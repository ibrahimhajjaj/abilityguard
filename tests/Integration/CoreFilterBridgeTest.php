<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Compat\AbilitiesApi;
use AbilityGuard\Installer;
use AbilityGuard\Registry\CoreFilterBridge;
use WP_Error;
use WP_UnitTestCase;

/**
 * Exercises the WP 7.1 execution-lifecycle bridge:
 *
 *   - wp_pre_execute_ability for approval-blocking
 *   - wp_ability_execute_result for re-firing abilityguard_post_execute_result
 *
 * Each test markTestSkipped's when the loaded WP_Ability lacks the filters
 * (i.e. on hosts that haven't applied wordpress-develop#11731). The
 * existing ApprovalFlowTest + DryRun tests cover the wrap fallback path
 * for older hosts.
 */
final class CoreFilterBridgeTest extends WP_UnitTestCase {

	use AbilityRegistrationTrait;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		if ( ! AbilitiesApi::has_lifecycle_filters() ) {
			$this->markTestSkipped( 'WP_Ability lacks WP 7.1 execution lifecycle filters (PR #11731 not applied).' );
		}
		if ( ! CoreFilterBridge::is_registered() ) {
			$this->markTestSkipped( 'CoreFilterBridge not wired; Plugin::boot must run first.' );
		}
		Installer::install();
		\AbilityGuard\Approval\CapabilityManager::grant_to_administrators();
		$this->ensure_test_category();
	}

	/**
	 * Approval-blocking short-circuits via wp_pre_execute_ability:
	 *   - callback never runs
	 *   - the WP_Error 202 envelope matches the wrap path's shape
	 *   - the audit row is written as 'pending' directly
	 *   - wp_before_execute_ability does NOT fire (proves the pipeline
	 *     short-circuited inside the pre_execute filter, not later)
	 */
	public function test_pre_execute_short_circuits_for_approval(): void {
		$before_fired = 0;
		add_action(
			'wp_before_execute_ability',
			static function () use ( &$before_fired ): void {
				++$before_fired;
			},
			5
		);

		$ability_name = 'abilityguard-tests/bridge-approval';
		$ran          = 0;
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Bridge approval',
				'description'         => 'Should be parked by the pre-execute filter.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( &$ran ) {
					++$ran;
					return array( 'should-not' => 'run' );
				},
				'input_schema'        => array( 'type' => 'object' ),
				'safety'              => array(
					'requires_approval' => true,
					'skip_drift_check'  => true,
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilityguard_pending_approval', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 202, $data['status'] );
		$this->assertGreaterThan( 0, $data['approval_id'] );
		$this->assertGreaterThan( 0, $data['log_id'] );

		$this->assertSame( 0, $ran, 'callback must not run when pre-execute short-circuits' );
		$this->assertSame(
			0,
			$before_fired,
			'wp_before_execute_ability must not fire; the pre-execute filter aborts the whole pipeline'
		);

		$logs = ( new LogRepository() )->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $logs, 'exactly one pending audit row should exist' );
		$this->assertSame( 'pending', $logs[0]['status'] );

		$approval = ( new ApprovalRepository() )->find( (int) $data['approval_id'] );
		$this->assertNotNull( $approval );
		$this->assertSame( 'pending', $approval['status'] );
		$this->assertSame( $ability_name, $approval['ability_name'] );
	}

	/**
	 * wp_ability_execute_result fires abilityguard_post_execute_result, with
	 * the wrap stepping out of its own dispatch (no double-fire).
	 */
	public function test_execute_result_filter_re_fires_post_execute_result_exactly_once(): void {
		$post_fired      = 0;
		$post_invocation = null;
		$probe           = static function ( $result, $name, $input, $context ) use ( &$post_fired, &$post_invocation ) {
			if ( 'abilityguard-tests/bridge-result' !== $name ) {
				return $result;
			}
			++$post_fired;
			$post_invocation = $context['invocation_id'] ?? null;
			return $result;
		};
		add_filter( 'abilityguard_post_execute_result', $probe, 10, 4 );

		$this->register_via_init(
			'abilityguard-tests/bridge-result',
			static fn() => array(
				'label'               => 'Bridge result',
				'description'         => 'Exercises the result filter seam',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'input_schema'        => array( 'type' => 'object' ),
				'safety'              => array( 'skip_drift_check' => true ),
			)
		);

		$ability = wp_get_ability( 'abilityguard-tests/bridge-result' );
		$result  = $ability->execute( array() );

		remove_filter( 'abilityguard_post_execute_result', $probe, 10 );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame(
			1,
			$post_fired,
			'abilityguard_post_execute_result must fire exactly once on 7.1+ (bridge owns dispatch; wrap is gated off)'
		);
		$this->assertNotNull( $post_invocation, 'context.invocation_id should be threaded through' );
	}

	/**
	 * Pre-execute filter respects another plugin's short-circuit: if a
	 * higher-priority subscriber returns a non-stdClass value, the bridge
	 * passes it through untouched even when requires_approval is set.
	 */
	public function test_pre_execute_respects_prior_short_circuit(): void {
		$ability_name = 'abilityguard-tests/bridge-prior';
		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Bridge prior',
				'description'         => 'Another plugin claims the short-circuit slot',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'never',
				'input_schema'        => array( 'type' => 'object' ),
				'safety'              => array(
					'requires_approval' => true,
					'skip_drift_check'  => true,
				),
			)
		);

		$prior = static fn() => 'prior-plugin-result';
		add_filter( 'wp_pre_execute_ability', $prior, 5, 4 );

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array() );

		remove_filter( 'wp_pre_execute_ability', $prior, 5 );

		$this->assertSame(
			'prior-plugin-result',
			$result,
			'Bridge must not override a non-sentinel value set by a higher-priority filter'
		);
	}
}
