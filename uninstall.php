<?php
/**
 * Fired on plugin uninstall. Drops AbilityGuard tables + options.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'abilityguard_retention_prune' );

global $wpdb;

$tables = array(
	$wpdb->prefix . 'abilityguard_log',
	$wpdb->prefix . 'abilityguard_log_meta',
	$wpdb->prefix . 'abilityguard_snapshots',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'abilityguard_db_version' );
