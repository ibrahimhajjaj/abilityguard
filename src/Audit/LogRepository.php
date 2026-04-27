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

	private const ALLOWED_STATUS = array( 'ok', 'error', 'rolled_back', 'pending', 'rejected' );

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invocation_id = %s", $invocation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * List rows with optional filters.
	 *
	 * Recognised keys in $filters: ability_name, user_id, status, destructive,
	 * caller_id, per_page (1..500, default 50), offset (default 0).
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
		if ( isset( $filters['caller_id'] ) && is_string( $filters['caller_id'] ) && '' !== $filters['caller_id'] ) {
			$where[]  = 'caller_id = %s';
			$params[] = $filters['caller_id'];
		}

		$per_page = isset( $filters['per_page'] ) ? max( 1, min( 500, (int) $filters['per_page'] ) ) : 50;
		$offset   = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find direct children of a parent invocation.
	 *
	 * @param string $parent_invocation_id UUID of the parent invocation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_children( string $parent_invocation_id ): array {
		if ( '' === $parent_invocation_id ) {
			return array();
		}
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE parent_invocation_id = %s ORDER BY id ASC",
				$parent_invocation_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate stats across the audit log.
	 *
	 * Returns a tuple of:
	 *   - counts: status histogram (ok/error/rolled_back/pending/rejected)
	 *   - timings_ms: p50/p95 over duration_ms for finished rows (ok|error|rolled_back)
	 *   - top_abilities: top 10 ability_name by row count (any status)
	 *
	 * Each section is a single query. p95 uses LIMIT/OFFSET because MySQL 5.7
	 * has no PERCENTILE_CONT and we don't want to depend on window functions
	 * (MySQL 8+) given WordPress's supported floor.
	 *
	 * @return array{counts: array<string,int>, timings_ms: array<string,?int>, top_abilities: array<int, array{name:string,count:int}>}
	 */
	public function stats(): array {
		global $wpdb;
		$table = Installer::table( 'log' );

		// Status histogram. GROUP BY is single round-trip and uses the
		// existing KEY status index from Installer::install().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$counts = array(
			'ok'          => 0,
			'error'       => 0,
			'rolled_back' => 0,
			'pending'     => 0,
			'rejected'    => 0,
			'approved'    => 0,
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$status = (string) ( $r['status'] ?? '' );
				if ( '' === $status ) {
					continue;
				}
				$counts[ $status ] = (int) ( $r['c'] ?? 0 );
			}
		}

		// `approved` is not a log status (the log moves pending → ok/error/rolled_back
		// after approval). Source it from the approvals table so the dashboard's
		// "approved" tile reflects approver decisions, not log execution outcome.
		$approvals_table = Installer::table( 'approvals' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts['approved'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$approvals_table} WHERE status = 'approved'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Timings. Restrict to finished executions so pending rows (which carry
		// duration_ms = 0) don't drag p50 to zero on quiet sites.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ('ok','error','rolled_back')"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$p50 = null;
		$p95 = null;
		if ( $total > 0 ) {
			$p50_offset = (int) floor( 0.50 * ( $total - 1 ) );
			$p95_offset = (int) floor( 0.95 * ( $total - 1 ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$p50 = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT duration_ms FROM {$table} WHERE status IN ('ok','error','rolled_back') ORDER BY duration_ms ASC LIMIT 1 OFFSET %d",
					$p50_offset
				)
			);
			$p95 = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT duration_ms FROM {$table} WHERE status IN ('ok','error','rolled_back') ORDER BY duration_ms ASC LIMIT 1 OFFSET %d",
					$p95_offset
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$p50 = null === $p50 ? null : (int) $p50;
			$p95 = null === $p95 ? null : (int) $p95;
		}

		// Top abilities by frequency. KEY ability_name covers the GROUP BY.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$top_rows = $wpdb->get_results(
			"SELECT ability_name, COUNT(*) AS c FROM {$table} GROUP BY ability_name ORDER BY c DESC, ability_name ASC LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$top_abilities = array();
		if ( is_array( $top_rows ) ) {
			foreach ( $top_rows as $r ) {
				$top_abilities[] = array(
					'name'  => (string) ( $r['ability_name'] ?? '' ),
					'count' => (int) ( $r['c'] ?? 0 ),
				);
			}
		}

		return array(
			'counts'        => $counts,
			'timings_ms'    => array(
				'p50' => $p50,
				'p95' => $p95,
			),
			'top_abilities' => $top_abilities,
		);
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
