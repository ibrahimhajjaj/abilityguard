<?php
/**
 * Taxonomy snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Collects + restores taxonomy term assignments for one or more posts.
 *
 * Spec shape:    array<int $post_id, string[] $taxonomy_names>.
 * Captured shape: array<int $post_id, array<string $taxonomy, int[] $term_ids>>.
 */
final class TaxonomyCollector implements CollectorInterface {

	/**
	 * Read current term assignments.
	 *
	 * @param mixed $spec array<int, string[]>.
	 *
	 * @return array<int, array<string, int[]>>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}

		$out = array();

		foreach ( $spec as $post_id => $taxonomies ) {
			$post_id = (int) $post_id;
			if ( ! is_array( $taxonomies ) ) {
				continue;
			}

			$out[ $post_id ] = array();

			foreach ( $taxonomies as $taxonomy ) {
				$taxonomy = (string) $taxonomy;
				$terms    = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

				if ( is_wp_error( $terms ) ) {
					continue;
				}

				$term_ids = array_map( 'intval', $terms );
				sort( $term_ids );

				$out[ $post_id ][ $taxonomy ] = $term_ids;
			}

			ksort( $out[ $post_id ] );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Restore term assignments to captured state.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		foreach ( $captured as $post_id => $taxonomies ) {
			$post_id = (int) $post_id;
			if ( ! is_array( $taxonomies ) ) {
				continue;
			}

			foreach ( $taxonomies as $taxonomy => $term_ids ) {
				$taxonomy = (string) $taxonomy;
				if ( ! is_array( $term_ids ) ) {
					continue;
				}

				$term_ids = array_map( 'intval', $term_ids );
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}
}
