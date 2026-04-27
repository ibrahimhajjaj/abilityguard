<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Installer;
use AbilityGuard\Retention\RetentionService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for RetentionService against real $wpdb.
 */
final class RetentionServiceTest extends WP_UnitTestCase {

	private AuditLogger $logger;
	private RetentionService $service;

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		$this->logger  = new AuditLogger();
		$this->service = new RetentionService();
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Insert a log row with created_at offset from NOW().
	 *
	 * @param string $invocation_id UUID.
	 * @param bool   $destructive   Whether the row is destructive.
	 * @param int    $days_ago      How many days in the past to backdate.
	 * @param string $status        Log status (ok|error|rolled_back|pending|rejected).
	 */
	private function insert_log( string $invocation_id, bool $destructive, int $days_ago, string $status = 'ok' ): void {
		global $wpdb;

		$this->logger->log(
			array(
				'invocation_id' => $invocation_id,
				'ability_name'  => 'test/prune',
				'caller_type'   => 'internal',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => $status,
				'destructive'   => $destructive,
				'duration_ms'   => 1,
				'pre_hash'      => str_repeat( 'a', 64 ),
				'post_hash'     => null,
				'snapshot_id'   => null,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $days_ago > 0 ) {
			$table = Installer::table( 'log' );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET created_at = NOW() - INTERVAL %d DAY WHERE invocation_id = %s",
					$days_ago,
					$invocation_id
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * Insert a snapshot row and return its id.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return int
	 */
	private function insert_snapshot( string $invocation_id ): int {
		$store = new SnapshotStore();
		$id    = $store->insert( $invocation_id, array( 'test' => true ), str_repeat( 'b', 64 ) );
		$this->assertNotNull( $id );
		return (int) $id;
	}

	/**
	 * Count rows in log table matching an invocation_id.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return int
	 */
	private function log_exists( string $invocation_id ): int {
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE invocation_id = %s", $invocation_id )
		);
		// phpcs:enable
		return $count;
	}

	/**
	 * Count rows in snapshots table matching an invocation_id.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return int
	 */
	private function snapshot_exists( string $invocation_id ): int {
		global $wpdb;
		$table = Installer::table( 'snapshots' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE invocation_id = %s", $invocation_id )
		);
		// phpcs:enable
		return $count;
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	/**
	 * Default thresholds (30 / 180):
	 * - old non-destructive (45d) → deleted
	 * - old destructive (45d)     → kept (45 < 180)
	 * - recent non-destructive    → kept
	 * - recent destructive        → kept
	 */
	public function test_prune_respects_default_retention(): void {
		$this->insert_log( 'old-normal', false, 45 );
		$this->insert_log( 'old-destructive', true, 45 );
		$this->insert_log( 'recent-normal', false, 5 );
		$this->insert_log( 'recent-destructive', true, 5 );

		$result = $this->service->prune();

		$this->assertSame( 1, $result['logs_deleted'] );
		$this->assertSame( 0, $this->log_exists( 'old-normal' ) );
		$this->assertSame( 1, $this->log_exists( 'old-destructive' ) );
		$this->assertSame( 1, $this->log_exists( 'recent-normal' ) );
		$this->assertSame( 1, $this->log_exists( 'recent-destructive' ) );
	}

	/**
	 * Overriding destructive threshold to 30 days causes the 45-day-old
	 * destructive row to be deleted as well.
	 */
	public function test_filter_override_prunes_destructive_rows(): void {
		$this->insert_log( 'old-normal-2', false, 45 );
		$this->insert_log( 'old-destructive-2', true, 45 );

		add_filter( 'abilityguard_retention_days_destructive', fn() => 30 );
		$result = $this->service->prune();
		remove_all_filters( 'abilityguard_retention_days_destructive' );

		$this->assertSame( 2, $result['logs_deleted'] );
		$this->assertSame( 0, $this->log_exists( 'old-normal-2' ) );
		$this->assertSame( 0, $this->log_exists( 'old-destructive-2' ) );
	}

	/**
	 * When the normal retention days filter returns 0, no normal rows are
	 * deleted regardless of age.
	 */
	public function test_filter_zero_skips_normal_prune(): void {
		$this->insert_log( 'old-normal-3', false, 90 );

		add_filter( 'abilityguard_retention_days_normal', fn() => 0 );
		$result = $this->service->prune();
		remove_all_filters( 'abilityguard_retention_days_normal' );

		$this->assertSame( 0, $result['logs_deleted'] );
		$this->assertSame( 1, $this->log_exists( 'old-normal-3' ) );
	}

	/**
	 * A snapshot whose invocation_id has no matching log row is deleted.
	 */
	public function test_orphan_snapshot_is_deleted(): void {
		// Insert snapshot without a matching log row.
		$this->insert_snapshot( 'orphan-snap' );

		$result = $this->service->prune();

		$this->assertSame( 1, $result['snapshots_deleted'] );
		$this->assertSame( 0, $this->snapshot_exists( 'orphan-snap' ) );
	}

	/**
	 * Per-status retention: pending older than 7d goes; ok at 30d (between 7
	 * and 90) stays; error at 100d (between 90 and 180) stays.
	 */
	public function test_per_status_thresholds(): void {
		$this->insert_log( 'pending-old', false, 14, 'pending' );
		$this->insert_log( 'pending-young', false, 3, 'pending' );
		$this->insert_log( 'ok-mid', false, 30, 'ok' );
		$this->insert_log( 'ok-stale', false, 120, 'ok' );
		$this->insert_log( 'error-mid', false, 100, 'error' );
		$this->insert_log( 'error-stale', false, 200, 'error' );

		add_filter(
			'abilityguard_retention_days_by_status',
			fn() => array(
				'pending' => 7,
				'ok'      => 90,
				'error'   => 180,
			)
		);
		$result = $this->service->prune();
		remove_all_filters( 'abilityguard_retention_days_by_status' );

		$this->assertSame( 3, $result['logs_deleted'] );
		$this->assertSame( 0, $this->log_exists( 'pending-old' ) );
		$this->assertSame( 1, $this->log_exists( 'pending-young' ) );
		$this->assertSame( 1, $this->log_exists( 'ok-mid' ) );
		$this->assertSame( 0, $this->log_exists( 'ok-stale' ) );
		$this->assertSame( 1, $this->log_exists( 'error-mid' ) );
		$this->assertSame( 0, $this->log_exists( 'error-stale' ) );
	}

	/**
	 * A snapshot tied to a surviving log row is NOT deleted.
	 */
	public function test_snapshot_tied_to_surviving_log_is_kept(): void {
		// Insert a recent log row (will survive prune) and its snapshot.
		$this->insert_log( 'recent-with-snap', false, 5 );
		$this->insert_snapshot( 'recent-with-snap' );

		$result = $this->service->prune();

		$this->assertSame( 0, $result['snapshots_deleted'] );
		$this->assertSame( 1, $this->snapshot_exists( 'recent-with-snap' ) );
	}
}
