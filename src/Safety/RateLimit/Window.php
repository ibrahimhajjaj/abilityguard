<?php
/**
 * Sliding-window-counter math.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions for the Cloudflare/Upstash sliding-window-counter formula.
 *
 * For a window of W seconds we keep two counters: the current bucket (index
 * floor(now/W)) and the previous one (index - 1). The estimated rate is:
 *
 *     prev * ((W - elapsed) / W) + curr
 *
 * where `elapsed = now mod W` is how far we are into the current bucket.
 *
 * See Cloudflare's "Counting things, a lot of different things" and the
 * Upstash ratelimit-ts algorithms doc for the same formula.
 */
final class Window {

	/**
	 * Bucket index for `now` in a window of `W` seconds.
	 *
	 * @param int $now    Current unix timestamp.
	 * @param int $window Window length in seconds.
	 */
	public static function bucket_index( int $now, int $window ): int {
		return intdiv( $now, $window );
	}

	/**
	 * Seconds elapsed inside the current bucket.
	 *
	 * @param int $now    Current unix timestamp.
	 * @param int $window Window length in seconds.
	 */
	public static function elapsed_in_bucket( int $now, int $window ): int {
		return $now % $window;
	}

	/**
	 * Time-to-reset for the current bucket. Used for the `t` parameter on
	 * `RateLimit` (admit path) and `Retry-After` (reject path).
	 *
	 * Pure function of (now, W). No storage round-trip, the previous
	 * implementation read `_transient_timeout_<key>` which leaked transient
	 * internals and broke under external object caches.
	 *
	 * @param int $now    Current unix timestamp.
	 * @param int $window Window length in seconds.
	 */
	public static function seconds_until_reset( int $now, int $window ): int {
		$t = $window - ( $now % $window );
		return $t > 0 ? $t : $window;
	}

	/**
	 * Weighted estimate of in-flight count for the sliding window.
	 *
	 * @param int $prev_count    Count in the previous bucket.
	 * @param int $curr_count    Count in the current bucket.
	 * @param int $now           Current unix timestamp.
	 * @param int $window        Window length in seconds.
	 *
	 * @return float Weighted estimate. Caller compares with the policy limit.
	 */
	public static function estimate( int $prev_count, int $curr_count, int $now, int $window ): float {
		$elapsed = self::elapsed_in_bucket( $now, $window );
		$weight  = ( $window - $elapsed ) / $window;
		return ( $prev_count * $weight ) + $curr_count;
	}
}
