<?php
/**
 * Options snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Collects + restores wp_options values.
 *
 * Spec shape: string[] (option names).
 * Captured shape: array<string, mixed>; missing options use a sentinel so
 * restore can delete them rather than resurrect them as empty strings.
 */
final class OptionsCollector implements CollectorInterface {

	/**
	 * Sentinel placed in the captured payload when an option was absent at
	 * snapshot time. restore() uses it to decide between update and delete.
	 */
	public const MISSING = '__abilityguard_missing__';

	/**
	 * Read option values.
	 *
	 * @param mixed $spec string[] of option names.
	 *
	 * @return array<string, mixed>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}
		$out = array();
		foreach ( $spec as $name ) {
			$name         = (string) $name;
			$out[ $name ] = get_option( $name, self::MISSING );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Restore option values. Missing-at-capture options get deleted.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		foreach ( $captured as $name => $value ) {
			$name = (string) $name;
			if ( self::MISSING === $value ) {
				delete_option( $name );
				continue;
			}
			update_option( $name, $value );
		}
	}
}
