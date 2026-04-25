<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Rest;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for POST /abilityguard/v1/rollback/<id>.
 */
final class RollbackEndpointTest extends WP_UnitTestCase {

	use RestHelpers;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_rest_helpers();
	}

	private function route( int $id ): string {
		return '/abilityguard/v1/rollback/' . $id;
	}

	/**
	 * Build a log row linked to a snapshot that has both pre-state and post-state captured.
	 *
	 * @param string $inv_id  Unique invocation uuid.
	 * @param int    $post_id WordPress post id.
	 * @param string $before  Meta value before the ability ran (pre-state).
	 * @param string $after   Meta value after the ability ran (post-state).
	 *
	 * @return int Log row id.
	 */
	private function create_full_fixture( string $inv_id, int $post_id, string $before, string $after ): int {
		update_post_meta( $post_id, '_price', $before );

		$service = new SnapshotService( new SnapshotStore() );
		$safety  = array( 'snapshot' => array( 'post_meta' => array( $post_id => array( '_price' ) ) ) );
		$snap    = $service->capture( $inv_id, $safety, null );

		// Simulate the ability running - establishes post-state baseline.
		update_post_meta( $post_id, '_price', $after );
		$service->capture_post( (int) $snap['snapshot_id'], $safety, null );

		global $wpdb;
		$log_table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$log_table,
			array(
				'invocation_id' => $inv_id,
				'ability_name'  => 'test/rollback-ep',
				'caller_type'   => 'cli',
				'caller_id'     => null,
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => 1,
				'duration_ms'   => 1,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => (int) $snap['snapshot_id'],
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function test_non_admin_receives_403(): void {
		$log_id = $this->seed_log_row();
		wp_set_current_user( $this->subscriber_user_id );
		$response = $this->dispatch( 'POST', $this->route( $log_id ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_no_force_drift_detected_returns_400_drift_error(): void {
		$post_id = (int) self::factory()->post->create();
		$log_id  = $this->create_full_fixture( wp_generate_uuid4(), $post_id, '10.00', '20.00' );

		// Introduce drift AFTER the ability ran.
		update_post_meta( $post_id, '_price', '55.55' );

		$response = $this->as_admin(
			fn() => $this->dispatch(
				'POST',
				$this->route( $log_id ),
				array( 'force' => false )
			)
		);
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'abilityguard_rollback_drift', $data['code'] );

		// State must not have been restored.
		$this->assertSame( '55.55', get_post_meta( $post_id, '_price', true ) );
	}

	public function test_admin_already_rolled_back_returns_409(): void {
		$log_id = $this->seed_log_row(
			array(
				'invocation_id' => wp_generate_uuid4(),
				'status'        => 'rolled_back',
				'snapshot_id'   => null,
			)
		);

		$response = $this->as_admin(
			fn() => $this->dispatch( 'POST', $this->route( $log_id ) )
		);
		$this->assertSame( 409, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'abilityguard_already_rolled_back', $data['code'] );
	}

	public function test_admin_snapshot_missing_returns_400(): void {
		$log_id = $this->seed_log_row(
			array(
				'snapshot_id' => null,
				'status'      => 'ok',
			)
		);

		$response = $this->as_admin(
			fn() => $this->dispatch( 'POST', $this->route( $log_id ) )
		);
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'abilityguard_snapshot_missing', $data['code'] );
	}

	public function test_admin_force_true_returns_200_and_restores_state(): void {
		$post_id = (int) self::factory()->post->create();
		$inv_id  = wp_generate_uuid4();
		$log_id  = $this->create_full_fixture( $inv_id, $post_id, '10.00', '20.00' );

		// Introduce drift so force is meaningful.
		update_post_meta( $post_id, '_price', '99.99' );

		$response = $this->as_admin(
			fn() => $this->dispatch(
				'POST',
				$this->route( $log_id ),
				array( 'force' => true )
			)
		);
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'ok', $data );
		$this->assertTrue( $data['ok'] );

		// Pre-state ('10.00') should be restored.
		$this->assertSame( '10.00', get_post_meta( $post_id, '_price', true ) );

		// Log status must be rolled_back.
		$repo = new LogRepository();
		$row  = $repo->find( $log_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'rolled_back', $row['status'] );
	}
}
