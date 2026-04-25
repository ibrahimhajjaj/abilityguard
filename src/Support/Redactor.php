<?php
/**
 * Pure redaction helper.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Support;

/**
 * Redactor.
 *
 * Walks a value tree and replaces the values of matching keys with a
 * placeholder string or the return value of a transform callable.
 * Matching is case-insensitive and supports dot-path notation for nested
 * keys (e.g. "actor.principal").
 *
 * Sentinel for snapshot-redacted keys: '[redacted]' stored as the value.
 * Rollback must skip any key whose stored value equals the sentinel so it
 * cannot corrupt live state by restoring the placeholder.
 *
 * In v0.4+, callers may pass a $transform callable that receives the
 * matching value and returns its replacement (e.g. Cipher::encrypt()).
 * The placeholder path is retained for back-compat.
 */
final class Redactor {

	/**
	 * Sentinel prefix stored in snapshot surfaces when a key is redacted.
	 * Using a distinct prefix lets rollback detect partial snapshots.
	 */
	public const SENTINEL = '[redacted]';

	/**
	 * Key present in Cipher envelopes that marks a value as encrypted.
	 * Consumers may check for this key instead of depending on Cipher directly.
	 */
	public const SENTINEL_KEY = '_abilityguard_redacted';

	/**
	 * Default list of keys to treat as sensitive (case-insensitive).
	 *
	 * @return string[]
	 */
	public static function default_keys(): array {
		return array(
			'password',
			'pass',
			'passwd',
			'token',
			'auth_token',
			'access_token',
			'refresh_token',
			'api_key',
			'apikey',
			'secret',
			'authorization',
			'cookie',
			'set-cookie',
		);
	}

	/**
	 * Redact matching keys from $value.
	 *
	 * When $transform is provided it is called with the matching value and its
	 * return replaces the original.  This is the v0.4 encrypted-redaction path.
	 * When $transform is null the $placeholder string is used (v0.3 back-compat).
	 *
	 * @param mixed         $value       The input to sanitise (array, object, scalar, null).
	 * @param string[]      $key_paths   Flat keys or dot-path strings like 'actor.principal'.
	 * @param string        $placeholder Replacement for redacted values (used when $transform is null).
	 * @param callable|null $transform   Optional transform: fn(mixed $value): mixed.
	 *
	 * @return mixed Redacted copy.
	 */
	public static function redact( mixed $value, array $key_paths, string $placeholder = self::SENTINEL, ?callable $transform = null ): mixed {
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return $value;
		}

		// Partition key_paths into flat keys and dot-paths.
		$flat_lower = array();
		$nested     = array(); // first segment (lower) => remaining path(s).

		foreach ( $key_paths as $path ) {
			$path = (string) $path;
			$dot  = strpos( $path, '.' );
			if ( false === $dot ) {
				$flat_lower[] = strtolower( $path );
			} else {
				$head              = strtolower( substr( $path, 0, $dot ) );
				$tail              = substr( $path, $dot + 1 );
				$nested[ $head ][] = $tail;
			}
		}

		$is_object = is_object( $value );
		$arr       = $is_object ? (array) $value : $value;

		$result = array();
		foreach ( $arr as $key => $item ) {
			$key_lower = strtolower( (string) $key );

			if ( in_array( $key_lower, $flat_lower, true ) ) {
				// Flat match: transform or replace with placeholder.
				$result[ $key ] = null !== $transform ? $transform( $item ) : $placeholder;
			} elseif ( isset( $nested[ $key_lower ] ) ) {
				// Dot-path match: recurse on the nested tails.
				$result[ $key ] = self::redact( $item, $nested[ $key_lower ], $placeholder, $transform );
			} else {
				// No match: still recurse to handle arrays nested inside.
				$result[ $key ] = ( is_array( $item ) || is_object( $item ) )
					? self::redact( $item, $key_paths, $placeholder, $transform )
					: $item;
			}
		}

		return $is_object ? (object) $result : $result;
	}
}
