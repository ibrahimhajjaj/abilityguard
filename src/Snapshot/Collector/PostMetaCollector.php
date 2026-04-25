<?php
/**
 * Post_meta snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Collects + restores post_meta for one or more posts.
 *
 * Spec shape: array<int $post_id, string[] $meta_keys>.
 * Captured shape: array<int $post_id, array<string $key, mixed $value>>.
 */
final class PostMetaCollector implements CollectorInterface {

	/**
	 * Read current meta values.
	 *
	 * @param mixed $spec array<int, string[]>.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}
		$out = array();
		foreach ( $spec as $post_id => $keys ) {
			$post_id = (int) $post_id;
			if ( ! is_array( $keys ) ) {
				continue;
			}
			$out[ $post_id ] = array();
			foreach ( $keys as $key ) {
				$key                     = (string) $key;
				$out[ $post_id ][ $key ] = get_post_meta( $post_id, $key, true );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Restore meta to captured values. Deletes keys that had no prior value.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		foreach ( $captured as $post_id => $meta ) {
			$post_id = (int) $post_id;
			if ( ! is_array( $meta ) ) {
				continue;
			}
			foreach ( $meta as $key => $value ) {
				$key = (string) $key;
				if ( '' === $value || null === $value ) {
					delete_post_meta( $post_id, $key );
					continue;
				}
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
