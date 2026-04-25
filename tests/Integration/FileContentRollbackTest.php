<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\FilesCollector;
use AbilityGuard\Snapshot\FileBlobStore;
use WP_UnitTestCase;

/**
 * Round-trip tests for STRATEGY_FULL_CONTENT - the v0.9 file-rollback slice.
 *
 * Covers: capture writes encrypted blob; restore rewrites bytes via temp+rename;
 * deleted file is re-created; oversize file falls back to fingerprint-only;
 * tampered blob is detected; path-safety rejects traversal attempts.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 */
final class FileContentRollbackTest extends WP_UnitTestCase {

	/**
	 * Tracked tmp file paths to clean up on tearDown.
	 *
	 * @var string[]
	 */
	private array $tmp_files = array();

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		// Sweep the staging dir clean so test runs don't pile up.
		FileBlobStore::prune_except( array() );
		parent::tearDown();
	}

	private function tmp_under_abspath( string $contents, string $suffix = '' ): string {
		// Tests must write under ABSPATH so is_safe_path() accepts them.
		$dir = trailingslashit( ABSPATH ) . 'wp-content/uploads/ag-tests';
		wp_mkdir_p( $dir );
		$path = $dir . '/ag_test_' . uniqid() . $suffix;
		file_put_contents( $path, $contents );
		$this->tmp_files[] = $path;
		return $path;
	}

	public function test_full_content_round_trip_restores_original_bytes(): void {
		$path = $this->tmp_under_abspath( "version-1\nline two\n" );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
			)
		);

		$this->assertNotEmpty( $captured[ $path ]['blob'] ?? '', 'blob hash should be set' );
		$this->assertSame( hash( 'sha256', "version-1\nline two\n" ), $captured[ $path ]['blob'] );

		// Mutate.
		file_put_contents( $path, 'TAMPERED' );
		$this->assertSame( 'TAMPERED', file_get_contents( $path ) );

		// Restore - should rewrite bytes from the captured blob.
		$col->restore( $captured );

		$this->assertSame( "version-1\nline two\n", file_get_contents( $path ), 'restore must rewrite original bytes' );
	}

	public function test_full_content_recreates_deleted_file(): void {
		$path = $this->tmp_under_abspath( 'will be deleted' );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
			)
		);

		unlink( $path );
		$this->assertFileDoesNotExist( $path );

		$col->restore( $captured );

		$this->assertFileExists( $path, 'deleted file should be re-created' );
		$this->assertSame( 'will be deleted', file_get_contents( $path ) );
	}

	public function test_full_content_preserves_octal_mode_when_captured(): void {
		$path = $this->tmp_under_abspath( 'mode-test' );
		chmod( $path, 0640 );
		clearstatcache( true, $path );

		$col      = new FilesCollector();
		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
			)
		);
		$this->assertSame( 0640, $captured[ $path ]['mode'] ?? null );

		// Mutate content + permissions.
		file_put_contents( $path, 'changed' );
		chmod( $path, 0664 );
		clearstatcache( true, $path );

		$col->restore( $captured );

		clearstatcache( true, $path );
		$mode_after = fileperms( $path ) & 0777;
		$this->assertSame( 0640, $mode_after, 'mode must be restored' );
	}

	public function test_oversize_file_falls_back_to_fingerprint_only(): void {
		$this->setExpectedIncorrectUsage( 'AbilityGuard FilesCollector' );

		$path = $this->tmp_under_abspath( str_repeat( 'A', 16 ) );

		add_filter( 'abilityguard_max_file_bytes', static fn() => 8 ); // 8-byte cap

		try {
			$col      = new FilesCollector();
			$captured = $col->collect(
				array(
					'paths'    => array( $path ),
					'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
				)
			);
			$this->assertArrayNotHasKey( 'blob', $captured[ $path ], 'oversize file must NOT carry a blob hash' );
			$this->assertSame( 16, $captured[ $path ]['size'] );
		} finally {
			remove_all_filters( 'abilityguard_max_file_bytes' );
		}
	}

	public function test_tampered_blob_is_not_used_for_restore(): void {
		$path = $this->tmp_under_abspath( 'pristine' );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
			)
		);
		$hash     = $captured[ $path ]['blob'];

		// Tamper with the on-disk blob: overwrite with garbage. Cipher's
		// auth tag will fail decrypt; FileBlobStore::get() returns null.
		$blob_path = trailingslashit( FileBlobStore::staging_dir() ) . $hash;
		file_put_contents( $blob_path, '{"_abilityguard_redacted":true,"ct":"YQ==","iv":"YQ==","tag":"YQ=="}' );

		// Mutate the source file too so restore would rewrite it on success.
		file_put_contents( $path, 'mutated' );

		$col->restore( $captured );

		$this->assertSame(
			'mutated',
			file_get_contents( $path ),
			'tampered blob must NOT cause a write - file stays as-is'
		);
	}

	public function test_path_traversal_attempt_is_rejected(): void {
		$evil = ABSPATH . 'wp-content/../../etc/passwd-attempt';

		$col      = new FilesCollector();
		$captured = array(
			$evil => array(
				'exists' => true,
				'sha256' => hash( 'sha256', 'x' ),
				'size'   => 1,
				'mtime'  => time(),
				'mode'   => 0644,
				'blob'   => FileBlobStore::put( 'x' ),
			),
		);

		// Should not throw, should not write anywhere outside ABSPATH.
		$col->restore( $captured );
		$this->assertFileDoesNotExist( $evil );
	}

	public function test_blob_dedup_by_content_hash(): void {
		$a = $this->tmp_under_abspath( 'identical' );
		$b = $this->tmp_under_abspath( 'identical' );

		$col      = new FilesCollector();
		$captured = $col->collect(
			array(
				'paths'    => array( $a, $b ),
				'strategy' => FilesCollector::STRATEGY_FULL_CONTENT,
			)
		);

		$this->assertSame(
			$captured[ $a ]['blob'],
			$captured[ $b ]['blob'],
			'identical bytes must share one staging blob'
		);
	}
}
