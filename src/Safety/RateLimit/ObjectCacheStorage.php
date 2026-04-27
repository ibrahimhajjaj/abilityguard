<?php
/**
 * Object-cache-backed rate-limiter storage.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Storage impl for any external object cache drop-in that honors atomic
 * `wp_cache_incr` (Memcached, APCu, and well-behaved custom drop-ins).
 *
 * Functionally identical to RedisStorage; the split exists so we can
 * advertise the backend and so Redis-specific optimizations stay isolated.
 */
final class ObjectCacheStorage implements Storage {

	private const GROUP = 'abilityguard_rl';

	/**
	 * Atomically increment the counter under `$key`, seeding to 1 with TTL.
	 *
	 * @param string $key Bucket key.
	 * @param int    $ttl Bucket TTL in seconds.
	 *
	 * @return int Counter value after increment.
	 */
	public function increment( string $key, int $ttl ): int {
		if ( \wp_cache_add( $key, 1, self::GROUP, $ttl ) ) {
			return 1;
		}
		$next = \wp_cache_incr( $key, 1, self::GROUP );
		if ( false === $next ) {
			\wp_cache_set( $key, 1, self::GROUP, $ttl );
			return 1;
		}
		return (int) $next;
	}

	/**
	 * Read the current counter value, 0 when missing.
	 *
	 * @param string $key Bucket key.
	 */
	public function get( string $key ): int {
		$v = \wp_cache_get( $key, self::GROUP );
		return false === $v ? 0 : (int) $v;
	}

	/**
	 * Delete a counter (used by tests and admin reset paths).
	 *
	 * @param string $key Bucket key.
	 */
	public function delete( string $key ): void {
		\wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Whether `increment` is atomic across concurrent requests.
	 */
	public function is_atomic(): bool {
		return true;
	}
}
