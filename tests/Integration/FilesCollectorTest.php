<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\FilesCollector;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for FilesCollector against the real filesystem.
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
