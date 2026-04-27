<?php
/**
 * Multi-policy sliding-window rate limiter with IETF RateLimit headers.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Safety\RateLimit\ObjectCacheStorage;
use AbilityGuard\Safety\RateLimit\Policy;
use AbilityGuard\Safety\RateLimit\RedisStorage;
use AbilityGuard\Safety\RateLimit\Storage;
use AbilityGuard\Safety\RateLimit\TransientStorage;
use AbilityGuard\Safety\RateLimit\Window;
use Throwable;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Multi-policy sliding-window-counter rate limiter.
 *
 * Algorithm: Cloudflare/Upstash sliding-window-counter, evaluated once per
 * declared policy. The request is admitted iff every policy admits it.
 * Estimated rate per policy = prev_count * ((W - elapsed)/W) + curr_count.
 *
 * Storage: an interface with three implementations, picked at boot time:
 *
 *   1. RedisStorage      , wp_cache_incr against a Redis drop-in.
 *   2. ObjectCacheStorage, wp_cache_incr against any external object cache
 *                           that honors the wp_cache_* atomic contract.
 *   3. TransientStorage  , get/set_transient fallback. NOT atomic; off-by-N
 *                           on concurrent calls is documented and accepted.
 *
 * Sites can swap a custom impl via the `abilityguard_rate_limiter_storage`
 * filter.
 *
 * Headers: IETF draft-ietf-httpapi-ratelimit-headers-10 only. We emit
 * `RateLimit-Policy` and `RateLimit` on every dispatch of an AbilityGuard
 * route, plus `Retry-After` (RFC 7231) on 429. We deliberately do NOT
 * emit vendor `X-RateLimit-*`, the IETF draft handles multi-policy
 * natively, the legacy convention does not.
 *
 * Principal resolution, in order:
 *
 *   1. Authenticated WP user → `u:{user_id}`.
 *   2. App-pw / OAuth client (via `$context['caller_id']`) → `c:{caller_id}`.
 *   3. Anonymous → `ip:{sha1(REMOTE_ADDR)[0:12]}`.
 *
 * On multisite the principal is suffixed with `@{blog_id}` so sub-sites
 * are separate trust domains. Filterable via `abilityguard_rate_limit_principal`.
 *
 * Config shape (long-form only, no shorthand):
 *
 *     'safety' => array(
 *         'rate_limits' => array(
 *             'policies' => array(
 *                 array( 'id' => 'burst',     'limit' => 5,  'window' => 1 ),
 *                 array( 'id' => 'sustained', 'limit' => 60, 'window' => 60 ),
 *             ),
 *         ),
 *     ),
 *
 * The recommended pair for AI-agent abilities is `[burst=5/1s, sustained=60/60s]`.
 *
 * Fail-open on storage exception (Stripe pattern): a throwing storage
 * MUST NOT block ability execution, we log via error_log and admit.
 */
final class RateLimiter {

	/**
	 * Boot guard.
	 */
	private static bool $registered = false;

	/**
	 * Selected storage backend. Lazily resolved on first use.
	 */
	private static ?Storage $storage = null;

	/**
	 * Per-request memo of the latest pre_execute_decision evaluation, so
	 * rest_post_dispatch can attach matching headers without reading state
	 * again. A simple LIFO is fine, REST dispatch resolves a single ability
	 * per HTTP request in the abilities-api shape.
	 *
	 * @var array<int, array{policy:Policy, remaining:int, reset:int, exhausted:bool}>
	 */
	private static array $last_dispatch_state = array();

	/**
	 * Wire the filter seams. Idempotent.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'abilityguard_pre_execute_decision', array( self::class, 'maybe_block' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( self::class, 'emit_headers' ), 10, 3 );
	}

	/**
	 * Test helper.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered          = false;
		self::$storage             = null;
		self::$last_dispatch_state = array();
		remove_filter( 'abilityguard_pre_execute_decision', array( self::class, 'maybe_block' ), 10 );
		remove_filter( 'rest_post_dispatch', array( self::class, 'emit_headers' ), 10 );
	}

	/**
	 * Resolve the storage backend exactly once per request. The picker
	 * order (Redis > ObjectCache > Transient) is filterable via
	 * `abilityguard_rate_limiter_storage`.
	 */
	public static function storage(): Storage {
		if ( null === self::$storage ) {
			self::$storage = self::pick_storage();
			/**
			 * Filter the rate-limiter storage backend.
			 *
			 * @since 1.3.0
			 *
			 * @param Storage $storage Selected backend.
			 */
			$filtered = apply_filters( 'abilityguard_rate_limiter_storage', self::$storage );
			if ( $filtered instanceof Storage ) {
				self::$storage = $filtered;
			}
		}
		return self::$storage;
	}

	/**
	 * Pre-execute filter: evaluate every declared policy. Admit iff all
	 * pass. On reject return a 429 WP_Error with `retry_after` set to the
	 * MAX of the exhausted policies' time-to-reset.
	 *
	 * @param mixed                $decision     Existing decision (null = proceed).
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

		$safety   = is_array( $context['safety'] ?? null ) ? $context['safety'] : array();
		$policies = Policy::from_safety( $safety );
		if ( array() === $policies ) {
			return $decision;
		}

		$principal    = self::resolve_principal( $context );
		$ability_hash = substr( md5( $ability_name ), 0, 16 );
		$now          = self::now();

		$state      = array();
		$exhausted  = array();
		$retry_after = 0;

		foreach ( $policies as $policy ) {
			try {
				$counts = self::counts_for( $policy, $principal, $ability_hash, $now );
			} catch ( Throwable $e ) {
				// Fail-open (Stripe pattern). A throwing backend must not
				// block the call; log and admit this policy.
				error_log( sprintf( 'AbilityGuard rate limiter storage error: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			$estimate = Window::estimate( $counts['prev'], $counts['curr'], $now, $policy->window );
			$reset    = Window::seconds_until_reset( $now, $policy->window );

			if ( $estimate >= $policy->limit ) {
				$exhausted[]  = $policy;
				$retry_after  = max( $retry_after, $reset );
				$state[]      = array(
					'policy'    => $policy,
					'remaining' => 0,
					'reset'     => $reset,
					'exhausted' => true,
				);
				continue;
			}

			// Admitted by this policy: record the increment.
			try {
				$ttl     = $policy->window * 2;
				$key     = self::bucket_key( $policy, $principal, $ability_hash, Window::bucket_index( $now, $policy->window ) );
				$new_curr = self::storage()->increment( $key, $ttl );
				$counts['curr'] = $new_curr;
			} catch ( Throwable $e ) {
				error_log( sprintf( 'AbilityGuard rate limiter storage error: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			$post_estimate = Window::estimate( $counts['prev'], $counts['curr'], $now, $policy->window );
			$remaining     = (int) max( 0, floor( $policy->limit - $post_estimate ) );

			$state[] = array(
				'policy'    => $policy,
				'remaining' => $remaining,
				'reset'     => $reset,
				'exhausted' => false,
			);
		}

		self::$last_dispatch_state = $state;

		if ( array() !== $exhausted ) {
			$ids = array_map( static fn( Policy $p ): string => $p->id, $exhausted );
			return new WP_Error(
				'abilityguard_rate_limited',
				sprintf(
					'Rate limit exceeded for ability "%s" (policies: %s).',
					$ability_name,
					implode( ', ', $ids )
				),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
					'policies'    => array_map(
						static fn( Policy $p ): array => array(
							'id'     => $p->id,
							'limit'  => $p->limit,
							'window' => $p->window,
						),
						$exhausted
					),
				)
			);
		}

		return $decision;
	}

	/**
	 * rest_post_dispatch listener: attach IETF RateLimit headers to any
	 * AbilityGuard-routed response. Scoped by route prefix so we don't
	 * pollute other plugins' REST responses.
	 *
	 * @param WP_HTTP_Response|WP_REST_Response|WP_Error $response Response.
	 * @param mixed                                      $server   REST server (unused).
	 * @param WP_REST_Request                            $request  Request.
	 *
	 * @return mixed
	 */
	public static function emit_headers( $response, $server, $request ): mixed {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( ! self::is_abilityguard_route( $request->get_route() ) ) {
			return $response;
		}

		$state = self::$last_dispatch_state;
		if ( array() === $state ) {
			return $response;
		}
		self::$last_dispatch_state = array();

		$policy_field = array();
		$rate_field   = array();
		$blocked      = false;
		$retry_after  = 0;

		foreach ( $state as $row ) {
			$p              = $row['policy'];
			$policy_field[] = sprintf( '"%s";q=%d;w=%d', $p->id, $p->limit, $p->window );
			$rate_field[]   = sprintf( '"%s";r=%d;t=%d', $p->id, $row['remaining'], $row['reset'] );
			if ( $row['exhausted'] ) {
				$blocked     = true;
				$retry_after = max( $retry_after, (int) $row['reset'] );
			}
		}

		if ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) {
			$response->header( 'RateLimit-Policy', implode( ', ', $policy_field ) );
			$response->header( 'RateLimit', implode( ', ', $rate_field ) );
			if ( $blocked ) {
				$response->header( 'Retry-After', (string) $retry_after );
			}
		}

		return $response;
	}

	/**
	 * Resolve the principal for the current call. Three-tier fallback,
	 * filterable. Multisite-aware: includes the current blog id so
	 * sub-sites are separate trust domains.
	 *
	 * @param array<string, mixed> $context Wrapper context.
	 */
	private static function resolve_principal( array $context ): string {
		$user_id = (int) get_current_user_id();
		if ( $user_id > 0 ) {
			$base = 'u:' . $user_id;
		} else {
			$caller_id = isset( $context['caller_id'] ) ? (string) $context['caller_id'] : '';
			if ( '' !== $caller_id ) {
				$base = 'c:' . $caller_id;
			} else {
				$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
				$hash = '' === $ip ? 'unknown' : substr( sha1( $ip ), 0, 12 );
				$base = 'ip:' . $hash;
			}
		}

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$base .= '@' . (int) get_current_blog_id();
		}

		/**
		 * Filter the resolved principal.
		 *
		 * @since 1.3.0
		 *
		 * @param string               $principal Resolved principal id.
		 * @param array<string, mixed> $context   Wrapper context.
		 */
		$filtered = apply_filters( 'abilityguard_rate_limit_principal', $base, $context );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $base;
	}

	/**
	 * Read the (prev, curr) counter pair for a policy, in one place so
	 * the storage round-trip count is visible.
	 *
	 * @return array{prev:int, curr:int}
	 */
	private static function counts_for( Policy $policy, string $principal, string $ability_hash, int $now ): array {
		$bucket   = Window::bucket_index( $now, $policy->window );
		$curr_key = self::bucket_key( $policy, $principal, $ability_hash, $bucket );
		$prev_key = self::bucket_key( $policy, $principal, $ability_hash, $bucket - 1 );

		$storage = self::storage();
		return array(
			'prev' => $storage->get( $prev_key ),
			'curr' => $storage->get( $curr_key ),
		);
	}

	/**
	 * Build a bucket key. Layout:
	 *
	 *     ag:rl:v1:{policy_id}:{principal}:{ability_hash16}:{bucket_index}
	 *
	 * For TransientStorage this becomes the option_name; the 191-char
	 * `wp_options.option_name` limit is comfortably respected because we
	 * hash the ability name to 16 hex chars (kept from the previous
	 * implementation).
	 */
	private static function bucket_key( Policy $policy, string $principal, string $ability_hash, int $bucket_index ): string {
		// Replace `:` with `_` so the key is also valid as a transient
		// option_name (which forbids no chars in particular but `:` reads
		// poorly in wp_options listings).
		$safe_principal = str_replace( ':', '_', $principal );
		return sprintf( 'ag_rl_v1_%s_%s_%s_%d', $policy->id, $safe_principal, $ability_hash, $bucket_index );
	}

	/**
	 * Pick the best available storage backend.
	 */
	private static function pick_storage(): Storage {
		// `wp_using_ext_object_cache()` is true when a drop-in is loaded.
		// We can't reliably introspect "is this drop-in Redis or Memcached"
		// without coupling to drop-in internals, so we use Redis-class
		// constants as the cheapest signal: the wordpress/redis-cache
		// plugin defines `WP_REDIS_PREFIX` and a global $redis_object_cache.
		if ( function_exists( 'wp_using_ext_object_cache' ) && \wp_using_ext_object_cache() ) {
			if ( class_exists( '\\WP_Object_Cache' ) && (
				defined( 'WP_REDIS_PREFIX' )
				|| isset( $GLOBALS['redis_object_cache'] )
				|| isset( $GLOBALS['wp_object_cache']->redis )
			) ) {
				return new RedisStorage();
			}
			return new ObjectCacheStorage();
		}
		return new TransientStorage();
	}

	/**
	 * AbilityGuard route check for `rest_post_dispatch` scoping. Two
	 * surfaces qualify:
	 *
	 *   - The plugin's own /abilityguard/v1/* admin REST routes.
	 *   - The abilities-api /wp-abilities/v1/abilities/.../run endpoint
	 *     where ability invocations land.
	 */
	private static function is_abilityguard_route( string $route ): bool {
		if ( '' === $route ) {
			return false;
		}
		if ( str_starts_with( $route, '/abilityguard/' ) ) {
			return true;
		}
		if ( str_starts_with( $route, '/wp-abilities/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Clock seam. Filterable so tests can straddle a bucket boundary
	 * without sleeping.
	 */
	private static function now(): int {
		/**
		 * Filter the current unix time used by the rate limiter. Test seam.
		 *
		 * @since 1.3.0
		 *
		 * @param int $now Default `time()`.
		 */
		return (int) apply_filters( 'abilityguard_rate_limit_now', time() );
	}
}
