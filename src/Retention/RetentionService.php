<?php
/**
 * Prunes stale log rows and orphaned snapshots.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Retention;

use AbilityGuard\Installer;

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
	 * @return array{ logs_deleted: int, snapshots_deleted: int }
	 */
	public function prune(): array {
		global $wpdb;

		$log       = Installer::table( 'log' );
		$snapshots = Installer::table( 'snapshots' );

		$logs_deleted = 0;

		// Prune non-destructive rows.
		$days_normal = $this->retention_days_normal();
		if ( $days_normal > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log} WHERE destructive = 0 AND created_at < NOW() - INTERVAL %d DAY",
					$days_normal
				)
			);
			$logs_deleted += (int) $wpdb->rows_affected;
		}

		// Prune destructive rows.
		$days_destructive = $this->retention_days_destructive();
		if ( $days_destructive > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$log} WHERE destructive = 1 AND created_at < NOW() - INTERVAL %d DAY",
					$days_destructive
				)
			);
			$logs_deleted += (int) $wpdb->rows_affected;
		}

		// Prune orphaned snapshots (invocation_id no longer in log table).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE s FROM {$snapshots} s LEFT JOIN {$log} l ON s.invocation_id = l.invocation_id WHERE l.invocation_id IS NULL"
		);
		$snapshots_deleted = (int) $wpdb->rows_affected;

		return array(
			'logs_deleted'      => $logs_deleted,
			'snapshots_deleted' => $snapshots_deleted,
		);
	}
}
