<?php
/**
 * Abilities API capability probe.
 *
 * Detects whether the loaded WP_Ability class ships the WP 7.1 execution
 * lifecycle filters (wp_pre_execute_ability, wp_ability_normalize_input,
 * wp_ability_permission_result, wp_ability_execute_result). Probes by
 * reflecting on the class source rather than by parsing a $wp_version
 * string, since the abilities-api also ships as a standalone plugin whose
 * release cadence is independent of core.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Compat;

defined( 'ABSPATH' ) || exit;

use ReflectionMethod;
use Throwable;

/**
 * Cached, runtime probe of the abilities-api filter surface.
 */
final class AbilitiesApi {

	/**
	 * Memoized result of has_lifecycle_filters().
	 *
	 * @var bool|null
	 */
	private static ?bool $cached_has_lifecycle_filters = null;

	/**
	 * True iff WP_Ability::execute() ships the 7.1 short-circuit filter.
	 *
	 * The detection is a source scan, not a docblock parse or version
	 * string check, so it stays correct against in-development branches
	 * and against the standalone abilities-api plugin's own release line.
	 */
	public static function has_lifecycle_filters(): bool {
		if ( null !== self::$cached_has_lifecycle_filters ) {
			return self::$cached_has_lifecycle_filters;
		}

		self::$cached_has_lifecycle_filters = self::probe();
		return self::$cached_has_lifecycle_filters;
	}

	/**
	 * Uncached source-scan probe.
	 */
	private static function probe(): bool {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return false;
		}

		try {
			$rm    = new ReflectionMethod( 'WP_Ability', 'execute' );
			$file  = $rm->getFileName();
			$start = $rm->getStartLine();
			$end   = $rm->getEndLine();
			if ( ! is_string( $file ) || ! is_readable( $file ) || $start < 1 || $end < $start ) {
				return false;
			}
			$lines = file( $file, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				return false;
			}
			$body = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );
			return false !== strpos( $body, "'wp_pre_execute_ability'" );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Test helper: reset the memoized probe.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$cached_has_lifecycle_filters = null;
	}
}
