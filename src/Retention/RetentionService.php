<?php
/**
 * Prunes stale log rows and orphaned snapshots.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Retention;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\FileBlobStore;

/**
 * Deletes expired log rows (normal / destructive) and orphaned snapshot rows.
 */
final class RetentionService {

	/**
	 * Number of days to keep non-destructive log rows.
	 *
	 * 0 means "never prune".
	 */
	public function retention_days_normal(): int {
		return (int) apply_filters( 'abilityguard_retention_days_normal', 30 );
	}

	/**
	 * Number of days to keep destructive log rows.
	 *
	 * 0 means "never prune".
	 */
	public function retention_days_destructive(): int {
		return (int) apply_filters( 'abilityguard_retention_days_destructive', 180 );
	}

	/**
	 * Run all pruning operations.
	 *
	 * @return array{ logs_deleted: int, snapshots_deleted: int, blobs_deleted: int }
	 */
	public function prune(): array {
		global $wpdb;

		$log       = Installer::table( 'log' );
		$snapshots = Installer::table( 'snapshots' );

		$logs_deleted = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Table names come from Installer::table() and are not user input.
		$days_normal = $this->retention_days_normal();
		if ( $days_normal > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log} WHERE destructive = 0 AND created_at < NOW() - INTERVAL %d DAY",
					$days_normal
				)
			);
			$logs_deleted += (int) $wpdb->rows_affected;
		}

		$days_destructive = $this->retention_days_destructive();
		if ( $days_destructive > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log} WHERE destructive = 1 AND created_at < NOW() - INTERVAL %d DAY",
					$days_destructive
				)
			);
			$logs_deleted += (int) $wpdb->rows_affected;
		}

		$wpdb->query(
			"DELETE s FROM {$snapshots} s LEFT JOIN {$log} l ON s.invocation_id = l.invocation_id WHERE l.invocation_id IS NULL"
		);
		$snapshots_deleted = (int) $wpdb->rows_affected;

		// Walk the surviving snapshots' surfaces_json and collect every
		// blob hash referenced by a still-live snapshot. Anything in the
		// staging dir not in that set is orphaned and gets unlinked.
		$keep_hashes = $this->collect_referenced_blob_hashes();
		// phpcs:enable

		$blobs_deleted = FileBlobStore::prune_except( $keep_hashes );

		// Pin the last-prune state so the admin dashboard shows when the
		// retention cron last ran and how much it cleared. Without this the
		// `/retention` endpoint reports "never" forever, which the widget
		// then renders verbatim.
		update_option( 'abilityguard_last_pruned', current_time( 'mysql', true ), false );
		update_option( 'abilityguard_last_pruned_count', $logs_deleted + $snapshots_deleted + $blobs_deleted, false );

		return array(
			'logs_deleted'      => $logs_deleted,
			'snapshots_deleted' => $snapshots_deleted,
			'blobs_deleted'     => $blobs_deleted,
		);
	}

	/**
	 * Walk every surviving snapshot row and return the set of blob hashes
	 * its files-surface entries reference. Used to build the keep-set for
	 * `FileBlobStore::prune_except()` so orphaned content blobs get GC'd.
	 *
	 * Quietly tolerates malformed JSON / unexpected shapes - the cost of a
	 * false negative here is one extra blob on disk, not data loss.
	 *
	 * @return array<int, string>
	 */
	private function collect_referenced_blob_hashes(): array {
		global $wpdb;
		$snapshots = Installer::table( 'snapshots' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_col( "SELECT surfaces_json FROM {$snapshots}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$hashes = array();
		foreach ( $rows as $blob ) {
			if ( ! is_string( $blob ) || '' === $blob ) {
				continue;
			}
			$inflated = @gzdecode( $blob );
			$payload  = is_string( $inflated ) ? $inflated : $blob;
			$decoded  = json_decode( $payload, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
				continue;
			}
			foreach ( $decoded['files'] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['blob'] ) && is_string( $entry['blob'] ) && '' !== $entry['blob'] ) {
					$hashes[] = $entry['blob'];
				}
			}
		}
		return array_values( array_unique( $hashes ) );
	}
}
