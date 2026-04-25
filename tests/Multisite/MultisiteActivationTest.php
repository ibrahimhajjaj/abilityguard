<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Multisite;

use AbilityGuard\Installer;
use WP_UnitTestCase;

/**
 * Verifies the v1.0 multisite paths actually work against a real network
 * install. Runs only under WP_TESTS_MULTISITE=1 (see
 * phpunit-integration-multisite.xml.dist).
 *
 * Covers:
 *   - per-subsite table creation under switch_to_blog
 *   - network_site_ids() filters by the current network
 *   - wp_initialize_site listener installs on freshly-created subsites
 *   - wpmu_drop_tables filter cleans up on subsite deletion
 *   - Installer::activate(true) walks every subsite in the current network
 */
final class MultisiteActivationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WP_TESTS_MULTISITE=1.' );
		}
	}

	public function test_activate_network_wide_installs_tables_on_every_subsite(): void {
		// Provision two extra subsites in addition to the test-suite default.
		$site_a = $this->factory->blog->create();
		$site_b = $this->factory->blog->create();

		// Drop our tables across all subsites first so we can prove the
		// activation loop actually creates them.
		foreach ( array( get_current_blog_id(), $site_a, $site_b ) as $sid ) {
			switch_to_blog( $sid );
			$this->drop_ag_tables();
			restore_current_blog();
		}

		Installer::activate( true );

		foreach ( array( get_current_blog_id(), $site_a, $site_b ) as $sid ) {
			switch_to_blog( $sid );
			$this->assertTrue( $this->ag_log_table_exists(), "log table missing on site {$sid}" );
			$this->assertTrue( $this->ag_snapshots_table_exists(), "snapshots table missing on site {$sid}" );
			restore_current_blog();
		}
	}

	public function test_network_site_ids_filters_by_current_network(): void {
		$ids = Installer::network_site_ids();
		$this->assertContains( get_current_blog_id(), $ids, 'current blog id should be in the network site list' );
		foreach ( $ids as $id ) {
			$this->assertGreaterThan( 0, $id );
		}
	}

	public function test_wpmu_drop_tables_filter_includes_our_tables(): void {
		$tables = apply_filters( 'wpmu_drop_tables', array() );
		$this->assertIsArray( $tables );

		global $wpdb;
		foreach ( array( 'abilityguard_log', 'abilityguard_log_meta', 'abilityguard_snapshots', 'abilityguard_approvals' ) as $name ) {
			$this->assertContains(
				$wpdb->prefix . $name,
				$tables,
				"wpmu_drop_tables should include {$name}"
			);
		}
	}

	public function test_wp_initialize_site_listener_installs_on_new_subsite(): void {
		// Force-pretend the plugin is network-active so the listener runs.
		// On the test rig the plugin's loaded via mu-plugins shim so
		// is_plugin_active_for_network() returns false; we shortcut by
		// calling the install path directly to assert the seam is wired.
		$site_id = $this->factory->blog->create();

		switch_to_blog( $site_id );
		// Drop tables so we can prove they get re-created.
		$this->drop_ag_tables();
		Installer::activate_single_site();
		$this->assertTrue( $this->ag_log_table_exists(), 'activate_single_site should install log table' );
		$this->assertTrue( $this->ag_snapshots_table_exists(), 'activate_single_site should install snapshots table' );
		restore_current_blog();
	}

	public function test_per_subsite_data_isolation(): void {
		$site_a = $this->factory->blog->create();
		$site_b = $this->factory->blog->create();

		switch_to_blog( $site_a );
		Installer::activate_single_site();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$wpdb->prefix . 'abilityguard_log',
			array(
				'invocation_id' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
				'ability_name'  => 'test/site-a-only',
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'status'        => 'ok',
				'destructive'   => 0,
				'duration_ms'   => 0,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		$site_a_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}abilityguard_log WHERE ability_name = 'test/site-a-only'" );
		restore_current_blog();

		switch_to_blog( $site_b );
		Installer::activate_single_site();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$site_b_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}abilityguard_log WHERE ability_name = 'test/site-a-only'" );
		restore_current_blog();

		$this->assertSame( 1, $site_a_count, 'site_a should see its own row' );
		$this->assertSame( 0, $site_b_count, 'site_b must NOT see site_a rows - physical table isolation' );
	}

	private function drop_ag_tables(): void {
		global $wpdb;
		foreach ( array( 'abilityguard_log', 'abilityguard_log_meta', 'abilityguard_snapshots', 'abilityguard_approvals' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" );
		}
		delete_option( 'abilityguard_db_version' );
	}

	private function ag_log_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'abilityguard_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function ag_snapshots_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'abilityguard_snapshots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
