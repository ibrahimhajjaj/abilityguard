<?php
/**
 * Plugin uninstall handler.
 *
 * Fired by WordPress when the plugin is deleted (not just deactivated).
 * On a multisite network, walks every subsite in every network and drops
 * AbilityGuard's four tables, options, and approval capability on each.
 * On a single site, runs the same teardown in place.
 *
 * `register_uninstall_hook()` runs WITHOUT a `$network_wide` argument -
 * by the time uninstall fires the plugin's main file may have already been
 * deleted, so we rediscover the multisite topology via `is_multisite()` +
 * `get_sites()` and require CapabilityManager directly.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Approval/CapabilityManager.php';

/**
 * Drop tables, clear cron, remove the approval capability for the
 * currently-switched-to subsite.
 */
function abilityguard_uninstall_single_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'abilityguard_retention_prune' );

	AbilityGuard\Approval\CapabilityManager::remove_from_all_roles();

	$tables = array(
		$wpdb->prefix . 'abilityguard_log',
		$wpdb->prefix . 'abilityguard_log_meta',
		$wpdb->prefix . 'abilityguard_snapshots',
		$wpdb->prefix . 'abilityguard_approvals',
	);
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	delete_option( 'abilityguard_db_version' );
	delete_option( 'abilityguard_last_pruned' );
	delete_option( 'abilityguard_last_pruned_count' );
}

/**
 * Best-effort purge of the FileBlobStore staging directory under
 * wp-content. Shared across the whole install - purge once, not per
 * subsite. Failures here are non-fatal.
 */
function abilityguard_uninstall_purge_staging(): void {
	$dir = trailingslashit( WP_CONTENT_DIR ) . 'abilityguard-staging';
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$entries = scandir( $dir );
	if ( false === $entries ) {
		return;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( trailingslashit( $dir ) . $entry );
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@rmdir( $dir );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	// Walk every subsite in every network. Plugin deletion means uninstall;
	// orphan tables on subsites the operator may not even remember about
	// would just rot in the database.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	if ( is_array( $site_ids ) ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			abilityguard_uninstall_single_site();
			restore_current_blog();
		}
	}
} else {
	abilityguard_uninstall_single_site();
}

abilityguard_uninstall_purge_staging();
