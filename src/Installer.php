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
	public const DB_VERSION        = '5';

	/**
	 * Activation hook.
	 *
	 * On a multisite network-activate, walks every subsite in the current
	 * network and runs the per-site activation routine on each. Single-site
	 * installs (or per-site activation of a network-deployed plugin) hit
	 * just the current site. Multinetwork installs are filtered to the
	 * current network only - sister networks are left alone.
	 *
	 * @param bool $network_wide Passed by `register_activation_hook()` when
	 *                           the plugin is being network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( self::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_single_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_single_site();
	}

	/**
	 * Deactivation hook. Clears the retention cron; data is preserved.
	 *
	 * Mirrors `activate()` for the network-wide case.
	 *
	 * @param bool $network_wide Passed by `register_deactivation_hook()`.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( self::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				( new Retention\Scheduler() )->unschedule();
				restore_current_blog();
			}
			return;
		}
		( new Retention\Scheduler() )->unschedule();
	}

	/**
	 * Per-subsite activation: install tables, schedule cron, grant cap.
	 *
	 * Used for both single-site activation and the network-wide loop. Also
	 * called from the `wp_initialize_site` listener so freshly-created
	 * subsites on an already-active network get the same treatment.
	 */
	public static function activate_single_site(): void {
		self::install();
		( new Retention\Scheduler() )->schedule();
		Approval\CapabilityManager::grant_to_administrators();
	}

	/**
	 * Subsite IDs in the current network. Filters by `$wpdb->siteid` so
	 * multinetwork installs (rare) don't accidentally touch sister networks.
	 *
	 * @return int[]
	 */
	public static function network_site_ids(): array {
		if ( ! function_exists( 'get_sites' ) || ! function_exists( 'get_current_network_id' ) ) {
			return array();
		}
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'network_id' => get_current_network_id(),
				'number'     => 0,
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Wire the subsite-lifecycle hooks. No-op on single-site installs.
	 *
	 * - `wp_initialize_site`: a fresh subsite was just created - install
	 *   tables and grant the approval cap on it so audit/rollback works
	 *   from the moment that subsite goes live.
	 * - `wpmu_drop_tables`: a subsite is being deleted - register our
	 *   four tables for cleanup so they go down with the subsite instead
	 *   of becoming orphans.
	 *
	 * Both hooks are idempotent: re-firing them on an already-installed
	 * subsite is harmless because dbDelta and `add_role` are themselves
	 * idempotent.
	 */
	public static function register_multisite_hooks(): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		add_action(
			'wp_initialize_site',
			static function ( $new_site ): void {
				if ( ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
					return;
				}
				if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( ! is_plugin_active_for_network( plugin_basename( ABILITYGUARD_FILE ) ) ) {
					return;
				}
				switch_to_blog( (int) $new_site->blog_id );
				self::activate_single_site();
				restore_current_blog();
			},
			10,
			1
		);

		add_filter(
			'wpmu_drop_tables',
			static function ( array $tables ): array {
				global $wpdb;
				foreach ( array( 'abilityguard_log', 'abilityguard_log_meta', 'abilityguard_snapshots', 'abilityguard_approvals', 'abilityguard_approval_stages' ) as $name ) {
					$tables[] = $wpdb->prefix . $name;
				}
				return $tables;
			}
		);
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
	 * Create / upgrade tables via dbDelta on the current site.
	 *
	 * Always operates against `$wpdb->prefix` - under a `switch_to_blog()`
	 * call this points at the correct subsite's tables. Idempotent.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$log             = $wpdb->prefix . 'abilityguard_log';
		$log_meta        = $wpdb->prefix . 'abilityguard_log_meta';
		$snapshots       = $wpdb->prefix . 'abilityguard_snapshots';
		$approvals       = $wpdb->prefix . 'abilityguard_approvals';
		$approval_stages = $wpdb->prefix . 'abilityguard_approval_stages';

		$sql = array();

		$sql[] = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invocation_id char(36) NOT NULL,
			parent_invocation_id char(36) NULL,
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
			KEY parent_invocation_id (parent_invocation_id),
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

		$sql[] = "CREATE TABLE {$approvals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) NOT NULL,
			ability_name varchar(191) NOT NULL,
			input_json longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY log_id (log_id),
			KEY status (status),
			KEY ability_name (ability_name)
		) {$charset_collate};";

		// Per-stage rows for multi-stage approvals (v1.1+). Single-stage
		// approvals (default `safety.requires_approval = true`) write zero
		// rows here and the existing approvals.status field tracks the
		// whole decision - preserves v1.0 behavior exactly.
		// `required_count` enables parallel ("N of M must approve") stages.
		// `required_user_id` (nullable) pins a stage to a specific approver.
		// When both required_cap and required_user_id are set, BOTH must match.
		// `decision_count` tracks how many approves the stage has accumulated;
		// stage advances only when decision_count >= required_count.
		$sql[] = "CREATE TABLE {$approval_stages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			approval_id bigint(20) unsigned NOT NULL,
			stage_index smallint(5) unsigned NOT NULL,
			required_cap varchar(191) NOT NULL,
			required_user_id bigint(20) unsigned NULL,
			required_count smallint(5) unsigned NOT NULL DEFAULT 1,
			decision_count smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY approval_stage (approval_id, stage_index),
			KEY status (status)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Table name helper.
	 *
	 * @param 'log'|'log_meta'|'snapshots'|'approvals'|'approval_stages' $which Table key.
	 */
	public static function table( string $which ): string {
		global $wpdb;
		return $wpdb->prefix . 'abilityguard_' . $which;
	}
}
