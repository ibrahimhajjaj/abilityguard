<?php
/**
 * Stable hash helpers.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Support;

/**
 * Deterministic hashing for snapshot payloads.
 *
 * The same logical value must always produce the same hash, regardless of
 * associative-array key ordering. That guarantee lets the audit log's
 * pre_hash / post_hash columns double as a tamper / drift signal.
 */
final class Hash {

	/**
	 * Hash an arbitrary value as sha256 of its canonical JSON encoding.
	 *
	 * @param mixed $value Value to hash.
	 */
	public static function stable( $value ): string {
		return hash( 'sha256', Json::canonical( $value ) );
	}
}
