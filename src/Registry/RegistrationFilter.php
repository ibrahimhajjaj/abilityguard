<?php
/**
 * Hooks wp_register_ability_args to intercept safety-enabled abilities.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;
use AbilityGuard\Snapshot\Collector\CollectorInterface;
use AbilityGuard\Snapshot\Collector\CollectorRegistry;

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
		// During approval execution, skip wrapping to avoid re-queueing.
		if ( ApprovalService::is_approving() ) {
			return $args;
		}

		if ( empty( $args['safety'] ) || ! is_array( $args['safety'] ) ) {
			return $args;
		}
		if ( empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		// Mirror core's meta.annotations.destructive into the safety array so
		// the wrapper sees a single boolean. Core defines the canonical key
		// in WP 6.9; we don't carry our own.
		if ( isset( $args['meta']['annotations']['destructive'] ) && null !== $args['meta']['annotations']['destructive'] ) {
			$args['safety']['destructive'] = (bool) $args['meta']['annotations']['destructive'];
		}

		// Register custom collectors declared on this ability so SnapshotService
		// + RollbackService can find them at capture and rollback time. Keys
		// matching built-in surfaces are silently ignored by CollectorRegistry,
		// since silently swapping the file/options collectors site-wide would
		// be a footgun far worse than the boilerplate it would save.
		if ( isset( $args['safety']['collectors'] ) && is_array( $args['safety']['collectors'] ) ) {
			foreach ( $args['safety']['collectors'] as $surface => $collector ) {
				if ( is_string( $surface ) && $collector instanceof CollectorInterface ) {
					CollectorRegistry::register( $surface, $collector );
				}
			}
			// Keep the rest of safety[] intact; just drop `collectors` so the
			// AbilityWrapper doesn't have to reason about it.
			unset( $args['safety']['collectors'] );
		}

		// Hand the resolved safety to the observer so the WP 6.9
		// before-hook listener can find it without re-reading registry args.
		InvocationObserver::register_safety( $name, $args['safety'] );

		$wrapper                  = new AbilityWrapper( $this->snapshots, $this->audit, $name, $args['safety'] );
		$args['execute_callback'] = $wrapper->wrap( $args['execute_callback'] );

		// Strip the non-core key so the core registry's schema validation doesn't complain.
		unset( $args['safety'] );

		return $args;
	}

}
