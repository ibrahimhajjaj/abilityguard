<?php
/**
 * Collector contract.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Reads live state for a snapshot surface (post_meta, options, etc.).
 */
interface CollectorInterface {

	/**
	 * Read current values for the declared spec.
	 *
	 * @param mixed $spec Surface-specific spec (see concrete collectors).
	 *
	 * @return array<mixed>
	 */
	public function collect( $spec ): array;

	/**
	 * Restore previously-captured state. Inverse of collect().
	 *
	 * @param array<mixed> $captured Payload returned by collect() earlier.
	 */
	public function restore( array $captured ): void;
}
