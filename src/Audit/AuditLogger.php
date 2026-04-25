<?php
/**
 * Concrete audit logger backed by $wpdb.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Audit;

use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Installer;

/**
 * Persists invocation rows to the abilityguard_log table.
 */
final class AuditLogger implements AuditLoggerInterface {

	/**
	 * Insert a log row.
	 *
	 * @param array<string, mixed> $entry See AbilityWrapper for keys.
	 *
	 * @return int Inserted log id (0 on failure).
	 */
	public function log( array $entry ): int {
		global $wpdb;
		$table = Installer::table( 'log' );

		$caller_id = isset( $entry['caller_id'] ) && is_string( $entry['caller_id'] ) && '' !== $entry['caller_id']
			? $entry['caller_id']
			: null;

		$row     = array(
			'invocation_id' => (string) $entry['invocation_id'],
			'ability_name'  => (string) $entry['ability_name'],
			'caller_type'   => (string) ( $entry['caller_type'] ?? 'internal' ),
			'caller_id'     => $caller_id,
			'user_id'       => (int) ( $entry['user_id'] ?? 0 ),
			'args_json'     => $entry['args_json'] ?? null,
			'result_json'   => $entry['result_json'] ?? null,
			'status'        => (string) ( $entry['status'] ?? 'ok' ),
			'destructive'   => ! empty( $entry['destructive'] ) ? 1 : 0,
			'duration_ms'   => (int) ( $entry['duration_ms'] ?? 0 ),
			'pre_hash'      => $entry['pre_hash'] ?? null,
			'post_hash'     => $entry['post_hash'] ?? null,
			'snapshot_id'   => isset( $entry['snapshot_id'] ) ? (int) $entry['snapshot_id'] : null,
			'created_at'    => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $table, $row, $formats );
		if ( ! $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}
}
