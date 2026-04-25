<?php
/**
 * Read-side query API for the approval queue.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Approval;

use AbilityGuard\Installer;

/**
 * Lookup + listing helpers for the wp_abilityguard_approvals table.
 */
class ApprovalRepository {

	private const ALLOWED_STATUS = array( 'pending', 'approved', 'rejected' );

	/**
	 * Find a row by primary key.
	 *
	 * @param int $id Approval id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Installer::table( 'approvals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find a row by log id.
	 *
	 * @param int $log_id Log id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_log_id( int $log_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'approvals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE log_id = %d ORDER BY id DESC LIMIT 1", $log_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * List rows with optional filters.
	 *
	 * Recognised keys in $filters: status, ability_name, requested_by,
	 * per_page (1..500, default 50), offset (default 0).
	 *
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;
		$table = Installer::table( 'approvals' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::ALLOWED_STATUS, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['ability_name'] ) ) {
			$where[]  = 'ability_name = %s';
			$params[] = (string) $filters['ability_name'];
		}
		if ( ! empty( $filters['requested_by'] ) ) {
			$where[]  = 'requested_by = %d';
			$params[] = (int) $filters['requested_by'];
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
}
