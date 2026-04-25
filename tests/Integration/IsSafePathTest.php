<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Snapshot\Collector\FilesCollector;
use WP_UnitTestCase;

/**
 * Path-traversal defence taxonomy. Each row is a path string an attacker
 * might inject into a snapshot's files surface; is_safe_path() must
 * reject anything that would escape ABSPATH or contain a poison byte.
 */
final class IsSafePathTest extends WP_UnitTestCase {

	public function test_rejects_empty_string(): void {
		$this->assertFalse( FilesCollector::is_safe_path( '' ) );
	}

	public function test_rejects_null_byte(): void {
		$this->assertFalse( FilesCollector::is_safe_path( ABSPATH . "wp-content/uploads/foo\0bar.txt" ) );
	}

	public function test_rejects_dotdot_segment(): void {
		$this->assertFalse( FilesCollector::is_safe_path( ABSPATH . 'wp-content/../../etc/passwd' ) );
	}

	public function test_rejects_leading_dotdot(): void {
		$this->assertFalse( FilesCollector::is_safe_path( '../etc/passwd' ) );
	}

	public function test_rejects_backslash_dotdot_segment(): void {
		$this->assertFalse( FilesCollector::is_safe_path( ABSPATH . 'wp-content\\..\\etc\\passwd' ) );
	}

	public function test_rejects_dotdot_at_path_end(): void {
		$this->assertFalse( FilesCollector::is_safe_path( ABSPATH . 'wp-content/uploads/..' ) );
	}

	public function test_rejects_path_outside_abspath(): void {
		$this->assertFalse( FilesCollector::is_safe_path( '/etc/passwd' ) );
	}

	public function test_accepts_existing_path_under_abspath(): void {
		// wp-content always exists under ABSPATH in the test rig.
		$this->assertTrue( FilesCollector::is_safe_path( ABSPATH . 'wp-content/uploads/test.txt' ) );
	}

	public function test_accepts_path_when_parent_dir_must_be_created(): void {
		// Deletion-restore: the file (and its dir) may not yet exist; the
		// guard falls back to checking the requested path starts with ABSPATH.
		$this->assertTrue( FilesCollector::is_safe_path( ABSPATH . 'wp-content/uploads/new-folder-' . uniqid() . '/file.txt' ) );
	}

	public function test_rejects_dotdot_in_middle_of_filename_does_not_count(): void {
		// Only `/../` segments are dangerous, not literal `..` in a filename.
		// e.g. `foo..bar.txt` should be allowed; `foo/../bar` should not.
		$this->assertTrue( FilesCollector::is_safe_path( ABSPATH . 'wp-content/uploads/foo..bar.txt' ) );
	}
}
