<?php
/**
 * WP 7.1 execution-lifecycle filter bridge.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Contracts\SnapshotServiceInterface;
use WP_Ability;
use WP_Error;
use WP_Filter_Sentinel;

/**
 * Bridges AbilityGuard onto the WP 7.1 execution lifecycle filters added in
 * WordPress/wordpress-develop#11731. Only registered when
 * AbilitiesApi::has_lifecycle_filters() detects the filters at boot.
 *
 * On 7.1+ this bridge owns two responsibilities the AbilityWrapper used to
 * carry. The wrap stays in place for older hosts: it still owns the
 * advisory lock, the abilityguard_pre_execute_decision filter, the actual
 * execute, and error-path audit completion.
 *
 *   - wp_pre_execute_ability: approval-blocking short-circuit. Lives here
 *     instead of inside the wrap because the short-circuit must happen
 *     BEFORE the wp_before_execute_ability observer runs, so the audit row
 *     is written as 'pending' once rather than 'ok' then patched.
 *
 *   - wp_ability_execute_result: post-snapshot capture + the
 *     abilityguard_post_execute_result extension filter. Re-fired from
 *     this seam on 7.1+ so DryRun / RateLimiter and any third-party
 *     post-execute subscribers keep working without depending on the wrap.
 */
final class CoreFilterBridge {

	/**
	 * Whether register() has run.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param InvocationObserver       $observer  Owns the pending-invocation prep helper.
	 * @param SnapshotServiceInterface $snapshots Used for the post-snapshot capture.
	 */
	public function __construct(
		private InvocationObserver $observer,
		private SnapshotServiceInterface $snapshots
	) {}

	/**
	 * Is the bridge wired? AbilityWrapper checks this so it can step out of
	 * the duplicate work on 7.1+ hosts.
	 */
	public static function is_registered(): bool {
		return self::$registered;
	}

	/**
	 * Wire the filter handlers. Idempotent.
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'wp_pre_execute_ability', array( $this, 'on_pre_execute' ), 10, 4 );
		add_filter( 'wp_ability_execute_result', array( $this, 'on_execute_result' ), 10, 4 );
	}

	/**
	 * Handles wp_pre_execute_ability.
	 *
	 * Returns $pre unchanged unless we're going to short-circuit the call
	 * with an approval-pending envelope.
	 *
	 * @param mixed      $pre          Sentinel default, or another plugin's short-circuit value.
	 * @param string     $ability_name Ability name.
	 * @param mixed      $input        Raw input passed to execute().
	 * @param WP_Ability $ability      Ability instance.
	 *
	 * @return mixed Original $pre, or WP_Error 202 envelope on short-circuit.
	 */
	public function on_pre_execute( $pre, string $ability_name, mixed $input, WP_Ability $ability ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $ability is part of the filter signature.
		// Sentinel test: core seeds $pre with a fresh WP_Filter_Sentinel per
		// call (see wordpress-develop#11731). Anything that is not a
		// WP_Filter_Sentinel means a higher-priority filter already
		// short-circuited; pass through and respect that.
		if ( ! ( $pre instanceof WP_Filter_Sentinel ) ) {
			return $pre;
		}

		if ( ApprovalService::is_approving() ) {
			return $pre;
		}

		$safety = InvocationObserver::safety_for( $ability_name );
		if ( null === $safety || empty( $safety['requires_approval'] ) ) {
			return $pre;
		}

		$prepared = $this->observer->open_pending_invocation_for_approval( $ability_name, $input );
		if ( array() === $prepared ) {
			return $pre;
		}

		$stages = array();
		if ( is_array( $safety['requires_approval'] )
			&& isset( $safety['requires_approval']['stages'] )
			&& is_array( $safety['requires_approval']['stages'] )
		) {
			$stages = $safety['requires_approval']['stages'];
		}

		$approval_service = new ApprovalService();
		$approval_id      = $approval_service->request(
			$ability_name,
			$input,
			(string) $prepared['invocation_id'],
			(int) $prepared['log_id'],
			$stages
		);

		return new WP_Error(
			'abilityguard_pending_approval',
			sprintf( 'Ability "%s" requires approval before execution.', $ability_name ),
			array(
				'status'      => 202,
				'approval_id' => $approval_id,
				'log_id'      => (int) $prepared['log_id'],
			)
		);
	}

	/**
	 * Handles wp_ability_execute_result.
	 *
	 * Captures the post-snapshot then re-fires
	 * abilityguard_post_execute_result with the observer's context, so
	 * third-party post-execute subscribers (DryRun, RateLimiter, custom
	 * plugins) keep firing on 7.1+ without depending on the wrap.
	 *
	 * @param mixed      $result       Result from the registered execute_callback.
	 * @param string     $ability_name Ability name.
	 * @param mixed      $input        Normalized input.
	 * @param WP_Ability $ability      Ability instance.
	 *
	 * @return mixed Possibly-transformed result from post_execute_result subscribers.
	 */
	public function on_execute_result( mixed $result, string $ability_name, mixed $input, WP_Ability $ability ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $ability is part of the filter signature.
		$ctx = InvocationContext::find_for( $ability_name );
		if ( null === $ctx ) {
			return $result;
		}

		// Capture post-snapshot BEFORE the filter so subscribers can
		// diff against live state (DryRun's auto-rollback depends on
		// this ordering).
		if ( null !== ( $ctx->snapshot['snapshot_id'] ?? null ) ) {
			$this->snapshots->capture_post( (int) $ctx->snapshot['snapshot_id'], $ctx->safety, $input );
		}

		return apply_filters(
			'abilityguard_post_execute_result',
			$result,
			$ability_name,
			$input,
			array(
				'invocation_id' => $ctx->invocation_id,
				'log_id'        => $ctx->log_id,
				'snapshot_id'   => (int) ( $ctx->snapshot['snapshot_id'] ?? 0 ),
				'caller_type'   => $ctx->caller_type,
				'caller_id'     => $ctx->caller_id,
				'destructive'   => (bool) ( $ctx->safety['destructive'] ?? false ),
				'safety'        => $ctx->safety,
				// duration_ms is not available at this seam: we're
				// inside WP_Ability::do_execute() and the wrap hasn't
				// returned yet. Subscribers that need timing should
				// read it from the abilityguard_invocation_completed
				// action (which fires after the full duration is known).
				'duration_ms'   => 0,
			)
		);
	}

	/**
	 * Test helper.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
	}
}
