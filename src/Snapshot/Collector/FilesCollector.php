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
 * ## Detection strategies (lifted from SafeGuard's File_Scanner tiers)
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

	/**
	 * Other-plugin backup directories we exclude by default. Lifted from
	 * SafeGuard's `File_Scanner::$exclude_patterns`. Plugin authors can extend
	 * via `safety.snapshot.files.exclude_dirs` or the
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
	 * @return array<string, array{exists: bool, sha256: ?string, size: ?int, mtime: ?int}>
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

			$hash = null;
			if ( $this->should_hash( $path, $strategy ) ) {
				$h    = @hash_file( 'sha256', $path );
				$hash = ( false !== $h ) ? $h : null;
			}
			// phpcs:enable

			$out[ $path ] = array(
				'exists' => true,
				'sha256' => $hash,
				'size'   => $size,
				'mtime'  => $mt,
			);
		}

		ksort( $out );

		return $out;
	}

	/**
	 * No-op restore: files are not restored.
	 *
	 * Compares captured state to current state using each entry's
	 * available signals (sha256 if present, otherwise size + mtime),
	 * and fires `abilityguard_files_changed_since_snapshot` with the
	 * paths whose state has changed.
	 *
	 * Deletions (captured.exists=true && current.exists=false) are also
	 * fired as a distinct signal via `abilityguard_files_deleted_since_snapshot`
	 * so listeners that care specifically about disappearance (security
	 * monitors, restore prompts) can react without re-deriving it from a
	 * generic change list.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		$changed_paths = array();
		$deleted_paths = array();

		foreach ( $captured as $path => $state ) {
			$path = (string) $path;

			if ( ! is_array( $state ) ) {
				continue;
			}

			$current = $this->collect( array( $path ) );
			if ( ! isset( $current[ $path ] ) ) {
				$changed_paths[] = $path;
				continue;
			}

			if ( $this->state_differs( $state, $current[ $path ] ) ) {
				$changed_paths[] = $path;

				// Deletion-specific: was present at snapshot, gone now.
				$cap_exists = ! empty( $state['exists'] );
				$cur_exists = ! empty( $current[ $path ]['exists'] );
				if ( $cap_exists && ! $cur_exists ) {
					$deleted_paths[] = $path;
				}
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		do_action( 'abilityguard_files_changed_since_snapshot', $changed_paths );

		if ( array() !== $deleted_paths ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			do_action( 'abilityguard_files_deleted_since_snapshot', $deleted_paths );
		}
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
