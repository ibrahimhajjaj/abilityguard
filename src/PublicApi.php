<?php
/**
 * Procedural helpers exposed to plugin authors.
 *
 * Thin wrappers around namespaced services so plugin authors can call them
 * without pulling AbilityGuard\* into their code.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

if ( ! function_exists( 'abilityguard_rollback' ) ) {

	/**
	 * Roll back an invocation by id.
	 *
	 * @param int|string $invocation_id Numeric log id or invocation uuid.
	 *
	 * @return true|\WP_Error
	 */
	function abilityguard_rollback( $invocation_id ) {
		$service = new \AbilityGuard\Rollback\RollbackService(
			new \AbilityGuard\Audit\LogRepository(),
			new \AbilityGuard\Snapshot\SnapshotStore()
		);
		return $service->rollback( $invocation_id );
	}
}

if ( ! function_exists( 'abilityguard_snapshot_meta' ) ) {

	/**
	 * Read current post_meta values for the given post + keys.
	 *
	 * @param int      $post_id Post id.
	 * @param string[] $keys    Meta keys.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function abilityguard_snapshot_meta( int $post_id, array $keys ): array {
		return ( new \AbilityGuard\Snapshot\Collector\PostMetaCollector() )->collect( array( $post_id => $keys ) );
	}
}

if ( ! function_exists( 'abilityguard_snapshot_options' ) ) {

	/**
	 * Read current option values for the given keys.
	 *
	 * @param string[] $keys Option keys.
	 *
	 * @return array<string, mixed>
	 */
	function abilityguard_snapshot_options( array $keys ): array {
		return ( new \AbilityGuard\Snapshot\Collector\OptionsCollector() )->collect( $keys );
	}
}
