<?php
/**
 * Snapshot orchestration.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot;

use AbilityGuard\Contracts\SnapshotServiceInterface;
use AbilityGuard\Snapshot\Collector\CollectorInterface;
use AbilityGuard\Snapshot\Collector\FilesCollector;
use AbilityGuard\Snapshot\Collector\OptionsCollector;
use AbilityGuard\Snapshot\Collector\PostMetaCollector;
use AbilityGuard\Snapshot\Collector\TaxonomyCollector;
use AbilityGuard\Snapshot\Collector\UserRoleCollector;
use AbilityGuard\Support\Hash;

/**
 * Resolves a per-invocation snapshot spec, runs each collector, persists
 * the captured surfaces, and returns hash + storage id for audit.
 *
 * Safety config recognised:
 *   safety.snapshot          (callable|array): resolver or static spec
 *   safety.snapshot.post_meta  array<int,string[]>
 *   safety.snapshot.options    string[]
 *
 * If safety.snapshot is callable, it is called with the ability input and
 * must return the spec array.
 */
final class SnapshotService implements SnapshotServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param SnapshotStore                          $store      Persistence.
	 * @param array<string, CollectorInterface>|null $collectors Surface => collector. Defaults wired if null.
	 */
	public function __construct(
		private SnapshotStore $store,
		private ?array $collectors = null
	) {
		if ( null === $this->collectors ) {
			$this->collectors = array(
				'post_meta' => new PostMetaCollector(),
				'options'   => new OptionsCollector(),
				'taxonomy'  => new TaxonomyCollector(),
				'user_role' => new UserRoleCollector(),
				'files'     => new FilesCollector(),
			);
		}
	}

	/**
	 * Capture snapshot for a single invocation.
	 *
	 * @param string               $invocation_id UUID.
	 * @param array<string, mixed> $safety        Safety config from the ability.
	 * @param mixed                $input         Ability input.
	 *
	 * @return array{ pre_hash: string, snapshot_id: int|null, surfaces: array<string, mixed> }
	 */
	public function capture( string $invocation_id, array $safety, $input ): array {
		$spec = $this->resolve_spec( $safety, $input );
		if ( array() === $spec ) {
			return array(
				'pre_hash'    => Hash::stable( array() ),
				'snapshot_id' => null,
				'surfaces'    => array(),
			);
		}

		$surfaces = array();
		foreach ( $spec as $surface => $surface_spec ) {
			if ( ! isset( $this->collectors[ $surface ] ) ) {
				continue;
			}
			$surfaces[ $surface ] = $this->collectors[ $surface ]->collect( $surface_spec );
		}

		$pre_hash    = Hash::stable( $surfaces );
		$snapshot_id = $this->store->insert( $invocation_id, $surfaces, $pre_hash );

		return array(
			'pre_hash'    => $pre_hash,
			'snapshot_id' => $snapshot_id,
			'surfaces'    => $surfaces,
		);
	}

	/**
	 * Resolve safety.snapshot into a concrete spec array.
	 *
	 * @param array<string, mixed> $safety Safety config.
	 * @param mixed                $input  Ability input.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_spec( array $safety, $input ): array {
		if ( empty( $safety['snapshot'] ) ) {
			return array();
		}
		$snapshot = $safety['snapshot'];
		if ( is_callable( $snapshot ) ) {
			$resolved = $snapshot( $input );
			return is_array( $resolved ) ? $resolved : array();
		}
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}
		return array();
	}
}
