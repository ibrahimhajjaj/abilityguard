<?php
/**
 * Sidecar file store for snapshotted file contents.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot;

use AbilityGuard\Support\Cipher;
use RuntimeException;

/**
 * Stores file bytes captured by FilesCollector under STRATEGY_FULL_CONTENT.
 *
 * ## Why a sidecar dir, not a DB blob column
 *
 * File contents have very different size + retention characteristics from
 * the option/meta scalars in the snapshots table. Blobbing into a DB
 * column inflates row sizes, hurts query performance, and makes
 * retention sweeps expensive. Filesystem storage with content-addressed
 * filenames lets us:
 *
 *  - Dedupe: identical blobs share a single file (e.g. all snapshots of an
 *    unchanged `.htaccess` reuse the same hash-keyed file).
 *  - Prune cheaply: a single `unlink()` per orphaned hash.
 *  - Skip the snapshot row size cap entirely for the file body.
 *
 * Encryption-at-rest reuses Cipher (AES-256-GCM, per-site key). We base64
 * the raw bytes before encrypt() so Cipher's internal wp_json_encode
 * doesn't choke on binary payloads.
 *
 * Atomic writes via temp-file + rename, scoped to the staging dir on the
 * same filesystem. The blob filename is the sha256 of the *plaintext*
 * bytes - so a tampered blob fails the post-decrypt hash check at
 * restore time, defending against in-place blob substitution.
 */
final class FileBlobStore {

	/**
	 * Subdirectory under wp-content where blobs live.
	 */
	private const STAGING_DIR_NAME = 'abilityguard-staging';

	/**
	 * Absolute path of the staging directory, ensured to exist.
	 *
	 * Filterable via `abilityguard_file_blob_dir` for tests / unusual hosts.
	 */
	public static function staging_dir(): string {
		$default = trailingslashit( WP_CONTENT_DIR ) . self::STAGING_DIR_NAME;
		$dir     = function_exists( 'apply_filters' )
			? (string) apply_filters( 'abilityguard_file_blob_dir', $default )
			: $default;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Drop sentinel + .htaccess so this dir is never confused with
			// a third-party archive directory by the FilesCollector excludes.
			$marker = trailingslashit( $dir ) . 'index.html';
			if ( ! file_exists( $marker ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $marker, "<!-- AbilityGuard blob staging -->\n" );
			}
			$ht = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $ht ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $ht, "Deny from all\n" );
			}
		}
		return $dir;
	}

	/**
	 * Write bytes into the store. Returns the sha256 of the plaintext
	 * which doubles as the blob's filename stem.
	 *
	 * Idempotent: a second `put()` of the same bytes is a no-op.
	 *
	 * @param string $bytes Raw file contents.
	 *
	 * @return string Hex-encoded sha256 of the plaintext.
	 *
	 * @throws RuntimeException When the encrypt or atomic-write step fails.
	 */
	public static function put( string $bytes ): string {
		$hash = hash( 'sha256', $bytes );
		$path = self::path_for( $hash );

		if ( file_exists( $path ) ) {
			return $hash; // dedup hit.
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$envelope = Cipher::encrypt( base64_encode( $bytes ) );
		$json     = wp_json_encode( $envelope );
		if ( false === $json ) {
			throw new RuntimeException( 'AbilityGuard\\Snapshot\\FileBlobStore: wp_json_encode failed.' );
		}

		// Atomic write: temp file in the same dir, then rename.
		$tmp = $path . '.tmp.' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = file_put_contents( $tmp, $json );
		if ( false === $ok ) {
			throw new RuntimeException( 'AbilityGuard\\Snapshot\\FileBlobStore: temp write failed.' );
		}
		if ( ! rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			throw new RuntimeException( 'AbilityGuard\\Snapshot\\FileBlobStore: rename failed.' );
		}

		return $hash;
	}

	/**
	 * Read bytes for a previously-stored hash. Returns null when the blob
	 * is missing or the post-decrypt hash check fails (tampered).
	 *
	 * @param string $hash Hex sha256.
	 */
	public static function get( string $hash ): ?string {
		$path = self::path_for( $hash );
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $path );
		if ( false === $json ) {
			return null;
		}

		$envelope = json_decode( $json, true );
		if ( ! is_array( $envelope ) || ! Cipher::is_envelope( $envelope ) ) {
			return null;
		}

		try {
			$b64 = Cipher::decrypt( $envelope );
		} catch ( RuntimeException $e ) {
			return null;
		}

		if ( ! is_string( $b64 ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes ) {
			return null;
		}

		// Tamper detection: the filename hash must match the plaintext hash.
		if ( hash( 'sha256', $bytes ) !== $hash ) {
			return null;
		}

		return $bytes;
	}

	/**
	 * Whether a blob exists for the given hash.
	 *
	 * @param string $hash Hex sha256.
	 */
	public static function has( string $hash ): bool {
		return file_exists( self::path_for( $hash ) );
	}

	/**
	 * Delete a single blob by hash. Returns false if the file was missing.
	 *
	 * @param string $hash Hex sha256.
	 */
	public static function delete( string $hash ): bool {
		$path = self::path_for( $hash );
		if ( ! file_exists( $path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return @unlink( $path );
	}

	/**
	 * Remove every blob whose hash isn't in the keep-set.
	 *
	 * Used by RetentionService to garbage-collect orphaned content when
	 * the snapshots table no longer references it.
	 *
	 * @param array<int, string> $keep_hashes Hex sha256 list to preserve.
	 *
	 * @return int Number of blobs unlinked.
	 */
	public static function prune_except( array $keep_hashes ): int {
		$dir = self::staging_dir();
		$set = array_flip(
			array_filter(
				$keep_hashes,
				static fn( $h ): bool => is_string( $h ) && '' !== $h
			)
		);

		$entries = is_dir( $dir ) ? scandir( $dir ) : array();
		if ( false === $entries ) {
			return 0;
		}

		$removed = 0;
		foreach ( $entries as $entry ) {
			// Match exactly 64-hex-char filenames; skip index.html, .htaccess, .tmp leftovers.
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $entry ) ) {
				continue;
			}
			if ( isset( $set[ $entry ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( @unlink( trailingslashit( $dir ) . $entry ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Resolve the absolute filename for a given hash.
	 *
	 * @param string $hash Hex sha256 (caller is responsible for validating shape).
	 */
	private static function path_for( string $hash ): string {
		return trailingslashit( self::staging_dir() ) . $hash;
	}
}
