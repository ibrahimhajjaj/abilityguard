<?php
/**
 * Transient-backed rate-limiter storage.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Fallback storage. WP transients are NOT atomic across concurrent
 * requests, two simultaneous calls can both read N and both write N+1,
 * letting one slip past the cap. Documented and accepted: the goal is to
 * stop runaway agent loops, not enforce a hard SLA. Sites that need
 * stricter accounting should drop in a Redis or Lua-script-backed impl
 * via `abilityguard_rate_limiter_storage`.
 */
final class TransientStorage implements Storage {

	/**
	 * Read-modify-write increment via transients. NOT atomic.
	 *
	 * @param string $key Bucket key.
	 * @param int    $ttl Bucket TTL in seconds.
	 *
	 * @return int Counter value after increment.
	 */
	public function increment( string $key, int $ttl ): int {
		$current = (int) \get_transient( $key );
		$next    = $current + 1;
		\set_transient( $key, $next, $ttl );
		return $next;
	}

	/**
	 * Read the current counter value, 0 when missing.
	 *
	 * @param string $key Bucket key.
	 */
	public function get( string $key ): int {
		$v = \get_transient( $key );
		return false === $v ? 0 : (int) $v;
	}

	/**
	 * Delete a counter (used by tests and admin reset paths).
	 *
	 * @param string $key Bucket key.
	 */
	public function delete( string $key ): void {
		\delete_transient( $key );
	}

	/**
	 * Whether `increment` is atomic across concurrent requests.
	 */
	public function is_atomic(): bool {
		return false;
	}
}
