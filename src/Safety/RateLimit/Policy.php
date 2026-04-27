<?php
/**
 * Rate-limit policy value object.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable (id, limit, window) tuple. One per declared policy. The id is
 * also the wire identifier emitted in the IETF RateLimit-Policy header
 * (draft-ietf-httpapi-ratelimit-headers-10 §B.3.2), so it must be a short
 * sf-string-safe token (e.g. "burst", "sustained", "daily").
 */
final class Policy {

	/**
	 * Construct an immutable policy.
	 *
	 * @param string $id     Wire identifier (sf-string-safe token).
	 * @param int    $limit  Max calls allowed inside the window.
	 * @param int    $window Window length in seconds.
	 */
	public function __construct(
		public readonly string $id,
		public readonly int $limit,
		public readonly int $window
	) {
	}

	/**
	 * Build a list of Policy objects from the safety.rate_limits config.
	 *
	 * Long-form only:
	 *
	 *     'rate_limits' => array(
	 *         'policies' => array(
	 *             array( 'id' => 'burst',     'limit' => 5,  'window' => 1 ),
	 *             array( 'id' => 'sustained', 'limit' => 60, 'window' => 60 ),
	 *         ),
	 *     ),
	 *
	 * Invalid / non-positive entries are silently dropped, same posture
	 * as today's `max_calls_per_hour <= 0` no-op.
	 *
	 * @param array<string, mixed> $safety The full safety array.
	 *
	 * @return Policy[]
	 */
	public static function from_safety( array $safety ): array {
		$rl = $safety['rate_limits'] ?? null;
		if ( ! is_array( $rl ) ) {
			return array();
		}
		$raw = $rl['policies'] ?? null;
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id     = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			$limit  = isset( $entry['limit'] ) ? (int) $entry['limit'] : 0;
			$window = isset( $entry['window'] ) ? (int) $entry['window'] : 0;
			if ( '' === $id || $limit <= 0 || $window <= 0 ) {
				continue;
			}
			$out[] = new self( $id, $limit, $window );
		}
		return $out;
	}
}
