<?php
/**
 * JSON helpers used by snapshot + audit layers.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Support;

use RuntimeException;

/**
 * JSON encoding utilities.
 *
 * Canonical() produces byte-identical output for equivalent associative
 * structures by recursively sorting keys. encode_compressed() /
 * decode_compressed() handle the snapshot storage path.
 */
final class Json {

	/**
	 * Canonical JSON: keys sorted recursively, no pretty print, unescaped unicode.
	 *
	 * @param mixed $value Value to encode.
	 *
	 * @throws RuntimeException When encoding fails.
	 */
	public static function canonical( $value ): string {
		$normalized = self::normalize( $value );
		$encoded    = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			throw new RuntimeException( 'abilityguard: failed to encode canonical json' );
		}
		return $encoded;
	}

	/**
	 * Gzip-compressed canonical JSON for snapshot storage.
	 *
	 * @param mixed $value Value to encode.
	 *
	 * @throws RuntimeException When gzencode fails.
	 */
	public static function encode_compressed( $value ): string {
		$compressed = gzencode( self::canonical( $value ), 6 );
		if ( false === $compressed ) {
			throw new RuntimeException( 'abilityguard: failed to gzencode snapshot' );
		}
		return $compressed;
	}

	/**
	 * Decode a compressed snapshot blob.
	 *
	 * @param string $blob Compressed blob.
	 * @return mixed
	 *
	 * @throws RuntimeException When decoding fails.
	 */
	public static function decode_compressed( string $blob ) {
		$raw = gzdecode( $blob );
		if ( false === $raw ) {
			throw new RuntimeException( 'abilityguard: failed to gzdecode snapshot' );
		}
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( 'abilityguard: failed to decode snapshot json: ' . json_last_error_msg() );
		}
		return $decoded;
	}

	/**
	 * Recursively sort array keys to make output order-independent.
	 *
	 * @param mixed $value Input.
	 * @return mixed Normalized value.
	 */
	private static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'normalize' ), $value );
		}
		ksort( $value );
		foreach ( $value as $key => $inner ) {
			$value[ $key ] = self::normalize( $inner );
		}
		return $value;
	}
}
