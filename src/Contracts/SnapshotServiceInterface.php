<?php
/**
 * Snapshot service contract.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Contracts;

/**
 * Captures pre-invocation state as declared in the ability's safety config.
 */
interface SnapshotServiceInterface {

	/**
	 * Capture snapshot for a single invocation.
	 *
	 * @param string               $invocation_id UUID.
	 * @param array<string, mixed> $safety        The safety config from the ability.
	 * @param mixed                $input         Ability input.
	 *
	 * @return array{ pre_hash: string, snapshot_id: int|null, surfaces: array<string, mixed> }
	 */
	public function capture( string $invocation_id, array $safety, $input ): array;
}
