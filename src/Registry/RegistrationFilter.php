<?php
/**
 * Hooks wp_register_ability_args to intercept safety-enabled abilities.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;

/**
 * Inserts the AbilityGuard wrapper into execute_callback when the ability's
 * registration args include a `safety` sub-array, then strips `safety` before
 * the core Abilities API validates the args.
 */
final class RegistrationFilter {

	/**
	 * Constructor.
	 *
	 * @param SnapshotServiceInterface $snapshots Snapshot collaborator.
	 * @param AuditLoggerInterface     $audit     Audit collaborator.
	 */
	public function __construct(
		private SnapshotServiceInterface $snapshots,
		private AuditLoggerInterface $audit
	) {}

	/**
	 * Register the filter. Idempotent.
	 */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( $this, 'filter_args' ), 10, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param array<string, mixed> $args Ability args.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed> Possibly-mutated args.
	 */
	public function filter_args( array $args, string $name ): array {
		if ( empty( $args['safety'] ) || ! is_array( $args['safety'] ) ) {
			return $args;
		}
		if ( empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$wrapper                  = new AbilityWrapper( $this->snapshots, $this->audit, $name, $args['safety'] );
		$args['execute_callback'] = $wrapper->wrap( $args['execute_callback'] );

		// Strip the non-core key so the core registry's schema validation doesn't complain.
		unset( $args['safety'] );

		return $args;
	}
}
