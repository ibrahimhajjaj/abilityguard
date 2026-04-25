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
use AbilityGuard\Support\Redactor;
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
	 * When the snapshot contains redacted values (sentinel '[redacted]'), those
	 * individual keys are skipped during restore. If any keys are skipped the
	 * method returns WP_Error 'abilityguard_rollback_partial' listing the
	 * affected surface/key pairs. Pass $force = true to mark the invocation as
	 * rolled-back anyway even when keys were skipped.
	 *
	 * @param int|string $reference Log id or invocation uuid.
	 * @param bool       $force     Mark rolled-back even if some keys were skipped.
	 *
	 * @return true|WP_Error
	 */
	public function rollback( $reference, bool $force = false ) {
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

		$skipped = array(); // Track keys that could not be restored due to redaction.

		foreach ( $snapshot['surfaces'] as $surface => $captured ) {
			if ( ! isset( $this->collectors[ $surface ] ) || ! is_array( $captured ) ) {
				continue;
			}

			// Strip redacted entries; collect them in $skipped for the partial report.
			$restorable = array();
			foreach ( $captured as $key => $entry ) {
				if ( $this->surface_entry_is_redacted( $entry ) ) {
					$skipped[] = "{$surface}.{$key}";
				} else {
					$restorable[ $key ] = $entry;
				}
			}

			if ( array() !== $restorable ) {
				$this->collectors[ $surface ]->restore( $restorable );
			}
		}

		if ( array() !== $skipped && ! $force ) {
			return new WP_Error(
				'abilityguard_rollback_partial',
				sprintf(
					'Rollback incomplete: %d key(s) were redacted at snapshot time and cannot be restored: %s. Pass force=true to mark rolled-back anyway.',
					count( $skipped ),
					implode( ', ', $skipped )
				),
				array( 'skipped_keys' => $skipped )
			);
		}

		$this->logs->update_status( (int) $log['id'], 'rolled_back' );

		do_action( 'abilityguard_rollback', $log, $snapshot );

		return true;
	}

	/**
	 * Determine whether a surface entry value is a redaction sentinel.
	 *
	 * Surface entries are stored as associative arrays keyed by meta-key or
	 * option name. We consider an entry "redacted" when its stored value IS the
	 * sentinel string - meaning the entire scalar value was replaced.
	 *
	 * @param mixed $entry A single value from the captured surface array.
	 *
	 * @return bool
	 */
	private function surface_entry_is_redacted( mixed $entry ): bool {
		return Redactor::SENTINEL === $entry;
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
