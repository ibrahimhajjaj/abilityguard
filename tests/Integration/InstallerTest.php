<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use WP_UnitTestCase;

final class InstallerTest extends WP_UnitTestCase {

	public function test_three_tables_exist_after_install(): void {
		Installer::install();

		global $wpdb;
		foreach ( array( 'log', 'log_meta', 'snapshots' ) as $key ) {
			$table  = Installer::table( $key );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $exists, "Table {$table} should exist" );
		}
	}

	public function test_log_table_has_expected_columns(): void {
		Installer::install();
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$columns = array_column( $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ), 'Field' );

		$expected = array( 'id', 'invocation_id', 'ability_name', 'caller_type', 'user_id', 'args_json', 'result_json', 'status', 'destructive', 'duration_ms', 'pre_hash', 'post_hash', 'snapshot_id', 'created_at' );
		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns, "Column {$column} missing on log table" );
		}
	}

	public function test_db_version_option_is_recorded(): void {
		Installer::install();
		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
	}
}
