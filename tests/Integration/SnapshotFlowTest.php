<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * End-to-end through the real services + real $wpdb.
 */
final class SnapshotFlowTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	public function test_capture_writes_snapshot_row_with_compressed_payload(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '9.99' );

		$service = new SnapshotService( new SnapshotStore() );
		$result  = $service->capture(
			'inv-cap-1',
			array(
				'snapshot' => array(
					'post_meta' => array( $post_id => array( '_price' ) ),
				),
			),
			null
		);

		$this->assertNotNull( $result['snapshot_id'] );
		$this->assertSame( 64, strlen( $result['pre_hash'] ) );

		$store    = new SnapshotStore();
		$reloaded = $store->find_by_invocation_id( 'inv-cap-1' );
		$this->assertNotNull( $reloaded );
		$this->assertSame( '9.99', $reloaded['surfaces']['post_meta'][ $post_id ]['_price'] );
	}

	public function test_audit_logger_writes_then_repository_reads(): void {
		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-cap-2',
				'ability_name'  => 'demo/x',
				'caller_type'   => 'cli',
				'user_id'       => 1,
				'args_json'     => '{"a":1}',
				'result_json'   => '{"ok":true}',
				'status'        => 'ok',
				'destructive'   => true,
				'duration_ms'   => 7,
				'pre_hash'      => str_repeat( 'a', 64 ),
				'post_hash'     => str_repeat( 'b', 64 ),
				'snapshot_id'   => null,
			)
		);

		$repo = new LogRepository();
		$row  = $repo->find_by_invocation_id( 'inv-cap-2' );
		$this->assertNotNull( $row );
		$this->assertSame( 'demo/x', $row['ability_name'] );
		$this->assertSame( 'cli', $row['caller_type'] );
		$this->assertSame( '1', (string) $row['destructive'] );

		$listed = $repo->list( array( 'ability_name' => 'demo/x' ) );
		$this->assertCount( 1, $listed );
	}

	public function test_full_rollback_restores_post_meta_and_options(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );
		update_option( 'demo_threshold', 'before' );

		$service = new SnapshotService( new SnapshotStore() );
		$snap    = $service->capture(
			'inv-cap-3',
			array(
				'snapshot' => array(
					'post_meta' => array( $post_id => array( '_price' ) ),
					'options'   => array( 'demo_threshold' ),
				),
			),
			null
		);

		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-cap-3',
				'ability_name'  => 'demo/rollback',
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => true,
				'duration_ms'   => 3,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => $snap['snapshot_id'],
			)
		);

		// Mutate post-invocation state.
		update_post_meta( $post_id, '_price', '99.99' );
		update_option( 'demo_threshold', 'after' );

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result   = $rollback->rollback( 'inv-cap-3' );

		$this->assertTrue( $result );
		$this->assertSame( '10.00', get_post_meta( $post_id, '_price', true ) );
		$this->assertSame( 'before', get_option( 'demo_threshold' ) );

		$repo = new LogRepository();
		$row  = $repo->find_by_invocation_id( 'inv-cap-3' );
		$this->assertSame( 'rolled_back', $row['status'] );
	}

	public function test_rollback_refuses_to_run_twice(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_x', 'a' );

		$service = new SnapshotService( new SnapshotStore() );
		$snap    = $service->capture(
			'inv-cap-4',
			array( 'snapshot' => array( 'post_meta' => array( $post_id => array( '_x' ) ) ) ),
			null
		);
		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-cap-4',
				'ability_name'  => 'demo/x',
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => false,
				'duration_ms'   => 0,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => $snap['snapshot_id'],
			)
		);

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$this->assertTrue( $rollback->rollback( 'inv-cap-4' ) );

		$second = $rollback->rollback( 'inv-cap-4' );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'abilityguard_already_rolled_back', $second->get_error_code() );
	}
}
