<?php
/**
 * MySQL advisory lock wrapper for per-surface serialisation.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Concurrency;

/**
 * Thin wrapper around MySQL GET_LOCK() / RELEASE_LOCK().
 *
 * Lock names are always prefixed with `abilityguard:` so they cannot
 * collide with lock names issued by other plugins.
 *
 * All public methods are static so callers need no instance.
 */
final class Lock {

	/**
	 * Namespace prefix added to every lock name.
	 *
	 * @var string
	 */
	private const PREFIX = 'abilityguard:';

	/**
	 * Acquire a named advisory lock.
	 *
	 * @param string $key             Full lock key (including prefix).
	 * @param int    $timeout_seconds Seconds to wait. 0 = fail immediately.
	 *
	 * @return bool True when the lock was acquired; false on timeout.
	 */
	public static function acquire( string $key, int $timeout_seconds = 5 ): bool {
		global $wpdb;

		$timeout = max( 0, $timeout_seconds );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- key is sha1-derived, never user input.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$key,
				$timeout
			)
		);

		if ( null === $result ) {
			// NULL means an error occurred (e.g. misconfigured MySQL).
			// Log and proceed without the lock (degraded mode).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'AbilityGuard: GET_LOCK() returned NULL for key "%s". Proceeding without lock.',
					$key
				)
			);
			return false;
		}

		return '1' === (string) $result;
	}

	/**
	 * Release a named advisory lock.
	 *
	 * Only releases locks held by THIS connection. Releasing a lock not held
	 * by this connection returns false (MySQL returns 0 or NULL).
	 *
	 * @param string $key Full lock key (including prefix).
	 *
	 * @return bool True if the lock was released by this connection.
	 */
	public static function release( string $key ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- key is sha1-derived, never user input.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				$key
			)
		);

		return '1' === (string) $result;
	}

	/**
	 * Derive a deterministic lock key from a resolved snapshot spec.
	 *
	 * Algorithm:
	 *  1. Walk each surface in the spec and emit `surface:identifier` strings.
	 *  2. For `post_meta`: emit `post_meta:<post_id>:<meta_key>` for every key.
	 *  3. For `options`: emit `options:<option_name>`.
	 *  4. For `taxonomy`: emit `taxonomy:<taxonomy>:<term_id>`.
	 *  5. For `user_role`: emit `user_role:<user_id>`.
	 *  6. For `files`: emit `files:<path>`.
	 *  7. Sort the list, join with `|`, sha1 the result.
	 *  8. Prefix with `abilityguard:`.
	 *
	 * Two invocations that share NO surface identifiers get different keys and
	 * therefore do NOT block each other.
	 *
	 * @param array<string, mixed> $spec Resolved snapshot spec.
	 *
	 * @return string Lock key, e.g. `abilityguard:da39a3ee5e6b4b0d3255...`.
	 */
	public static function key_for_spec( array $spec ): string {
		$parts = array();

		foreach ( $spec as $surface => $surface_spec ) {
			switch ( $surface ) {
				case 'post_meta':
					// Format: post_id mapped to an array of meta keys.
					if ( is_array( $surface_spec ) ) {
						foreach ( $surface_spec as $post_id => $keys ) {
							if ( is_array( $keys ) ) {
								foreach ( $keys as $meta_key ) {
									$parts[] = 'post_meta:' . (string) $post_id . ':' . (string) $meta_key;
								}
							}
						}
					}
					break;

				case 'options':
					// Format: flat list of option names.
					if ( is_array( $surface_spec ) ) {
						foreach ( $surface_spec as $option ) {
							$parts[] = 'options:' . (string) $option;
						}
					}
					break;

				case 'taxonomy':
					// Format: taxonomy name mapped to an array of term ids.
					if ( is_array( $surface_spec ) ) {
						foreach ( $surface_spec as $taxonomy => $term_ids ) {
							if ( is_array( $term_ids ) ) {
								foreach ( $term_ids as $term_id ) {
									$parts[] = 'taxonomy:' . (string) $taxonomy . ':' . (string) $term_id;
								}
							}
						}
					}
					break;

				case 'user_role':
					// Format: flat list of user ids.
					if ( is_array( $surface_spec ) ) {
						foreach ( $surface_spec as $user_id ) {
							$parts[] = 'user_role:' . (string) $user_id;
						}
					}
					break;

				case 'files':
					// Format: indexed list of paths or associative path-keyed array.
					if ( is_array( $surface_spec ) ) {
						foreach ( $surface_spec as $key => $val ) {
							// Accept both indexed (path as value) and associative (path as key).
							$path    = is_int( $key ) ? (string) $val : (string) $key;
							$parts[] = 'files:' . $path;
						}
					}
					break;

				default:
					// Unknown surface: add a generic entry so different unknown
					// surfaces still get distinct identifiers.
					$parts[] = (string) $surface . ':' . wp_json_encode( $surface_spec );
					break;
			}
		}

		sort( $parts );
		return self::PREFIX . sha1( implode( '|', $parts ) );
	}
}
