<?php
/**
 * Snapshot persistence layer.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot;

use AbilityGuard\Installer;
use AbilityGuard\Support\Json;

/**
 * INSERT / SELECT against the abilityguard_snapshots table.
 */
class SnapshotStore {

	/**
	 * Persist a snapshot row.
	 *
	 * @param string               $invocation_id UUID.
	 * @param array<string, mixed> $surfaces      Captured surfaces (post_meta, options, ...).
	 * @param string               $pre_hash      Stable hash of $surfaces.
	 *
	 * @return int|null Inserted row id, or null on failure.
	 */
	public function insert( string $invocation_id, array $surfaces, string $pre_hash ): ?int {
		global $wpdb;
		$table  = Installer::table( 'snapshots' );
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'invocation_id' => $invocation_id,
				'surfaces_json' => Json::encode_compressed( $surfaces ),
				'pre_hash'      => $pre_hash,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		if ( ! $result ) {
			return null;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Write post_state_json on an existing snapshot row.
	 *
	 * @param int                  $snapshot_id Row id returned by insert().
	 * @param array<string, mixed> $surfaces    Post-invocation surfaces to persist.
	 *
	 * @return bool True on success.
	 */
	public function update_post_state( int $snapshot_id, array $surfaces ): bool {
		global $wpdb;
		$table  = Installer::table( 'snapshots' );
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'post_state_json' => Json::encode_compressed( $surfaces ) ),
			array( 'id' => $snapshot_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Find a snapshot by invocation id.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return array{ id: int, invocation_id: string, surfaces: array<string, mixed>, pre_hash: string, post_state: array<string, mixed>|null }|null
	 */
	public function find_by_invocation_id( string $invocation_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'snapshots' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, invocation_id, surfaces_json, pre_hash, post_state_json FROM {$table} WHERE invocation_id = %s", $invocation_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$post_state = null;
		if ( null !== $row['post_state_json'] && '' !== $row['post_state_json'] ) {
			$post_state = Json::decode_compressed( (string) $row['post_state_json'] );
		}
		return array(
			'id'            => (int) $row['id'],
			'invocation_id' => (string) $row['invocation_id'],
			'surfaces'      => Json::decode_compressed( (string) $row['surfaces_json'] ),
			'pre_hash'      => (string) $row['pre_hash'],
			'post_state'    => $post_state,
		);
	}
}
