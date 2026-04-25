<?php
/**
 * One-click rollback for an invocation.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Rollback;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Snapshot\Collector\CollectorInterface;
use AbilityGuard\Snapshot\Collector\FilesCollector;
use AbilityGuard\Snapshot\Collector\OptionsCollector;
use AbilityGuard\Snapshot\Collector\PostMetaCollector;
use AbilityGuard\Snapshot\Collector\TaxonomyCollector;
use AbilityGuard\Snapshot\Collector\UserRoleCollector;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Error;

/**
 * Reverses a recorded invocation by restoring each captured surface.
 */
final class RollbackService {

	/**
	 * Constructor.
	 *
	 * @param LogRepository                          $logs       Audit log repo.
	 * @param SnapshotStore                          $snapshots  Snapshot store.
	 * @param array<string, CollectorInterface>|null $collectors Surface => collector. Defaults wired if null.
	 */
	public function __construct(
		private LogRepository $logs,
		private SnapshotStore $snapshots,
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
	 * Roll back the invocation identified by $reference.
	 *
	 * Accepts either a numeric log id or an invocation_id (uuid).
	 *
	 * @param int|string $reference Log id or invocation uuid.
	 *
	 * @return true|WP_Error
	 */
	public function rollback( $reference ) {
		$log = $this->resolve_log( $reference );
		if ( null === $log ) {
			return new WP_Error( 'abilityguard_log_not_found', 'No audit row matched the reference.' );
		}
		if ( 'rolled_back' === $log['status'] ) {
			return new WP_Error( 'abilityguard_already_rolled_back', 'This invocation was already rolled back.' );
		}

		$snapshot = $this->snapshots->find_by_invocation_id( (string) $log['invocation_id'] );
		if ( null === $snapshot ) {
			return new WP_Error( 'abilityguard_snapshot_missing', 'No snapshot stored for this invocation.' );
		}

		foreach ( $snapshot['surfaces'] as $surface => $captured ) {
			if ( ! isset( $this->collectors[ $surface ] ) || ! is_array( $captured ) ) {
				continue;
			}
			$this->collectors[ $surface ]->restore( $captured );
		}

		$this->logs->update_status( (int) $log['id'], 'rolled_back' );

		do_action( 'abilityguard_rollback', $log, $snapshot );

		return true;
	}

	/**
	 * Locate a log row by id or uuid.
	 *
	 * @param int|string $reference Reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_log( $reference ): ?array {
		if ( is_int( $reference ) || ctype_digit( (string) $reference ) ) {
			return $this->logs->find( (int) $reference );
		}
		return $this->logs->find_by_invocation_id( (string) $reference );
	}
}
