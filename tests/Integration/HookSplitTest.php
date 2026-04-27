<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use WP_Error;
use WP_UnitTestCase;

/**
 * Verifies the WP 6.9 before/after-hook split: observability lives in
 * InvocationObserver listeners; enforcement (the abilityguard_pre_execute_decision
 * filter and the error-path audit completion) stays in the wrap.
 */
final class HookSplitTest extends WP_UnitTestCase {

	use AbilityRegistrationTrait;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_test_category();
	}

	public function test_before_hook_listener_is_audit_source_of_truth(): void {
		$before_fired = 0;
		$after_fired  = 0;
		add_action(
			'wp_before_execute_ability',
			static function () use ( &$before_fired ): void {
				++$before_fired;
			},
			5
		);
		add_action(
			'wp_after_execute_ability',
			static function () use ( &$after_fired ): void {
				++$after_fired;
			},
			5
		);

		$ability_name = 'abilityguard-tests/hook-source';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Hook source',
				'description'         => 'Verifies observer owns the audit row',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn( $input ) => array( 'echo' => $input['echo'] ?? 'x' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'echo' => array( 'type' => 'string' ) ),
				),
				'safety'              => array( 'skip_drift_check' => true ),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'echo' => 'hi' ) );
		$this->assertSame( array( 'echo' => 'hi' ), $result );

		$this->assertSame( 1, $before_fired, 'wp_before_execute_ability should fire once' );
		$this->assertSame( 1, $after_fired, 'wp_after_execute_ability should fire once on success' );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows, 'exactly one audit row written by the hook split' );
		$this->assertSame( 'ok', $rows[0]['status'] );
		$this->assertNotNull( $rows[0]['result_json'] );
		$this->assertNotNull( $rows[0]['args_json'] );
	}

	public function test_error_path_completes_audit_when_after_hook_does_not_fire(): void {
		$after_fired = 0;
		add_action(
			'wp_after_execute_ability',
			static function () use ( &$after_fired ): void {
				++$after_fired;
			},
			5
		);

		$ability_name = 'abilityguard-tests/error-path';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Error path',
				'description'         => 'Returns WP_Error to skip the after-hook',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => new WP_Error( 'boom', 'inner failure' ),
				'input_schema'        => array( 'type' => 'object' ),
				'safety'              => array( 'skip_drift_check' => true ),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'boom', $result->get_error_code() );

		// Core skips wp_after_execute_ability when execute returns WP_Error.
		// The wrap has to finalize the audit row itself.
		$this->assertSame( 0, $after_fired );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_pre_execute_decision_filter_short_circuits_with_wp_error(): void {
		$ability_name = 'abilityguard-tests/pre-decision';
		$ran          = 0;
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Pre-decision',
				'description'         => 'Should not actually run',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( &$ran ) {
					++$ran;
					return 'should-not-be-returned';
				},
				'input_schema'        => array( 'type' => 'object' ),
				'safety'              => array( 'skip_drift_check' => true ),
			)
		);

		$decision_called = 0;
		add_filter(
			'abilityguard_pre_execute_decision',
			static function ( $current, $name ) use ( &$decision_called, $ability_name ) {
				if ( $name !== $ability_name ) {
					return $current;
				}
				++$decision_called;
				return new WP_Error( 'abilityguard_blocked', 'blocked by test', array( 'status' => 451 ) );
			},
			10,
			2
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilityguard_blocked', $result->get_error_code() );
		$this->assertSame( 1, $decision_called );
		$this->assertSame( 0, $ran, 'execute_callback must not run when filter short-circuits' );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );

		remove_all_filters( 'abilityguard_pre_execute_decision' );
	}
}
