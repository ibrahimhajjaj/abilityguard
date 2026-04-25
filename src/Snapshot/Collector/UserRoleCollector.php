<?php
/**
 * User role snapshot collector.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Snapshot\Collector;

use WP_User;

/**
 * Collects + restores user roles and capabilities.
 *
 * Spec shape:    int[] $user_ids.
 * Captured shape: array<int $user_id, array{ roles: string[], caps: array<string, bool> }>.
 */
final class UserRoleCollector implements CollectorInterface {

	/**
	 * Read current roles and caps for a list of user ids.
	 *
	 * @param mixed $spec int[].
	 *
	 * @return array<int, array{roles: string[], caps: array<string, bool>}>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}

		$out = array();

		foreach ( $spec as $user_id ) {
			$user_id = (int) $user_id;
			$user    = new WP_User( $user_id );

			if ( ! $user->exists() ) {
				continue;
			}

			$caps = array_map( 'boolval', $user->caps );

			$out[ $user_id ] = array(
				'roles' => array_values( $user->roles ),
				'caps'  => $caps,
			);
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Restore roles and caps to captured state. Silently skips missing users.
	 *
	 * @param array<mixed> $captured See collect() output.
	 */
	public function restore( array $captured ): void {
		foreach ( $captured as $user_id => $state ) {
			$user_id = (int) $user_id;
			$user    = new WP_User( $user_id );

			if ( ! $user->exists() ) {
				continue;
			}

			if ( ! is_array( $state ) ) {
				continue;
			}

			// Clear all existing roles.
			$user->set_role( '' );

			// Restore each captured role.
			$roles = isset( $state['roles'] ) && is_array( $state['roles'] ) ? $state['roles'] : array();
			foreach ( $roles as $role ) {
				$user->add_role( (string) $role );
			}

			// Restore each captured capability.
			$caps = isset( $state['caps'] ) && is_array( $state['caps'] ) ? $state['caps'] : array();
			foreach ( $caps as $cap => $granted ) {
				$user->add_cap( (string) $cap, (bool) $granted );
			}
		}
	}
}
