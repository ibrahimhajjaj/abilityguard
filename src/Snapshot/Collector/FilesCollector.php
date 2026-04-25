<?php
/**
 * Files snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

/**
 * Records file state (existence, optional hash, size, mtime) for a list of absolute paths.
 *
 * Files are not restored. This collector exists so the audit log can record file
 * changes; a future major version may implement actual file rollback.
 *
 * ## Detection strategies
 *
 * Choose how aggressively the collector compares captured vs current state.
 * Each tier is progressively more expensive; pick based on file count and
 * threat model.
 *
 *  - 'mtime'         - modification time only (fastest, misses content
 *                      edits that preserve mtime: rsync without -t, restored
 *                      files, intentional manipulation via `touch -d`).
 *  - 'mtime_size'    - mtime first, then filesize as a tie-breaker. Catches
 *                      most rsync/touch attacks without per-byte hashing.
 *  - 'critical_hash' - mtime + size; sha256 ONLY for paths matching a
 *                      "critical" allowlist (filter:
 *                      `abilityguard_files_critical_paths`). Default critical
 *                      list: any path ending in `wp-config.php`, `.env`,
 *                      `.htaccess`, or under `wp-includes/`.
 *  - 'full_hash'     - sha256 every path. Most accurate, slowest. Default
 *                      for back-compat with v0.2-v0.5.
 *
 * Override globally via `apply_filters( 'abilityguard_files_default_strategy', 'full_hash' )`.
 *
 * ## Spec shapes
 *
 * The collector accepts either:
 *
 *   string[] $absolute_paths                                                    // legacy, defaults to full_hash
 *
 * or:
 *
 *   array{ paths: string[], strategy?: string, exclude_dirs?: string[] }        // explicit
 *
 * ## Captured shape
 *
 *   array<string $path, array{
 *     exists: bool,
 *     sha256: ?string,    // null when strategy didn't compute it (or file missing)
 *     size:   ?int,
 *     mtime:  ?int,
 *   }>
 *
 * ## Symlink loop guard
 *
 * If a path resolves to a directory that's actually a symlink, the directory
 * walk skips it. The collector itself processes individual paths, but if
 * callers later expand directories into paths via filters, this guard fires.
 */
final class FilesCollector implements CollectorInterface {

	/**
	 * Strategy values accepted by the collector.
	 */
	public const STRATEGY_MTIME         = 'mtime';
	public const STRATEGY_MTIME_SIZE    = 'mtime_size';
	public const STRATEGY_CRITICAL_HASH = 'critical_hash';
	public const STRATEGY_FULL_HASH     = 'full_hash';
	public const STRATEGY_FULL_CONTENT  = 'full_content';

	/**
	 * Hard byte cap per captured file body under STRATEGY_FULL_CONTENT.
	 * Files larger than this fall back to fingerprint-only with a notice.
	 *
	 * Filterable via `abilityguard_max_file_bytes`.
	 */
	private const DEFAULT_MAX_FILE_BYTES = 262144; // 256 KB

	/**
	 * Third-party archive directories we exclude by default - backup-plugin
	 * archives that callers almost never mean to snapshot. Plugin authors
	 * can extend via `safety.snapshot.files.exclude_dirs` or the
	 * `abilityguard_files_default_exclude_dirs` filter.
	 *
	 * @var string[]
	 */
	private const BACKUP_PLUGIN_EXCLUDES = array(
		'/safeguard-backups/',
		'/safeguard-cache/',
		'/updraft/',
		'/backupbuddy_backups/',
		'/ai1wm-backups/',
		'/wpvivid/',
		'/backwpup-',
		'/duplicator/',
		'/.git/',
		'/node_modules/',
	);

	// Default critical suffixes now live on CriticalFileRegistry::all().

	/**
	 * Read current state of the given file paths.
	 *
	 * `paths` may be a string[] OR any Traversable yielding strings -
	 * generators are accepted so callers with very large path universes
	 * (50k+ files) can stream rather than materialise the whole list in
	 * memory before the collector sees it.
	 *
	 * @param mixed $spec string[] of absolute paths OR an array with keys
	 *                    `paths`, optional `strategy`, optional `exclude_dirs`.
	 *                    `paths` may also be a Traversable.
	 *
	 * @return array<string, array{exists: bool, sha256: ?string, size: ?int, mtime: ?int, mode?: ?int, blob?: ?string}>
	 */
	public function collect( $spec ): array {
		[ $paths, $strategy, $exclude_dirs ] = $this->resolve_spec( $spec );

		$out = array();

		foreach ( $paths as $path ) {
			$path = (string) $path;

			if ( $this->path_excluded( $path, $exclude_dirs ) ) {
				continue;
			}

			if ( ! file_exists( $path ) ) {
				$out[ $path ] = array(
					'exists' => false,
					'sha256' => null,
					'size'   => null,
					'mtime'  => null,
				);
				continue;
			}

			// Symlink loop guard: if the path is a symlink to a directory, skip.
			// The collector processes files; symlinked directories shouldn't
			// land here in normal usage, but the guard prevents infinite loops
			// if a caller expands a directory into paths.
			if ( is_link( $path ) && is_dir( $path ) ) {
				continue;
			}

			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- stat/hash on missing or unreadable files returns false; warnings are noise.
			$stat = @stat( $path );
			$size = ( false !== $stat ) ? (int) $stat['size'] : null;
			$mt   = ( false !== $stat ) ? (int) $stat['mtime'] : null;
			$mode = ( false !== $stat ) ? ( (int) $stat['mode'] & 0777 ) : null;

			$hash = null;
			if ( $this->should_hash( $path, $strategy ) ) {
				$h    = @hash_file( 'sha256', $path );
				$hash = ( false !== $h ) ? $h : null;
			}

			$blob = null;
			if ( self::STRATEGY_FULL_CONTENT === $strategy ) {
				$max_bytes = $this->max_file_bytes();
				if ( null !== $size && $size > $max_bytes ) {
					// Oversize: behave like a fingerprint-only capture and
					// nudge the developer. Don't error - a 200 MB file
					// shouldn't poison the whole snapshot.
					$this->doing_it_wrong_oversize( $path, $size, $max_bytes );
				} else {
					$bytes = @file_get_contents( $path );
					if ( false !== $bytes ) {
						try {
							$blob = \AbilityGuard\Snapshot\FileBlobStore::put( $bytes );
							if ( null === $hash ) {
								$hash = hash( 'sha256', $bytes );
							}
						} catch ( \RuntimeException $e ) {
							// Encryption / atomic-write failed - keep snapshotting,
							// drop content; rollback will fall back to drift action.
							$blob = null;
						}
					}
				}
			}
			// phpcs:enable

			$entry = array(
				'exists' => true,
				'sha256' => $hash,
				'size'   => $size,
				'mtime'  => $mt,
			);
			if ( null !== $mode ) {
				$entry['mode'] = $mode;
			}
			if ( null !== $blob ) {
				$entry['blob'] = $blob;
			}
			$out[ $path ] = $entry;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Restore captured file state.
	 *
	 * For entries captured under STRATEGY_FULL_CONTENT (i.e. carrying a
	 * `blob` hash), this rewrites the file with the captured bytes via
	 * temp-file + rename, restores the captured octal mode, and re-creates
	 * deleted files that have a stored blob. Entries without a blob hash
	 * keep the previous behavior - fire the changed/deleted actions and
	 * leave the filesystem alone.
	 *
	 * Path safety: every target path is validated against directory
	 * traversal, null bytes, and (when the directory exists) realpath
	 * containment within ABSPATH. Anything that fails validation is
	 * skipped silently - a malicious snapshot row should never be able to
	 * coerce a write outside the install.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		$changed_paths  = array();
		$deleted_paths  = array();
		$restored_paths = array();

		foreach ( $captured as $path => $state ) {
			$path = (string) $path;

			if ( ! is_array( $state ) ) {
				continue;
			}

			$current    = $this->collect( array( $path ) );
			$has_change = ! isset( $current[ $path ] ) || $this->state_differs( $state, $current[ $path ] );

			if ( ! $has_change ) {
				continue;
			}

			$changed_paths[] = $path;

			$cap_exists = ! empty( $state['exists'] );
			$cur_exists = ! empty( $current[ $path ]['exists'] ?? false );
			if ( $cap_exists && ! $cur_exists ) {
				$deleted_paths[] = $path;
			}

			// Real content restore: only when a blob hash was captured.
			$blob_hash = isset( $state['blob'] ) && is_string( $state['blob'] ) ? $state['blob'] : '';
			if ( '' === $blob_hash ) {
				continue;
			}

			if ( ! $this->is_safe_path( $path ) ) {
				continue;
			}

			$bytes = \AbilityGuard\Snapshot\FileBlobStore::get( $blob_hash );
			if ( null === $bytes ) {
				// Blob missing or tamper-detected; treat as drift.
				continue;
			}

			if ( $this->write_atomic( $path, $bytes, $state['mode'] ?? null ) ) {
				$restored_paths[] = $path;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		do_action( 'abilityguard_files_changed_since_snapshot', $changed_paths );

		if ( array() !== $deleted_paths ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			do_action( 'abilityguard_files_deleted_since_snapshot', $deleted_paths );
		}

		if ( array() !== $restored_paths ) {
			/**
			 * Fires after FilesCollector successfully rewrote one or more
			 * files from a STRATEGY_FULL_CONTENT capture. Emitted in
			 * addition to the generic changed/deleted actions so listeners
			 * can distinguish "we restored bytes" from "drift was detected".
			 *
			 * @since 0.9
			 *
			 * @param string[] $restored_paths Absolute paths just rewritten.
			 */
			do_action( 'abilityguard_files_restored', $restored_paths );
		}
	}

	/**
	 * Atomic write: temp file in the same directory, then rename. Restores
	 * the captured mode if provided. Returns true on success.
	 *
	 * @param string   $path  Absolute target path.
	 * @param string   $bytes File body to write.
	 * @param int|null $mode  Captured octal mode (e.g. 0644). Skipped when null.
	 */
	private function write_atomic( string $path, string $bytes, $mode ): bool {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = $path . '.ag-restore-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			return false;
		}
		if ( ! rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			return false;
		}
		if ( null !== $mode ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring captured octal mode is the point; chmod() failure is non-fatal here.
			@chmod( $path, ( (int) $mode ) & 0777 );
		}
		return true;
	}

	/**
	 * Path validation: reject `..`, null bytes, and confirm the resolved
	 * target stays within ABSPATH. Defends against a malicious snapshot
	 * row coercing a write outside the install during restore.
	 *
	 * @param string $path Absolute path to validate.
	 */
	private function is_safe_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}
		if ( preg_match( '#(?:^|/|\\\\)\.\.(?:/|\\\\|$)#', $path ) ) {
			return false;
		}
		// realpath() the parent dir; the file itself may not exist (deletion-restore).
		$dir      = dirname( $path );
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			// Parent dir absent - verify the requested ABSPATH ancestor
			// segment is real and contains the parent.
			$abspath = realpath( ABSPATH );
			return is_string( $abspath ) && 0 === strpos( $dir, $abspath );
		}
		$abspath = realpath( ABSPATH );
		if ( false === $abspath ) {
			return false;
		}
		return 0 === strpos( $real_dir, $abspath );
	}

	/**
	 * Resolve the configured per-file byte cap.
	 */
	private function max_file_bytes(): int {
		$default = self::DEFAULT_MAX_FILE_BYTES;
		if ( ! function_exists( 'apply_filters' ) ) {
			return $default;
		}
		$filtered = (int) apply_filters( 'abilityguard_max_file_bytes', $default );
		return $filtered > 0 ? $filtered : $default;
	}

	/**
	 * Surface a doing-it-wrong notice when a file exceeds the per-file cap.
	 *
	 * @param string $path      Path that overshot the cap.
	 * @param int    $size      Actual size in bytes.
	 * @param int    $max_bytes Configured cap.
	 */
	private function doing_it_wrong_oversize( string $path, int $size, int $max_bytes ): void {
		if ( ! function_exists( '_doing_it_wrong' ) || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		_doing_it_wrong(
			'AbilityGuard FilesCollector',
			sprintf(
				/* translators: 1: file path, 2: actual size, 3: configured cap */
				esc_html__( 'File "%1$s" (%2$d bytes) exceeds the abilityguard_max_file_bytes cap (%3$d). Captured as fingerprint-only; rollback will not restore content for this path.', 'abilityguard' ),
				esc_html( $path ),
				absint( $size ),
				absint( $max_bytes )
			),
			'0.9.0'
		);
	}

	/**
	 * Resolve raw spec input into a normalised tuple.
	 *
	 * Returns `paths` as an iterable so generator-based specs aren't
	 * materialised before the collector loop runs.
	 *
	 * @param mixed $spec Spec input.
	 *
	 * @return array{0: iterable<int|string, string>, 1: string, 2: string[]} Paths, strategy, exclude_dirs.
	 */
	private function resolve_spec( $spec ): array {
		$paths        = array();
		$strategy     = $this->default_strategy();
		$exclude_dirs = $this->default_exclude_dirs();

		if ( is_array( $spec ) && isset( $spec['paths'] ) ) {
			$candidate = $spec['paths'];
			if ( is_array( $candidate ) || $candidate instanceof \Traversable ) {
				$paths = $candidate;
			}
			if ( isset( $spec['strategy'] ) && is_string( $spec['strategy'] ) ) {
				$strategy = $this->validate_strategy( $spec['strategy'], $strategy );
			}
			if ( isset( $spec['exclude_dirs'] ) && is_array( $spec['exclude_dirs'] ) ) {
				$exclude_dirs = array_merge( $exclude_dirs, $spec['exclude_dirs'] );
			}
		} elseif ( $spec instanceof \Traversable ) {
			// Legacy-style flat input but as a generator.
			$paths = $spec;
		} elseif ( is_array( $spec ) ) {
			// Legacy shape: flat list of paths.
			$paths = $spec;
		}

		return array( $paths, $strategy, $exclude_dirs );
	}

	/**
	 * Default strategy, filterable.
	 */
	private function default_strategy(): string {
		$default = function_exists( 'apply_filters' )
			? (string) apply_filters( 'abilityguard_files_default_strategy', self::STRATEGY_FULL_HASH )
			: self::STRATEGY_FULL_HASH;

		return $this->validate_strategy( $default, self::STRATEGY_FULL_HASH );
	}

	/**
	 * Default backup-directory exclusions, filterable.
	 *
	 * @return string[]
	 */
	private function default_exclude_dirs(): array {
		$defaults = self::BACKUP_PLUGIN_EXCLUDES;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'abilityguard_files_default_exclude_dirs', $defaults );
			if ( is_array( $filtered ) ) {
				$defaults = array_values( array_filter( $filtered, 'is_string' ) );
			}
		}
		return $defaults;
	}

	/**
	 * Validate a strategy string; fall back to $fallback if invalid.
	 *
	 * @param string $strategy Candidate strategy.
	 * @param string $fallback Returned when $strategy isn't recognised.
	 */
	private function validate_strategy( string $strategy, string $fallback ): string {
		$valid = array(
			self::STRATEGY_MTIME,
			self::STRATEGY_MTIME_SIZE,
			self::STRATEGY_CRITICAL_HASH,
			self::STRATEGY_FULL_HASH,
			self::STRATEGY_FULL_CONTENT,
		);
		return in_array( $strategy, $valid, true ) ? $strategy : $fallback;
	}

	/**
	 * Decide whether to hash a given path under a given strategy.
	 *
	 * @param string $path     Absolute path.
	 * @param string $strategy Strategy enum.
	 */
	private function should_hash( string $path, string $strategy ): bool {
		if ( self::STRATEGY_FULL_HASH === $strategy ) {
			return true;
		}
		if ( self::STRATEGY_CRITICAL_HASH === $strategy ) {
			return $this->is_critical( $path );
		}
		// 'mtime' and 'mtime_size' never hash.
		return false;
	}

	/**
	 * Whether a path is "critical" - content-equality matters more than mtime.
	 *
	 * Source of truth is CriticalFileRegistry. The legacy
	 * `abilityguard_files_critical_suffixes` filter still wins so existing
	 * code keeps working; the registry merely seeds defaults.
	 *
	 * @param string $path Absolute path.
	 */
	private function is_critical( string $path ): bool {
		$suffixes = CriticalFileRegistry::all();

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'abilityguard_files_critical_suffixes', $suffixes );
			if ( is_array( $filtered ) ) {
				$suffixes = array_values( array_filter( $filtered, 'is_string' ) );
			}
		}

		foreach ( $suffixes as $suffix ) {
			if ( '' !== $suffix && str_ends_with( $path, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path matches any of the exclude-dir substrings.
	 *
	 * @param string   $path         Absolute path.
	 * @param string[] $exclude_dirs Substrings to match against.
	 */
	private function path_excluded( string $path, array $exclude_dirs ): bool {
		$normalised = str_replace( '\\', '/', $path );
		foreach ( $exclude_dirs as $needle ) {
			if ( '' !== $needle && false !== strpos( $normalised, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decide whether captured state differs from current state.
	 *
	 * Uses sha256 when both sides have it; otherwise falls back to size + mtime.
	 *
	 * @param array<string, mixed> $captured Captured entry.
	 * @param array<string, mixed> $current  Current entry.
	 */
	private function state_differs( array $captured, array $current ): bool {
		$cap_exists = ! empty( $captured['exists'] );
		$cur_exists = ! empty( $current['exists'] );
		if ( $cap_exists !== $cur_exists ) {
			return true;
		}
		if ( ! $cap_exists ) {
			return false; // both missing - no change.
		}

		// Hash comparison wins when both sides have one.
		if ( ! empty( $captured['sha256'] ) && ! empty( $current['sha256'] ) ) {
			return $captured['sha256'] !== $current['sha256'];
		}

		// Fallback: size + mtime mismatch counts as change.
		$captured_size = isset( $captured['size'] ) ? (int) $captured['size'] : null;
		$current_size  = isset( $current['size'] ) ? (int) $current['size'] : null;
		if ( $captured_size !== $current_size ) {
			return true;
		}

		$captured_mt = isset( $captured['mtime'] ) ? (int) $captured['mtime'] : null;
		$current_mt  = isset( $current['mtime'] ) ? (int) $current['mtime'] : null;
		return $captured_mt !== $current_mt;
	}
}
