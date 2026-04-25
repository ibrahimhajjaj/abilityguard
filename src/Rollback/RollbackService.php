<?php
/**
 * One-click rollback for an invocation.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Rollback;

use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\CollectorInterface;
use AbilityGuard\Snapshot\Collector\CollectorRegistry;
use AbilityGuard\Snapshot\SnapshotStore;
use AbilityGuard\Support\Cipher;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Redactor;
use RuntimeException;
use WP_Error;

/**
 * Reverses a recorded invocation by restoring each captured surface.
 *
 * ### Drift-check behaviour (v0.3)
 *
 * Before restoring each surface, `rollback()` re-runs the collector against
 * the same spec implied by the captured data and compares the live hash to
 * the captured hash. If they differ the surface has "drifted".
 *
 * - No drift → restores as before (returns true).
 * - Drift, force=false → returns WP_Error('abilityguard_rollback_drift')
 *   and restores NOTHING; log status is NOT updated to rolled_back.
 * - Drift, force=true → fires 'abilityguard_rollback_drift' action for
 *   observability and restores ALL surfaces regardless.
 *
 * ### Per-ability opt-out
 *
 * A registration can set `safety.skip_drift_check = true` to bypass the
 * check entirely. When bypassed the drift action still fires for audit
 * purposes (if drift was present).
 *
 * ### Action signatures
 *
 * do_action( 'abilityguard_rollback_drift', $log, $snapshot, $drifted_surfaces )
 *   Fired whenever drift is detected (even when skipped or forced).
 *   - $drifted_surfaces: string[] of surface names that drifted.
 *
 * do_action( 'abilityguard_rollback', $log, $snapshot, $drifted_surfaces )
 *   Fired after a successful restore.
 *   - $drifted_surfaces: string[] (empty when no drift occurred).
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
	) {}

	/**
	 * Resolve the active collector map, registry-aware. See SnapshotService.
	 *
	 * @return array<string, CollectorInterface>
	 */
	private function active_collectors(): array {
		return null !== $this->collectors ? $this->collectors : CollectorRegistry::defaults();
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
	 * @param bool       $force     When true, drift is ignored AND redacted skips are tolerated; restore proceeds.
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

		// --- Drift check -------------------------------------------------------
		// Compare live state to the snapshot's POST-state (what the ability left
		// behind), not the pre-state. The ability's own mutation isn't drift -
		// drift means "something ELSE changed state after the ability ran."
		// If post_state is missing (pre-v0.3 snapshot, or callback threw), skip
		// the check; we have no baseline to compare against.
		$skip_drift       = $this->should_skip_drift( $log );
		$drifted_surfaces = array();
		$post_state       = is_array( $snapshot['post_state'] ?? null ) ? $snapshot['post_state'] : null;

		$collectors = $this->active_collectors();

		if ( null !== $post_state ) {
			foreach ( $snapshot['surfaces'] as $surface => $captured ) {
				if ( ! isset( $collectors[ $surface ] ) || ! is_array( $captured ) ) {
					continue;
				}
				if ( ! isset( $post_state[ $surface ] ) || ! is_array( $post_state[ $surface ] ) ) {
					continue;
				}

				$spec     = $this->derive_spec( $surface, $captured );
				$current  = $collectors[ $surface ]->collect( $spec );
				$expected = $post_state[ $surface ];

				if ( Hash::stable( $current ) !== Hash::stable( $expected ) ) {
					$drifted_surfaces[] = $surface;
				}
			}
		}

		if ( ! empty( $drifted_surfaces ) ) {
			do_action( 'abilityguard_rollback_drift', $log, $snapshot, $drifted_surfaces );
		}

		if ( ! empty( $drifted_surfaces ) && ! $force && ! $skip_drift ) {
			return new WP_Error(
				'abilityguard_rollback_drift',
				sprintf(
					'Rollback aborted: live state has drifted on surface(s): %s. Use force=true to override.',
					implode( ', ', $drifted_surfaces )
				),
				array(
					'drifted_surfaces'  => $drifted_surfaces,
					'restored_surfaces' => array(),
				)
			);
		}

		// --- Restore (decrypt envelopes; skip v0.3 sentinels) ----------------
		// Capture file-change surfacing emitted by FilesCollector::restore().
		// FilesCollector doesn't actually rewrite files; it only fires this
		// action with paths whose live state diverged from the snapshot. We
		// pin those onto the log row so the audit trail reflects the drift.
		$log_id_for_meta     = (int) ( $log['id'] ?? 0 );
		$files_listener_fn   = static function ( array $changed_paths ) use ( $log_id_for_meta ): void {
			if ( $log_id_for_meta <= 0 || array() === $changed_paths ) {
				return;
			}
			LogMeta::set(
				$log_id_for_meta,
				'files_changed_on_rollback',
				(string) wp_json_encode( array_values( $changed_paths ) )
			);
		};
		$deleted_listener_fn = static function ( array $deleted_paths ) use ( $log_id_for_meta ): void {
			if ( $log_id_for_meta <= 0 || array() === $deleted_paths ) {
				return;
			}
			LogMeta::set(
				$log_id_for_meta,
				'files_deleted_on_rollback',
				(string) wp_json_encode( array_values( $deleted_paths ) )
			);
		};
		add_action( 'abilityguard_files_changed_since_snapshot', $files_listener_fn );
		add_action( 'abilityguard_files_deleted_since_snapshot', $deleted_listener_fn );

		$skipped = array(); // Keys not restored due to failed decryption or v0.3 sentinel.
		foreach ( $snapshot['surfaces'] as $surface => $captured ) {
			if ( ! isset( $collectors[ $surface ] ) || ! is_array( $captured ) ) {
				continue;
			}

			$restorable = array();
			foreach ( $captured as $key => $entry ) {
				if ( Cipher::is_envelope( $entry ) ) {
					// v0.4 encrypted envelope: decrypt before restoring.
					try {
						$restorable[ $key ] = Cipher::decrypt( $entry );
					} catch ( RuntimeException $e ) {
						// Key rotated or tampered - skip and report.
						$skipped[] = "{$surface}.{$key}";
					}
				} elseif ( $this->surface_entry_is_redacted( $entry ) ) {
					// v0.3 plain sentinel - cannot restore.
					$skipped[] = "{$surface}.{$key}";
				} else {
					$restorable[ $key ] = $entry;
				}
			}

			if ( array() !== $restorable ) {
				$collectors[ $surface ]->restore( $restorable );
			}
		}

		remove_action( 'abilityguard_files_changed_since_snapshot', $files_listener_fn );
		remove_action( 'abilityguard_files_deleted_since_snapshot', $deleted_listener_fn );

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

		/**
		 * Fires after a successful rollback.
		 *
		 * @param array<string, mixed> $log              Audit log row.
		 * @param array<string, mixed> $snapshot         Snapshot row.
		 * @param string[]             $drifted_surfaces Surfaces that drifted (empty when no drift).
		 */
		do_action( 'abilityguard_rollback', $log, $snapshot, $drifted_surfaces );

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

	/**
	 * Derive the collector spec from the captured payload.
	 *
	 * Each collector's spec mirrors the keys/structure of its captured output:
	 * - post_meta: array<int, string[]>  (post_id => meta_key[]).
	 * - options:   string[]              (option names).
	 * - All other surfaces: the captured array is passed back as-is (the
	 *   collectors that implement derive-from-capture are responsible for
	 *   handling the shape; unknown surfaces return the captured data unchanged
	 *   so collect() can use it as a spec if supported).
	 *
	 * @param string       $surface  Surface name.
	 * @param array<mixed> $captured Captured payload from the snapshot.
	 *
	 * @return mixed
	 */
	private function derive_spec( string $surface, array $captured ): mixed {
		switch ( $surface ) {
			case 'post_meta':
				// Spec: array<int, string[]>. Captured: array<int, array<string, mixed>>.
				$spec = array();
				foreach ( $captured as $post_id => $meta ) {
					if ( is_array( $meta ) ) {
						$spec[ (int) $post_id ] = array_keys( $meta );
					}
				}
				return $spec;

			case 'options':
				// Spec: string[]. Captured: array<string, mixed>.
				return array_keys( $captured );

			case 'files':
				// Spec: string[] of absolute paths. Captured keys ARE the paths.
				return array_keys( $captured );

			case 'taxonomy':
			case 'user_role':
				// Captured shape is the natural spec for these (collectors read
				// it back as a structured map).
				return $captured;

			default:
				// Custom (safety.collectors) surfaces. We don't know the spec
				// shape the registered collector originally received, so the
				// safest choice is to pass the captured top-level keys back.
				// Collectors that capture by id (string[] / int[]) can re-read
				// live state from those keys; collectors that need richer spec
				// information should construct their own SnapshotService rather
				// than rely on safety.collectors + drift detection.
				return is_array( $captured ) ? array_keys( $captured ) : array();
		}
	}

	/**
	 * Determine whether drift checking should be skipped for this invocation.
	 *
	 * Reads the `skip_drift_check` meta value from the abilityguard_log_meta
	 * table. Falls back to false (always check) when not present.
	 *
	 * @param array<string, mixed> $log Log row.
	 *
	 * @return bool
	 */
	private function should_skip_drift( array $log ): bool {
		global $wpdb;
		$log_id = isset( $log['id'] ) ? (int) $log['id'] : 0;
		if ( 0 === $log_id ) {
			return false;
		}
		$table = Installer::table( 'log_meta' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE log_id = %d AND meta_key = %s LIMIT 1",
				$log_id,
				'skip_drift_check'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return '1' === $value || 'true' === $value;
	}
}
