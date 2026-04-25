<?php
/**
 * Files snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Records file state (existence, hash, size, mtime) for a list of absolute paths.
 *
 * Files are not restored. This collector exists so the audit log can record file
 * changes; v0.3 may implement actual file rollback.
 *
 * Spec shape:    string[] $absolute_paths.
 * Captured shape: array<string $path, array{ exists: bool, sha256: ?string, size: ?int, mtime: ?int }>.
 */
final class FilesCollector implements CollectorInterface {

	/**
	 * Record current state of the given file paths.
	 *
	 * @param mixed $spec string[] of absolute paths.
	 *
	 * @return array<string, array{exists: bool, sha256: ?string, size: ?int, mtime: ?int}>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}

		$out = array();

		foreach ( $spec as $path ) {
			$path = (string) $path;

			if ( ! file_exists( $path ) ) {
				$out[ $path ] = array(
					'exists' => false,
					'sha256' => null,
					'size'   => null,
					'mtime'  => null,
				);
				continue;
			}

			$hash = hash_file( 'sha256', $path );
			$stat = stat( $path );

			$out[ $path ] = array(
				'exists' => true,
				'sha256' => false !== $hash ? $hash : null,
				'size'   => ( false !== $stat ) ? (int) $stat['size'] : null,
				'mtime'  => ( false !== $stat ) ? (int) $stat['mtime'] : null,
			);
		}

		ksort( $out );

		return $out;
	}

	/**
	 * No-op: files are not restored in v0.2.
	 *
	 * Fires 'abilityguard_files_changed_since_snapshot' with an array of paths
	 * whose sha256 hash differs from the captured state.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		$changed_paths = array();

		foreach ( $captured as $path => $state ) {
			$path = (string) $path;

			if ( ! is_array( $state ) ) {
				continue;
			}

			$current = $this->collect( array( $path ) );

			if ( ! isset( $current[ $path ] ) ) {
				continue;
			}

			$captured_hash = isset( $state['sha256'] ) ? $state['sha256'] : null;
			$current_hash  = $current[ $path ]['sha256'];

			if ( $captured_hash !== $current_hash ) {
				$changed_paths[] = $path;
			}
		}

		do_action( 'abilityguard_files_changed_since_snapshot', $changed_paths );
	}
}
