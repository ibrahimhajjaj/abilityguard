<?php
/**
 * Helper for writing/reading rows in the abilityguard_log_meta table.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Audit;

use AbilityGuard\Installer;

/**
 * Thin wrapper around the log_meta key/value table. Lets the rest of the
 * plugin attach extension data (skip_drift_check flags, files-changed
 * surfaces, future correlation metadata) to a log row without having to
 * grow the log table itself.
 */
final class LogMeta {

	/**
	 * Insert a meta row keyed to a log row.
	 *
	 * Values longer than longtext aren't validated here - callers are
	 * responsible for keeping them small.
	 *
	 * @param int    $log_id     Log row id (must already exist).
	 * @param string $meta_key   Short identifier (≤191 chars).
	 * @param string $meta_value Stringified value. Encode JSON yourself.
	 */
	public static function set( int $log_id, string $meta_key, string $meta_value ): void {
		if ( $log_id <= 0 || '' === $meta_key ) {
			return;
		}
		global $wpdb;
		$table = Installer::table( 'log_meta' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'log_id'     => $log_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Read all meta values for a key on a log row, oldest-first.
	 *
	 * @param int    $log_id   Log row id.
	 * @param string $meta_key Key to read.
	 *
	 * @return string[]
	 */
	public static function get_all( int $log_id, string $meta_key ): array {
		if ( $log_id <= 0 || '' === $meta_key ) {
			return array();
		}
		global $wpdb;
		$table = Installer::table( 'log_meta' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE log_id = %d AND meta_key = %s ORDER BY id ASC",
				$log_id,
				$meta_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', is_array( $rows ) ? $rows : array() );
	}
}
