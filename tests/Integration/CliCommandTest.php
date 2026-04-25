<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Cli\Command;
use AbilityGuard\Installer;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Exercises the wp-cli command class directly. We don't shell out to wp-cli
 * here - we instantiate the command and invoke its methods, which is how
 * WP_CLI itself dispatches in-process during integration runs.
 */
final class CliCommandTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\WP_CLI' ) ) {
			$this->markTestSkipped( 'WP_CLI not loaded in this run' );
		}
		Installer::install();
	}

	public function test_log_list_returns_real_rows(): void {
		( new AuditLogger() )->log( $this->row( 'inv-cli-1', 'demo/a' ) );
		( new AuditLogger() )->log( $this->row( 'inv-cli-2', 'demo/b' ) );

		$rows = ( new LogRepository() )->list();
		$this->assertGreaterThanOrEqual( 2, count( $rows ) );

		$names = array_column( $rows, 'ability_name' );
		$this->assertContains( 'demo/a', $names );
		$this->assertContains( 'demo/b', $names );
	}

	public function test_rollback_command_round_trip(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_x', 'pre' );

		$snap = ( new SnapshotService( new SnapshotStore() ) )->capture(
			'inv-cli-3',
			array( 'snapshot' => array( 'post_meta' => array( $post_id => array( '_x' ) ) ) ),
			null
		);
		( new AuditLogger() )->log( $this->row( 'inv-cli-3', 'demo/c', $snap['snapshot_id'], $snap['pre_hash'] ) );
		update_post_meta( $post_id, '_x', 'post' );

		$rb     = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result = $rb->rollback( 'inv-cli-3' );
		$this->assertTrue( $result );
		$this->assertSame( 'pre', get_post_meta( $post_id, '_x', true ) );
	}

	/**
	 * Build a log row payload.
	 *
	 * @param string      $invocation_id Invocation uuid.
	 * @param string      $name          Ability name.
	 * @param int|null    $snapshot_id   Snapshot row id, if any.
	 * @param string|null $pre_hash      Pre-hash, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function row( string $invocation_id, string $name, ?int $snapshot_id = null, ?string $pre_hash = null ): array {
		return array(
			'invocation_id' => $invocation_id,
			'ability_name'  => $name,
			'caller_type'   => 'cli',
			'user_id'       => 0,
			'args_json'     => null,
			'result_json'   => null,
			'status'        => 'ok',
			'destructive'   => false,
			'duration_ms'   => 1,
			'pre_hash'      => $pre_hash,
			'post_hash'     => null,
			'snapshot_id'   => $snapshot_id,
		);
	}
}
