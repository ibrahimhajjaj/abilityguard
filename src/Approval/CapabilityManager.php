<?php
/**
 * Manages the manage_abilityguard_approvals capability lifecycle.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Approval;

/**
 * Grants and removes the custom approval capability across WordPress roles.
 *
 * Lifecycle:
 *  - grant_to_administrators() - called on plugin activation. Assigns
 *    `manage_abilityguard_approvals` to the `administrator` role so at least
 *    one role can approve on a fresh install. Site owners may subsequently
 *    grant the cap to a dedicated "approver" role and revoke it from
 *    administrators to enforce true separation of duties.
 *  - remove_from_all_roles() - called only on uninstall. Strips the cap from
 *    every registered role to leave no footprint after the plugin is deleted.
 *    Deactivation intentionally leaves caps intact so they survive
 *    deactivate→reactivate cycles.
 */
final class CapabilityManager {

	/**
	 * Capability slug.
	 */
	public const CAP = 'manage_abilityguard_approvals';

	/**
	 * Grant the approval capability to the administrator role.
	 *
	 * Safe to call multiple times (idempotent).
	 */
	public static function grant_to_administrators(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		$role->add_cap( self::CAP );
	}

	/**
	 * Remove the approval capability from every registered role.
	 *
	 * Called only from uninstall.php.
	 */
	public static function remove_from_all_roles(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_slug ) {
			$role = get_role( (string) $role_slug );
			if ( null === $role ) {
				continue;
			}
			$role->remove_cap( self::CAP );
		}
	}
}
