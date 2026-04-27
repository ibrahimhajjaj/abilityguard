<?php
/**
 * Plugin bootstrap.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard;

/**
 * Top-level service wiring for AbilityGuard.
 *
 * Intentionally thin: hooks get registered here, concrete work lives in
 * the service classes under src/. No singleton state beyond the boot guard
 * so tests can instantiate collaborators directly.
 */
final class Plugin {

	/**
	 * Boot guard to prevent double-registration on plugins_loaded.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Entry point. Called on plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Installer::maybe_upgrade();
		Installer::register_multisite_hooks();

		Registry\McpContext::register();

		$snapshots = new Snapshot\SnapshotService( new Snapshot\SnapshotStore() );
		$audit     = new Audit\AuditLogger();

		$registration = new Registry\RegistrationFilter( $snapshots, $audit );
		$registration->register();

		( new Registry\InvocationObserver( $snapshots, $audit ) )->register();

		( new Retention\Scheduler() )->register();

		Cli\Command::register();
		Admin\AdminMenu::register();
		Admin\DashboardWidget::register();
		Admin\RestController::register();

		do_action( 'abilityguard_booted' );
	}

	/**
	 * Reset boot state. Test-only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$booted = false;
	}
}
