<?php
/**
 * Schema installer for AbilityGuard custom tables.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard;

/**
 * Creates and upgrades the AbilityGuard custom tables via dbDelta.
 *
 * Three tables, mirroring the wp-stream split but with a dedicated
 * snapshots table since forward-only logs aren't enough for rollback:
 *
 *  - wp_abilityguard_log       : one row per ability invocation.
 *  - wp_abilityguard_log_meta  : extensible key/value attached to a log row.
 *  - wp_abilityguard_snapshots : compressed pre-state captured before invocation.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'abilityguard_db_version';
	public const DB_VERSION        = '2';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
		( new Retention\Scheduler() )->schedule();
	}

	/**
	 * Deactivation hook. Clears the retention cron; data is preserved.
	 */
	public static function deactivate(): void {
		( new Retention\Scheduler() )->unschedule();
	}

	/**
	 * Run on every plugins_loaded; installs or upgrades as needed.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( self::DB_VERSION_OPTION );
		if ( self::DB_VERSION !== $current ) {
			self::install();
		}
	}

	/**
	 * Create / upgrade tables via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$log             = $wpdb->prefix . 'abilityguard_log';
		$log_meta        = $wpdb->prefix . 'abilityguard_log_meta';
		$snapshots       = $wpdb->prefix . 'abilityguard_snapshots';

		$sql = array();

		$sql[] = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invocation_id char(36) NOT NULL,
			ability_name varchar(191) NOT NULL,
			caller_type varchar(20) NOT NULL DEFAULT 'internal',
			caller_id varchar(191) NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			args_json longtext NULL,
			result_json longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'ok',
			destructive tinyint(1) NOT NULL DEFAULT 0,
			duration_ms int(11) unsigned NOT NULL DEFAULT 0,
			pre_hash char(64) NULL,
			post_hash char(64) NULL,
			snapshot_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invocation_id (invocation_id),
			KEY ability_name (ability_name),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY status (status),
			KEY caller_id (caller_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$log_meta} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL,
			meta_key varchar(191) NOT NULL,
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY log_id (log_id),
			KEY meta_key (meta_key)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$snapshots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invocation_id char(36) NOT NULL,
			surfaces_json longblob NOT NULL,
			pre_hash char(64) NOT NULL,
			post_state_json longblob NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invocation_id (invocation_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Table name helper.
	 *
	 * @param 'log'|'log_meta'|'snapshots' $which Table key.
	 */
	public static function table( string $which ): string {
		global $wpdb;
		return $wpdb->prefix . 'abilityguard_' . $which;
	}
}
