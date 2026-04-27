<?php
/**
 * Redis-backed rate-limiter storage.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Storage impl for sites running a Redis drop-in (the wordpress/redis-cache
 * plugin is the canonical example). Identical bytecode to ObjectCacheStorage
 *, kept as a separate class so `is_atomic()` and the boot-time picker can
 * tell them apart, and so future Redis-only optimizations (Lua-script bulk
 * INCR for all policies in one round-trip, à la upstash/ratelimit-js) can
 * land here without touching the generic object-cache path.
 */
final class RedisStorage implements Storage {

	private const GROUP = 'abilityguard_rl';

	public function increment( string $key, int $ttl ): int {
		// wp_cache_add returns false if the key already exists; in that
		// case wp_cache_incr atomically bumps the existing value. This is
		// the standard INCR-with-TTL idiom (see Cloudflare's blog post
		// "Counting things, a lot of different things").
		if ( \wp_cache_add( $key, 1, self::GROUP, $ttl ) ) {
			return 1;
		}
		$next = \wp_cache_incr( $key, 1, self::GROUP );
		if ( false === $next ) {
			// Drop-in lost the key between add and incr (eviction, race).
			// Re-seed at 1, accepting the off-by-one rather than throwing.
			\wp_cache_set( $key, 1, self::GROUP, $ttl );
			return 1;
		}
		return (int) $next;
	}

	public function get( string $key ): int {
		$v = \wp_cache_get( $key, self::GROUP );
		return false === $v ? 0 : (int) $v;
	}

	public function delete( string $key ): void {
		\wp_cache_delete( $key, self::GROUP );
	}

	public function is_atomic(): bool {
		return true;
	}
}
