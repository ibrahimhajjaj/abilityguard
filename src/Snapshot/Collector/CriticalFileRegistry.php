<?php
/**
 * Registry of "critical" file path matchers.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Tracks which paths FilesCollector should hash under STRATEGY_CRITICAL_HASH.
 *
 * Replaces the previous const+filter combo with an object that other code
 * (test fixtures, plugin authors, the future admin UI) can register against
 * imperatively without juggling closures on the
 * `abilityguard_files_critical_suffixes` filter.
 *
 * The legacy filter still wins - it is applied on top of the registered
 * suffixes inside FilesCollector - so existing customisations keep working.
 */
final class CriticalFileRegistry {

	/**
	 * Default critical-path matchers. Paths ending in any of these are
	 * considered critical by default.
	 *
	 * @var string[]
	 */
	private const DEFAULTS = array(
		'/wp-config.php',
		'/.env',
		'/.htaccess',
	);

	/**
	 * Per-process registry. Test code should call reset() between cases.
	 *
	 * @var string[]
	 */
	private static array $custom = array();

	/**
	 * Register an additional suffix as critical. No-op for empty strings or
	 * duplicates of already-registered suffixes.
	 *
	 * @param string $suffix Path suffix (e.g. '/secret.token' or '.env.production').
	 */
	public static function add( string $suffix ): void {
		$suffix = trim( $suffix );
		if ( '' === $suffix ) {
			return;
		}
		if ( in_array( $suffix, self::$custom, true ) ) {
			return;
		}
		self::$custom[] = $suffix;
	}

	/**
	 * Remove a previously-registered suffix. No-op if not present. Built-in
	 * defaults cannot be removed via this method - use the
	 * `abilityguard_files_critical_suffixes` filter to override defaults
	 * globally.
	 *
	 * @param string $suffix Suffix to drop from the runtime registry.
	 */
	public static function remove( string $suffix ): void {
		self::$custom = array_values( array_diff( self::$custom, array( $suffix ) ) );
	}

	/**
	 * Whether a path ends in any registered critical suffix.
	 *
	 * @param string $path Absolute path to test.
	 */
	public static function matches( string $path ): bool {
		foreach ( self::all() as $suffix ) {
			if ( '' !== $suffix && str_ends_with( $path, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Full list of suffixes currently considered critical.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_values( array_unique( array_merge( self::DEFAULTS, self::$custom ) ) );
	}

	/**
	 * Clear runtime registrations. Defaults remain. Test-only helper.
	 */
	public static function reset(): void {
		self::$custom = array();
	}
}
