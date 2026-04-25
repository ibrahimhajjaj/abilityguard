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
 * Rollback drift-check integration tests (v0.3 slice 4).
 *
 * All tests use real $wpdb + real WordPress functions.
 * No mocks of project services.
 */
final class RollbackDriftTest extends WP_UnitTestCase {

	/**
	 * Install tables before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	/**
	 * Create a snapshot and a matching audit log row.
	 *
	 * @param string               $inv_id  Invocation uuid.
	 * @param array<string, mixed> $spec    Snapshot spec (e.g. ['post_meta' => [...]]).
	 * @param string               $ability Ability name.
	 *
	 * @return array{ log_id: int, snapshot_id: int, pre_hash: string }
	 */
	private function create_snapshot_and_log( string $inv_id, array $spec, string $ability = 'test/drift', ?callable $mutate_during_invocation = null ): array {
		$service = new SnapshotService( new SnapshotStore() );
		$safety  = array( 'snapshot' => $spec );
		$snap    = $service->capture( $inv_id, $safety, null );

		// Simulate the ability's execute_callback running. Tests pass a
		// closure here that performs the "ability mutation". Anything done
		// AFTER capture_post counts as drift caused by a third party.
		if ( null !== $mutate_during_invocation ) {
			$mutate_during_invocation();
		}

		// Capture post-state - what the ability left behind.
		$service->capture_post( (int) $snap['snapshot_id'], $safety, null );

		$log_id = ( new AuditLogger() )->log(
			array(
				'invocation_id' => $inv_id,
				'ability_name'  => $ability,
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => true,
				'duration_ms'   => 1,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => $snap['snapshot_id'],
			)
		);

		return array(
			'log_id'      => $log_id,
			'snapshot_id' => (int) $snap['snapshot_id'],
			'pre_hash'    => $snap['pre_hash'],
		);
	}

	/**
	 * Write a log_meta row to enable skip_drift_check for a log row.
	 *
	 * @param int $log_id Log row id.
	 */
	private function set_skip_drift_check( int $log_id ): void {
		global $wpdb;
		$table = Installer::table( 'log_meta' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'log_id'     => $log_id,
				'meta_key'   => 'skip_drift_check',
				'meta_value' => '1',
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Build a fresh rollback service wired to real repos.
	 */
	private function rollback_service(): RollbackService {
		return new RollbackService( new LogRepository(), new SnapshotStore() );
	}

	/**
	 * Capture, mutate (but no third-party drift), rollback → success + state restored.
	 */
	public function test_no_drift_restores_successfully(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$this->create_snapshot_and_log(
			'drift-none-1',
			array( 'post_meta' => array( $post_id => array( '_price' ) ) ),
			'test/drift',
			static function () use ( $post_id ) {
				update_post_meta( $post_id, '_price', '20.00' );
			}
		);

		// No third-party mutation between capture_post and rollback → no drift.
		$result = $this->rollback_service()->rollback( 'drift-none-1' );

		$this->assertTrue( $result );
		$this->assertSame( '10.00', get_post_meta( $post_id, '_price', true ) );

		$row = ( new LogRepository() )->find_by_invocation_id( 'drift-none-1' );
		$this->assertSame( 'rolled_back', $row['status'] );
	}

	/**
	 * Drift present, force=false → WP_Error, no restore, log status unchanged.
	 */
	public function test_drift_detected_force_false_returns_error(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$this->create_snapshot_and_log(
			'drift-abort-1',
			array( 'post_meta' => array( $post_id => array( '_price' ) ) ),
			'test/drift',
			static function () use ( $post_id ) {
				update_post_meta( $post_id, '_price', '20.00' );
			}
		);

		// Third-party drift AFTER the ability ran.
		update_post_meta( $post_id, '_price', '99.99' );

		$result = $this->rollback_service()->rollback( 'drift-abort-1', false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_rollback_drift', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertContains( 'post_meta', $data['drifted_surfaces'] );
		$this->assertSame( array(), $data['restored_surfaces'] );

		// State must NOT have been restored.
		$this->assertSame( '99.99', get_post_meta( $post_id, '_price', true ) );

		// Log status must remain 'ok'.
		$row = ( new LogRepository() )->find_by_invocation_id( 'drift-abort-1' );
		$this->assertNotSame( 'rolled_back', $row['status'] );
	}

	/**
	 * Drift present, force=true → success + pre-state restored + drift action fires.
	 */
	public function test_drift_detected_force_true_restores_to_pre_state(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '10.00' );

		$this->create_snapshot_and_log(
			'drift-force-1',
			array( 'post_meta' => array( $post_id => array( '_price' ) ) ),
			'test/drift',
			static function () use ( $post_id ) {
				update_post_meta( $post_id, '_price', '20.00' );
			}
		);

		// Third-party drift after the ability ran.
		update_post_meta( $post_id, '_price', '99.99' );

		$drift_surfaces_seen = array();
		add_action(
			'abilityguard_rollback_drift',
			static function ( $log, $snapshot, $drifted ) use ( &$drift_surfaces_seen ): void {
				$drift_surfaces_seen = $drifted;
			},
			10,
			3
		);

		$result = $this->rollback_service()->rollback( 'drift-force-1', true );

		$this->assertTrue( $result );
		$this->assertSame( '10.00', get_post_meta( $post_id, '_price', true ) );

		$row = ( new LogRepository() )->find_by_invocation_id( 'drift-force-1' );
		$this->assertSame( 'rolled_back', $row['status'] );

		$this->assertContains( 'post_meta', $drift_surfaces_seen );
	}

	/**
	 * When skip_drift_check=true the check is bypassed; drift action still fires for observability.
	 */
	public function test_skip_drift_check_bypasses_check_and_fires_drift_action(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_price', '5.00' );

		$info = $this->create_snapshot_and_log(
			'drift-skip-1',
			array( 'post_meta' => array( $post_id => array( '_price' ) ) )
		);

		$this->set_skip_drift_check( $info['log_id'] );

		// Third-party drift.
		update_post_meta( $post_id, '_price', '77.77' );

		$drift_action_fired = false;
		add_action(
			'abilityguard_rollback_drift',
			static function () use ( &$drift_action_fired ): void {
				$drift_action_fired = true;
			},
			10,
			3
		);

		// No --force, but skip_drift_check is set → should succeed.
		$result = $this->rollback_service()->rollback( 'drift-skip-1', false );

		$this->assertTrue( $result );
		$this->assertSame( '5.00', get_post_meta( $post_id, '_price', true ) );
		$this->assertTrue( $drift_action_fired );
	}

	/**
	 * Multi-surface: only post_meta drifted. Error reports only that surface.
	 */
	public function test_multi_surface_only_drifted_surface_reported(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_weight', '1.5' );
		update_option( 'demo_multi_opt', 'stable' );

		$this->create_snapshot_and_log(
			'drift-multi-1',
			array(
				'post_meta' => array( $post_id => array( '_weight' ) ),
				'options'   => array( 'demo_multi_opt' ),
			)
		);

		// Only post_meta drifts.
		update_post_meta( $post_id, '_weight', '9.9' );

		$result = $this->rollback_service()->rollback( 'drift-multi-1', false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_rollback_drift', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertContains( 'post_meta', $data['drifted_surfaces'] );
		$this->assertNotContains( 'options', $data['drifted_surfaces'] );
	}

	/**
	 * Multi-surface: force=true restores both surfaces regardless of drift.
	 */
	public function test_multi_surface_force_true_restores_both(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_weight', '1.5' );
		update_option( 'demo_multi_opt2', 'stable' );

		$this->create_snapshot_and_log(
			'drift-multi-2',
			array(
				'post_meta' => array( $post_id => array( '_weight' ) ),
				'options'   => array( 'demo_multi_opt2' ),
			)
		);

		// Only post_meta drifts; option stays stable.
		update_post_meta( $post_id, '_weight', '9.9' );

		$result = $this->rollback_service()->rollback( 'drift-multi-2', true );

		$this->assertTrue( $result );
		$this->assertSame( '1.5', get_post_meta( $post_id, '_weight', true ) );
		$this->assertSame( 'stable', get_option( 'demo_multi_opt2' ) );
	}

	/**
	 * REST endpoint: POST /rollback/<id> without force on drifted state returns error.
	 */
	public function test_rest_rollback_without_force_returns_400_on_drift(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_rest_price', '1.00' );

		$info = $this->create_snapshot_and_log(
			'drift-rest-1',
			array( 'post_meta' => array( $post_id => array( '_rest_price' ) ) )
		);

		// Introduce drift.
		update_post_meta( $post_id, '_rest_price', '55.55' );

		$log_id  = $info['log_id'];
		$request = new \WP_REST_Request( 'POST', '/abilityguard/v1/rollback/' . $log_id );
		$request->set_param( 'id', $log_id );
		$request->set_param( 'force', false );

		$response = \AbilityGuard\Admin\RestController::do_rollback( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'abilityguard_rollback_drift', $response->get_error_code() );
	}

	/**
	 * REST endpoint: POST /rollback/<id>?force=1 on drifted state → 200 + restored.
	 */
	public function test_rest_rollback_with_force_returns_success_on_drift(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_rest_price2', '2.00' );

		$info = $this->create_snapshot_and_log(
			'drift-rest-2',
			array( 'post_meta' => array( $post_id => array( '_rest_price2' ) ) )
		);

		// Introduce drift.
		update_post_meta( $post_id, '_rest_price2', '66.66' );

		$log_id  = $info['log_id'];
		$request = new \WP_REST_Request( 'POST', '/abilityguard/v1/rollback/' . $log_id );
		$request->set_param( 'id', $log_id );
		$request->set_param( 'force', true );

		$response = \AbilityGuard\Admin\RestController::do_rollback( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2.00', get_post_meta( $post_id, '_rest_price2', true ) );
	}

	/**
	 * CLI path (via service): no --force → WP_Error on drift; state not restored.
	 */
	public function test_cli_rollback_without_force_fails_on_drift(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			$this->markTestSkipped( 'WP_CLI not loaded in this run' );
		}

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_cli_price', '3.00' );

		$info = $this->create_snapshot_and_log(
			'drift-cli-1',
			array( 'post_meta' => array( $post_id => array( '_cli_price' ) ) )
		);

		// Introduce drift.
		update_post_meta( $post_id, '_cli_price', '77.77' );

		$result = $this->rollback_service()->rollback( $info['log_id'], false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_rollback_drift', $result->get_error_code() );
		$this->assertSame( '77.77', get_post_meta( $post_id, '_cli_price', true ) );
	}

	/**
	 * CLI path (via service): --force → success on drift; state restored to pre-state.
	 */
	public function test_cli_rollback_with_force_succeeds_on_drift(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			$this->markTestSkipped( 'WP_CLI not loaded in this run' );
		}

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_cli_price2', '4.00' );

		$info = $this->create_snapshot_and_log(
			'drift-cli-2',
			array( 'post_meta' => array( $post_id => array( '_cli_price2' ) ) )
		);

		// Introduce drift.
		update_post_meta( $post_id, '_cli_price2', '88.88' );

		$result = $this->rollback_service()->rollback( $info['log_id'], true );

		$this->assertTrue( $result );
		$this->assertSame( '4.00', get_post_meta( $post_id, '_cli_price2', true ) );
	}
}
