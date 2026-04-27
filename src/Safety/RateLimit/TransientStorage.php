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

	public function increment( string $key, int $ttl ): int {
		$current = (int) \get_transient( $key );
		$next    = $current + 1;
		\set_transient( $key, $next, $ttl );
		return $next;
	}

	public function get( string $key ): int {
		$v = \get_transient( $key );
		return false === $v ? 0 : (int) $v;
	}

	public function delete( string $key ): void {
		\delete_transient( $key );
	}

	public function is_atomic(): bool {
		return false;
	}
}
