<?php
/**
 * Bulk rollback service - rolls back multiple invocations in one call.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Rollback;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Snapshot\SnapshotStore;

/**
 * Rolls back a list of invocation log ids in a single operation.
 *
 * Processes ids in descending order (most-recent-first) so that if anything
 * errors mid-stream the older state is left intact.
 *
 * Error codes routed to `skipped` (non-fatal / informational):
 *   - abilityguard_already_rolled_back
 *   - abilityguard_rollback_drift
 *   - abilityguard_rollback_partial
 *
 * Error codes routed to `errors` (hard failures):
 *   - abilityguard_log_not_found
 *   - abilityguard_snapshot_missing
 *   - everything else
 */
final class BulkRollbackService {

	/**
	 * Error codes that are considered soft/informational (go to `skipped`).
	 */
	private const SKIP_CODES = array(
		'abilityguard_already_rolled_back',
		'abilityguard_rollback_drift',
		'abilityguard_rollback_partial',
	);

	/**
	 * Constructor.
	 *
	 * @param RollbackService $rollback  Single-item rollback service.
	 */
	public function __construct(
		private RollbackService $rollback
	) {}

	/**
	 * Roll back a batch of invocations by log id.
	 *
	 * @param int[] $log_ids Log ids to roll back.
	 * @param bool  $force   When true, drift is ignored and partial restores are tolerated.
	 *
	 * @return array{rolled_back: int[], skipped: array<int, string>, errors: array<int, string>}
	 */
	public function rollback_many( array $log_ids, bool $force = false ): array {
		// Ensure numeric values, then sort descending (most-recent-first).
		$ids = array_map( 'intval', $log_ids );
		rsort( $ids );

		$summary = array(
			'rolled_back' => array(),
			'skipped'     => array(),
			'errors'      => array(),
		);

		foreach ( $ids as $id ) {
			$result = $this->rollback->rollback( $id, $force );

			if ( true === $result ) {
				$summary['rolled_back'][] = $id;
				continue;
			}

			// WP_Error - sort into skipped vs errors.
			$code = $result->get_error_code();
			if ( in_array( $code, self::SKIP_CODES, true ) ) {
				$summary['skipped'][ $id ] = (string) $code;
			} else {
				$summary['errors'][ $id ] = (string) $code;
			}
		}

		/**
		 * Fires after all bulk rollback attempts are complete.
		 *
		 * @param array{rolled_back: int[], skipped: array<int, string>, errors: array<int, string>} $summary Summary.
		 */
		do_action( 'abilityguard_bulk_rollback_complete', $summary );

		return $summary;
	}

	/**
	 * Build a default-wired instance using real repositories.
	 */
	public static function default(): self {
		return new self( new RollbackService( new LogRepository(), new SnapshotStore() ) );
	}
}
