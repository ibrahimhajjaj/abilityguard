<?php
/**
 * Read-side query API for the audit log.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Audit;

use AbilityGuard\Installer;

/**
 * Lookup + listing helpers used by wp-cli, REST, and the admin list table.
 */
class LogRepository {

	private const ALLOWED_STATUS = array( 'ok', 'error', 'rolled_back', 'pending' );

	/**
	 * Find a row by primary key.
	 *
	 * @param int $id Log id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a row by invocation uuid.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_invocation_id( string $invocation_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invocation_id = %s", $invocation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * List rows with optional filters.
	 *
	 * Recognised keys in $filters: ability_name, user_id, status, destructive,
	 * per_page (1..500, default 50), offset (default 0).
	 *
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;
		$table = Installer::table( 'log' );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['ability_name'] ) ) {
			$where[]  = 'ability_name = %s';
			$params[] = (string) $filters['ability_name'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::ALLOWED_STATUS, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( isset( $filters['destructive'] ) ) {
			$where[]  = 'destructive = %d';
			$params[] = $filters['destructive'] ? 1 : 0;
		}

		$per_page = isset( $filters['per_page'] ) ? max( 1, min( 500, (int) $filters['per_page'] ) ) : 50;
		$offset   = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update the status field on a row.
	 *
	 * @param int    $id     Log id.
	 * @param string $status New status (one of ok|error|rolled_back|pending).
	 *
	 * @return bool
	 */
	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, self::ALLOWED_STATUS, true ) ) {
			return false;
		}
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return false !== $ok;
	}
}
