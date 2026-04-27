<?php
/**
 * Per-user, per-ability rate limiter. Per-call opt-in via
 * safety.max_calls_per_hour.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Cheap rolling-window rate limiter for ability invocations.
 *
 * Plugs into the abilityguard_pre_execute_decision filter (the same seam
 * DryRun uses for post-execute work). When safety.max_calls_per_hour is set
 * for an ability, we maintain a transient-backed counter keyed by
 * (user_id, ability_name). Once the counter reaches the limit we short-
 * circuit the wrap with a 429 WP_Error before execute_callback runs, so the
 * audit row finalizes as 'error' without the side effects.
 *
 * Config shape (chose flat over nested for parity with safety.dry_run):
 *
 *     'safety' => array(
 *         'max_calls_per_hour' => 60,
 *     ),
 *
 * Window length is 3600s by default and tunable per call via:
 *
 *     apply_filters( 'abilityguard_rate_limit_window_seconds', 3600,
 *                    $ability_name, $user_id );
 *
 * Bucket semantics: each (user_id, ability) pair gets its own counter.
 * Unauthenticated callers (user_id = 0) share a single bucket per ability;
 * IP-bucketing can be added later if abuse becomes a real signal.
 *
 * Race-condition note: WP transients are not atomic across concurrent
 * requests. Two simultaneous calls can both read N-1 and both write N,
 * letting one slip past the cap. For ops-quota-control on AI agents this
 * is acceptable: the goal is to stop runaway loops, not enforce a hard
 * SLA. Sites that need stricter accounting can swap the storage by
 * registering their own callback at higher priority on
 * abilityguard_pre_execute_decision.
 */
final class RateLimiter {

	/**
	 * Default rolling window in seconds.
	 */
	private const DEFAULT_WINDOW = 3600;

	/**
	 * Boot guard.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Wire the pre-execute filter. Idempotent.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'abilityguard_pre_execute_decision', array( self::class, 'maybe_block' ), 10, 4 );
	}

	/**
	 * Test helper.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
		remove_filter( 'abilityguard_pre_execute_decision', array( self::class, 'maybe_block' ), 10 );
	}

	/**
	 * Filter callback: block when the per-user/per-ability quota is exhausted.
	 *
	 * @param mixed                $decision     Existing decision (null = proceed, WP_Error = blocked).
	 * @param string               $ability_name Ability name.
	 * @param mixed                $input        Input (unused).
	 * @param array<string, mixed> $context      invocation_id, caller_type, caller_id, destructive, safety.
	 *
	 * @return mixed
	 */
	public static function maybe_block( mixed $decision, string $ability_name, mixed $input, array $context ): mixed {
		// Earlier callbacks already blocked: respect their decision.
		if ( $decision instanceof WP_Error ) {
			return $decision;
		}

		$safety = is_array( $context['safety'] ?? null ) ? $context['safety'] : array();
		$limit  = isset( $safety['max_calls_per_hour'] ) ? (int) $safety['max_calls_per_hour'] : 0;

		// No config or non-positive limit: no-op.
		if ( $limit <= 0 ) {
			return $decision;
		}

		$user_id = (int) get_current_user_id();
		$window  = self::resolve_window( $ability_name, $user_id );
		$key     = self::transient_key( $user_id, $ability_name );

		$current = (int) get_transient( $key );

		if ( $current >= $limit ) {
			$retry_after = self::estimate_retry_after( $key, $window );
			return new WP_Error(
				'abilityguard_rate_limited',
				sprintf(
					'Rate limit exceeded for ability "%s": %d calls per %d seconds.',
					$ability_name,
					$limit,
					$window
				),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
					'limit'       => $limit,
					'window'      => $window,
				)
			);
		}

		// Increment + (re)set the transient. set_transient resets the TTL on
		// the first hit of the window; subsequent hits within the same window
		// keep the original expiry because we read it back via the key alone.
		// Tradeoff: we use a sliding-on-first-call window. Good enough for
		// quota control, simpler than implementing fixed wall-clock buckets.
		set_transient( $key, $current + 1, $window );

		return $decision;
	}

	/**
	 * Build a transient key under the option_name 191-char limit.
	 *
	 * The literal ability name can include slashes and arbitrary chars so
	 * we hash it. Keeps keys short and avoids surprises with collations.
	 *
	 * @param int    $user_id      User id (0 for anon).
	 * @param string $ability_name Ability name.
	 */
	private static function transient_key( int $user_id, string $ability_name ): string {
		return 'abilityguard_rl_' . $user_id . '_' . substr( md5( $ability_name ), 0, 16 );
	}

	/**
	 * Resolve the rolling-window length for this call.
	 *
	 * @param string $ability_name Ability name.
	 * @param int    $user_id      User id.
	 */
	private static function resolve_window( string $ability_name, int $user_id ): int {
		/**
		 * Filter the rate-limit rolling window in seconds.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $seconds      Default 3600.
		 * @param string $ability_name Ability name.
		 * @param int    $user_id      User id (0 for anon).
		 */
		$seconds = (int) apply_filters(
			'abilityguard_rate_limit_window_seconds',
			self::DEFAULT_WINDOW,
			$ability_name,
			$user_id
		);
		return $seconds > 0 ? $seconds : self::DEFAULT_WINDOW;
	}

	/**
	 * Best-effort retry-after estimate. WP transients store a separate
	 * _transient_timeout_<key> option whose value is the absolute expiry
	 * timestamp; we read it directly to give callers a real number rather
	 * than the full window length. Falls back to the window if the timeout
	 * row is missing (e.g. external object cache).
	 *
	 * @param string $key    Transient key (without the _transient_ prefix).
	 * @param int    $window Window in seconds.
	 */
	private static function estimate_retry_after( string $key, int $window ): int {
		$expiry = (int) get_option( '_transient_timeout_' . $key, 0 );
		if ( $expiry > 0 ) {
			$delta = $expiry - time();
			return $delta > 0 ? $delta : 0;
		}
		return $window;
	}
}
