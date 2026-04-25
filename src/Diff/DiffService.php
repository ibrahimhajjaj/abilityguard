<?php
/**
 * Diff computation between pre- and post-invocation snapshot surfaces.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Diff;

/**
 * Computes a flat diff array from a snapshot row produced by SnapshotStore.
 *
 * Each element of the returned array has the shape:
 *   { surface: string, key: string, before: mixed, after: mixed, changed: bool }
 *
 * Supported surface shapes:
 *   - post_meta : array<int $post_id, array<string $meta_key, mixed $value>>
 *                 flattened to surface = "post_meta · post #{id}", key = meta_key
 *   - options   : array<string $option_name, mixed $value>
 *                 flattened to surface = "options", key = option_name
 *   - any other flat surface: array<string $key, mixed $value>
 */
final class DiffService {

	/**
	 * Compute diff rows from a snapshot row.
	 *
	 * @param array<string, mixed> $snapshot Row from SnapshotStore::find_by_invocation_id().
	 *                                       Expected keys: 'surfaces' (pre-state), 'post_state' (post-state or null).
	 *
	 * @return list<array{ surface: string, key: string, before: mixed, after: mixed, changed: bool }>
	 */
	public function compute( array $snapshot ): array {
		$pre        = is_array( $snapshot['surfaces'] ?? null ) ? $snapshot['surfaces'] : array();
		$post_state = isset( $snapshot['post_state'] ) && is_array( $snapshot['post_state'] )
			? $snapshot['post_state']
			: null;

		$rows = array();

		foreach ( $pre as $surface => $pre_surface ) {
			if ( ! is_array( $pre_surface ) ) {
				continue;
			}

			$post_surface = ( null !== $post_state && isset( $post_state[ $surface ] ) && is_array( $post_state[ $surface ] ) )
				? $post_state[ $surface ]
				: null;

			if ( 'post_meta' === $surface ) {
				foreach ( $pre_surface as $post_id => $meta ) {
					if ( ! is_array( $meta ) ) {
						continue;
					}
					$label     = 'post_meta · post #' . $post_id;
					$post_meta = ( null !== $post_surface && isset( $post_surface[ $post_id ] ) && is_array( $post_surface[ $post_id ] ) )
						? $post_surface[ $post_id ]
						: null;
					foreach ( $meta as $meta_key => $before ) {
						$after  = null !== $post_meta ? ( $post_meta[ $meta_key ] ?? null ) : null;
						$rows[] = array(
							'surface' => $label,
							'key'     => (string) $meta_key,
							'before'  => $before,
							'after'   => $after,
							'changed' => null !== $post_meta && $before !== $after,
						);
					}
				}
				continue;
			}

			// Flat surface (options or custom).
			foreach ( $pre_surface as $key => $before ) {
				$after  = null !== $post_surface ? ( $post_surface[ $key ] ?? null ) : null;
				$rows[] = array(
					'surface' => $surface,
					'key'     => (string) $key,
					'before'  => $before,
					'after'   => $after,
					'changed' => null !== $post_surface && $before !== $after,
				);
			}
		}

		return $rows;
	}
}
