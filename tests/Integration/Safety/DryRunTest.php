<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Safety;

use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Safety\DryRun;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for src/Safety/DryRun.php.
 *
 * Each test registers an ability via the abilities-api so the full wrap
 * (RegistrationFilter -> AbilityWrapper -> InvocationObserver -> DryRun
 * filter) is exercised end to end.
 */
final class DryRunTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_test_category();
	}

	private function ensure_test_category(): void {
		if ( wp_has_ability_category( 'abilityguard-tests' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'abilityguard-tests',
				array(
					'label'       => 'AbilityGuard tests',
					'description' => 'Category for AbilityGuard integration tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		$this->assertNotNull( $result );
		return $result;
	}

	public function test_dry_run_returns_raw_result_and_persists_diff(): void {
		update_option( 'dryrun_demo_threshold', 'before' );

		$ability_name = 'abilityguard-tests/dry-run-bump';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Bump option',
				'description'         => 'Changes an option; we expect dry-run to undo it',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				// Strict output_schema: response must match this. The whole
				// point of the new dry-run contract is that this still
				// validates even when dry-run is on.
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'changed' ),
					'properties' => array(
						'changed' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => static function () {
					update_option( 'dryrun_demo_threshold', 'after' );
					return array( 'changed' => true );
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'dry_run'  => true,
					'snapshot' => array(
						'options' => array( 'dryrun_demo_threshold' ),
					),
				),
			)
		);

		$ability  = wp_get_ability( $ability_name );
		$response = $ability->execute( array() );

		// Raw result, validates against output_schema.
		$this->assertSame( array( 'changed' => true ), $response );

		// Option is back to pre-state after auto-rollback.
		$this->assertSame( 'before', get_option( 'dryrun_demo_threshold' ) );

		// Audit row reflects rolled_back status + dry_run meta.
		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'rolled_back', $rows[0]['status'] );

		$log_id = (int) $rows[0]['id'];
		$this->assertSame( array( '1' ), LogMeta::get_all( $log_id, 'dry_run' ) );
		$this->assertSame( array( '1' ), LogMeta::get_all( $log_id, 'dry_run_rolled_back' ) );

		// Diff persisted as JSON.
		$diff_meta = LogMeta::get_all( $log_id, 'dry_run_diff' );
		$this->assertNotEmpty( $diff_meta );
		$diff = json_decode( $diff_meta[0], true );
		$this->assertIsArray( $diff );
		$diff_keys = array_column( $diff, 'key' );
		$this->assertContains( 'dryrun_demo_threshold', $diff_keys );

		// fetch_result helper returns the same data the REST endpoint does.
		$invocation_id = (string) $rows[0]['invocation_id'];
		$fetched       = DryRun::fetch_result( $invocation_id );
		$this->assertIsArray( $fetched );
		$this->assertSame( $invocation_id, $fetched['invocation_id'] );
		$this->assertTrue( $fetched['rolled_back'] );
		$this->assertContains( 'dryrun_demo_threshold', array_column( $fetched['diff'], 'key' ) );
	}

	public function test_dry_run_rest_endpoint_returns_diff(): void {
		update_option( 'dryrun_rest_opt', 'before' );

		$ability_name = 'abilityguard-tests/dry-run-rest';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Bump option',
				'description'         => 'Verifies REST endpoint surfaces the diff',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					update_option( 'dryrun_rest_opt', 'after' );
					return array( 'ok' => true );
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'dry_run'  => true,
					'snapshot' => array(
						'options' => array( 'dryrun_rest_opt' ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$ability->execute( array() );

		$repo          = new LogRepository();
		$rows          = $repo->list( array( 'ability_name' => $ability_name ) );
		$invocation_id = (string) $rows[0]['invocation_id'];

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/abilityguard/v1/dry-run/' . $invocation_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $invocation_id, $data['invocation_id'] );
		$this->assertTrue( $data['rolled_back'] );
		$this->assertContains( 'dryrun_rest_opt', array_column( $data['diff'], 'key' ) );
	}

	public function test_dry_run_rest_endpoint_404_when_invocation_unknown(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/abilityguard/v1/dry-run/no-such-invocation-id' );
		$response = rest_do_request( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_endpoint_409_when_not_a_dry_run(): void {
		// Run an ability WITHOUT dry-run, then ask the endpoint about it.
		$ability_name = 'abilityguard-tests/dry-run-rest-409';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Plain run',
				'description'         => 'No dry-run; endpoint should refuse to return a diff for it',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array(
					'snapshot'         => array( 'options' => array( 'dryrun_409_opt' ) ),
					'skip_drift_check' => true,
				),
			)
		);
		wp_get_ability( $ability_name )->execute( array() );

		$repo          = new LogRepository();
		$rows          = $repo->list( array( 'ability_name' => $ability_name ) );
		$invocation_id = (string) $rows[0]['invocation_id'];

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$request  = new WP_REST_Request( 'GET', '/abilityguard/v1/dry-run/' . $invocation_id );
		$response = rest_do_request( $request );
		$this->assertSame( 409, $response->get_status() );
	}

	public function test_dry_run_absent_leaves_behavior_unchanged(): void {
		update_option( 'dryrun_no_flag_opt', 'before' );

		$ability_name = 'abilityguard-tests/dry-run-off';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Bump without dry-run',
				'description'         => 'Same shape as the dry-run case but without the flag',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					update_option( 'dryrun_no_flag_opt', 'after' );
					return array( 'changed' => true );
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'snapshot' => array(
						'options' => array( 'dryrun_no_flag_opt' ),
					),
				),
			)
		);

		$ability  = wp_get_ability( $ability_name );
		$response = $ability->execute( array() );

		// Raw result, no envelope.
		$this->assertSame( array( 'changed' => true ), $response );

		// Mutation persisted (no rollback).
		$this->assertSame( 'after', get_option( 'dryrun_no_flag_opt' ) );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['status'] );
	}

	public function test_dry_run_without_snapshot_config_returns_raw_result_and_marks_not_rolled_back(): void {
		$ability_name = 'abilityguard-tests/dry-run-no-snapshot';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'No snapshot',
				'description'         => 'dry_run without snapshot config: nothing to roll back',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array(
					'dry_run'          => true,
					'skip_drift_check' => true,
				),
			)
		);

		$ability  = wp_get_ability( $ability_name );
		$response = $ability->execute( array() );

		// Raw result; nothing to reshape.
		$this->assertSame( array( 'ok' => true ), $response );

		$repo          = new LogRepository();
		$rows          = $repo->list( array( 'ability_name' => $ability_name ) );
		$invocation_id = (string) $rows[0]['invocation_id'];
		$log_id        = (int) $rows[0]['id'];

		// Caller can still tell it was a dry run, but rolled_back is false.
		$this->assertSame( array( '1' ), LogMeta::get_all( $log_id, 'dry_run' ) );
		$this->assertSame( array( '0' ), LogMeta::get_all( $log_id, 'dry_run_rolled_back' ) );

		$fetched = DryRun::fetch_result( $invocation_id );
		$this->assertIsArray( $fetched );
		$this->assertFalse( $fetched['rolled_back'] );
		$this->assertSame( array(), $fetched['diff'] );
	}

	public function test_rollback_failure_marks_audit_row_error_and_returns_wp_error(): void {
		update_option( 'dryrun_fail_opt', 'before' );

		$ability_name = 'abilityguard-tests/dry-run-rb-fail';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Rollback fails',
				'description'         => 'Force RollbackService to return WP_Error',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					update_option( 'dryrun_fail_opt', 'after' );
					return array( 'changed' => true );
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'dry_run'  => true,
					'snapshot' => array(
						'options' => array( 'dryrun_fail_opt' ),
					),
				),
			)
		);

		// Pre-empt the audit row to status='rolled_back' so RollbackService
		// returns abilityguard_already_rolled_back. That's the simplest
		// available WP_Error shape from the rollback path - it exercises the
		// failure handling without needing a custom collector.
		$ability_obj = wp_get_ability( $ability_name );

		// Wire a hook that flips the placeholder row to 'rolled_back' the
		// instant before-hook inserts it. The post-execute filter will then
		// see RollbackService refuse to run again.
		$flip = static function ( $invocation_id ) {
			$repo = new LogRepository();
			$row  = $repo->find_by_invocation_id( $invocation_id );
			if ( null !== $row ) {
				$repo->update_status( (int) $row['id'], 'rolled_back' );
			}
		};
		add_action( 'abilityguard_invocation_started', $flip );

		$response = $ability_obj->execute( array() );

		remove_action( 'abilityguard_invocation_started', $flip );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'abilityguard_already_rolled_back', $response->get_error_code() );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	protected function tearDown(): void {
		// Make sure the filter we keep registered globally doesn't leak
		// between tests in this class's lifecycle.
		DryRun::reset_for_tests();
		DryRun::register();
		parent::tearDown();
	}
}
