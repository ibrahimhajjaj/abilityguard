<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\CriticalFileRegistry;
use AbilityGuard\Snapshot\Collector\FilesCollector;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for FilesCollector against the real filesystem.
 *
 * This suite intentionally drives raw PHP filesystem calls (unlink, file_put_contents,
 * file_get_contents, touch) against tmp fixtures. WP_Filesystem is a network/FTP
 * abstraction designed for runtime, not for synchronous fixture setup in tests.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_touch
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */
final class FilesCollectorTest extends WP_UnitTestCase {

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
		parent::tearDown();
	}

	private function tmp_file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'ag_files_test_' );
		if ( false === $path ) {
			$this->fail( 'Could not create temp file.' );
		}
		file_put_contents( $path, $content );
		$this->tmp_files[] = $path;
		return $path;
	}

	public function test_collect_records_file_state(): void {
		$path      = $this->tmp_file( 'hello world' );
		$collector = new FilesCollector();
		$captured  = $collector->collect( array( $path ) );

		$this->assertArrayHasKey( $path, $captured );
		$this->assertTrue( $captured[ $path ]['exists'] );
		$this->assertNotNull( $captured[ $path ]['sha256'] );
		$this->assertSame( hash( 'sha256', 'hello world' ), $captured[ $path ]['sha256'] );
		$this->assertNotNull( $captured[ $path ]['size'] );
		$this->assertNotNull( $captured[ $path ]['mtime'] );
	}

	public function test_collect_records_missing_file(): void {
		$collector = new FilesCollector();
		$captured  = $collector->collect( array( '/nonexistent/path/file.txt' ) );

		$this->assertArrayHasKey( '/nonexistent/path/file.txt', $captured );
		$this->assertFalse( $captured['/nonexistent/path/file.txt']['exists'] );
		$this->assertNull( $captured['/nonexistent/path/file.txt']['sha256'] );
	}

	public function test_restore_is_noop_but_fires_action_for_changed_file(): void {
		$path      = $this->tmp_file( 'original content' );
		$collector = new FilesCollector();

		// Snapshot.
		$captured = $collector->collect( array( $path ) );

		// Mutate the file.
		file_put_contents( $path, 'mutated content' );

		// Wire up the action listener.
		$fired_paths = array();
		add_action(
			'abilityguard_files_changed_since_snapshot',
			function ( array $changed ) use ( &$fired_paths ): void {
				$fired_paths = $changed;
			}
		);

		// Restore (no-op for file content).
		$collector->restore( $captured );

		// File on disk is still mutated.
		$this->assertSame( 'mutated content', file_get_contents( $path ) );

		// Action fired with the changed path.
		$this->assertContains( $path, $fired_paths );
	}

	public function test_restore_does_not_fire_action_for_unchanged_file(): void {
		$path      = $this->tmp_file( 'stable content' );
		$collector = new FilesCollector();

		$captured = $collector->collect( array( $path ) );

		$fired = false;
		add_action(
			'abilityguard_files_changed_since_snapshot',
			function ( array $changed ) use ( &$fired, $path ): void {
				if ( in_array( $path, $changed, true ) ) {
					$fired = true;
				}
			}
		);

		$collector->restore( $captured );

		$this->assertFalse( $fired );
	}

	public function test_strategy_mtime_skips_hashing(): void {
		$path = $this->tmp_file( 'plain content' );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_MTIME,
			)
		);
		$this->assertNull( $captured[ $path ]['sha256'], 'mtime strategy must not compute sha256' );
		$this->assertNotNull( $captured[ $path ]['mtime'], 'mtime strategy still records mtime' );
		$this->assertNotNull( $captured[ $path ]['size'], 'mtime strategy still records size' );
	}

	public function test_strategy_mtime_size_detects_size_change_without_hash(): void {
		$path = $this->tmp_file( 'short' );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'    => array( $path ),
				'strategy' => FilesCollector::STRATEGY_MTIME_SIZE,
			)
		);

		// Mutate content (changes size + mtime) and bump mtime so detection
		// works even on fast filesystems where touches share a second.
		file_put_contents( $path, 'much longer content here' );
		touch( $path, time() + 5 );

		$fired = false;
		add_action(
			'abilityguard_files_changed_since_snapshot',
			function ( $changed ) use ( $path, &$fired ): void {
				if ( in_array( $path, $changed, true ) ) {
					$fired = true;
				}
			}
		);

		$col->restore( $captured );
		$this->assertTrue( $fired, 'mtime_size strategy must detect size+mtime change without sha256' );
	}

	public function test_strategy_critical_hash_only_hashes_critical_paths(): void {
		$normal_path   = $this->tmp_file( 'normal' );
		$critical_path = sys_get_temp_dir() . '/ag_critical_test/.htaccess';
		wp_mkdir_p( dirname( $critical_path ) );
		file_put_contents( $critical_path, 'AddType text/html .htm' );
		$this->tmp_files[] = $critical_path;

		$col      = new FilesCollector();
		$captured = $col->collect(
			array(
				'paths'    => array( $normal_path, $critical_path ),
				'strategy' => FilesCollector::STRATEGY_CRITICAL_HASH,
			)
		);

		$this->assertNull( $captured[ $normal_path ]['sha256'], 'non-critical path must not be hashed' );
		$this->assertNotNull( $captured[ $critical_path ]['sha256'], 'critical (.htaccess) path must be hashed' );
	}

	public function test_exclude_dirs_filter_drops_matching_paths(): void {
		$path = $this->tmp_file( 'excluded' );
		$col  = new FilesCollector();

		$captured = $col->collect(
			array(
				'paths'        => array( $path ),
				'exclude_dirs' => array( basename( $path ) ),
			)
		);

		$this->assertArrayNotHasKey( $path, $captured, 'matching exclude_dirs entry must drop the path' );
	}

	public function test_legacy_flat_path_array_still_works(): void {
		$path     = $this->tmp_file( 'legacy' );
		$col      = new FilesCollector();
		$captured = $col->collect( array( $path ) );

		$this->assertArrayHasKey( $path, $captured, 'flat string[] spec must still be accepted' );
		$this->assertNotNull( $captured[ $path ]['sha256'], 'legacy flat spec defaults to full_hash strategy' );
	}

	public function test_critical_file_registry_extends_default_set(): void {
		$normal_path = $this->tmp_file( 'plain' );
		$weird_path  = sys_get_temp_dir() . '/ag_critical_registry_test/secret.token';
		wp_mkdir_p( dirname( $weird_path ) );
		file_put_contents( $weird_path, 'super-secret' );
		$this->tmp_files[] = $weird_path;

		CriticalFileRegistry::add( '/secret.token' );

		try {
			$col      = new FilesCollector();
			$captured = $col->collect(
				array(
					'paths'    => array( $normal_path, $weird_path ),
					'strategy' => FilesCollector::STRATEGY_CRITICAL_HASH,
				)
			);

			$this->assertNull( $captured[ $normal_path ]['sha256'], 'non-critical path stays unhashed' );
			$this->assertNotNull(
				$captured[ $weird_path ]['sha256'],
				'registered suffix promotes path to critical, must be hashed'
			);
		} finally {
			CriticalFileRegistry::reset();
		}
	}

	public function test_deletion_fires_separate_action(): void {
		$path = $this->tmp_file( 'will be deleted' );
		$col  = new FilesCollector();

		$captured = $col->collect( array( $path ) );

		// Remove the file so the deletion-specific action should fire.
		unlink( $path );
		$this->tmp_files = array_values( array_diff( $this->tmp_files, array( $path ) ) );

		$changed = array();
		$deleted = array();
		add_action(
			'abilityguard_files_changed_since_snapshot',
			static function ( array $paths ) use ( &$changed ): void {
				$changed = $paths;
			}
		);
		add_action(
			'abilityguard_files_deleted_since_snapshot',
			static function ( array $paths ) use ( &$deleted ): void {
				$deleted = $paths;
			}
		);

		$col->restore( $captured );

		$this->assertContains( $path, $changed, 'deleted file must appear in generic change list' );
		$this->assertContains( $path, $deleted, 'deleted file must appear in deletion-specific list' );
	}

	public function test_collect_accepts_generator_paths(): void {
		$a = $this->tmp_file( 'gen-a' );
		$b = $this->tmp_file( 'gen-b' );

		$gen = static function () use ( $a, $b ) {
			yield $a;
			yield $b;
		};

		$col      = new FilesCollector();
		$captured = $col->collect(
			array(
				'paths' => $gen(),
			)
		);

		$this->assertArrayHasKey( $a, $captured );
		$this->assertArrayHasKey( $b, $captured );
	}

	public function test_defaults_wiring_persists_files_surface(): void {
		$path    = $this->tmp_file( 'wiring test' );
		$service = new SnapshotService( new SnapshotStore() );
		$result  = $service->capture(
			'inv-files-wire-1',
			array(
				'snapshot' => array(
					'files' => array( $path ),
				),
			),
			null
		);

		$this->assertNotNull( $result['snapshot_id'] );
		$this->assertArrayHasKey( 'files', $result['surfaces'] );
	}
}
