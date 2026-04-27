<?php
/**
 * Storage interface for the rate limiter.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Storage contract for sliding-window-counter buckets.
 *
 * Three production impls live alongside this interface:
 *
 *   - RedisStorage:        wp_cache_incr against a Redis drop-in (atomic).
 *   - ObjectCacheStorage:  wp_cache_incr against any external object cache
 *                          that honors the contract (Memcached, APCu).
 *   - TransientStorage:    get/set_transient fallback (NOT atomic).
 *
 * Sites can swap a custom impl via the `abilityguard_rate_limiter_storage`
 * filter, e.g. a Lua-script-backed Redis implementation borrowed from
 * upstash/ratelimit-js for stricter accounting.
 *
 * Contract: implementations MAY throw on backend failure. Callers MUST
 * fail-open on exception (Stripe pattern: a throwing limiter does not
 * block the request).
 */
interface Storage {

	/**
	 * Atomically increment the counter at $key. Returns the post-increment
	 * value. If the key did not exist, it is created with the value 1 and
	 * a TTL of $ttl seconds.
	 *
	 * @param string $key Bucket key.
	 * @param int    $ttl Time-to-live for the slot, in seconds.
	 *
	 * @return int Post-increment count.
	 */
	public function increment( string $key, int $ttl ): int;

	/**
	 * Read the raw count at $key. 0 when missing.
	 *
	 * @param string $key Bucket key.
	 */
	public function get( string $key ): int;

	/**
	 * Test helper, drop the value at $key.
	 *
	 * @param string $key Bucket key.
	 */
	public function delete( string $key ): void;

	/**
	 * Truth-in-advertising. RedisStorage / ObjectCacheStorage return true,
	 * TransientStorage returns false. Documented for ops, not consulted on
	 * the hot path.
	 */
	public function is_atomic(): bool;
}
