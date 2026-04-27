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

		// Reconcile destructive flag with core's meta.annotations (WP 6.9).
		// Source of truth is `meta.annotations.destructive`; `safety.destructive`
		// is a legacy alias kept working via the shim below.
		$args = $this->reconcile_destructive( $args, $name );

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

		$wrapper                  = new AbilityWrapper( $this->snapshots, $this->audit, $name, $args['safety'] );
		$args['execute_callback'] = $wrapper->wrap( $args['execute_callback'] );

		// Strip the non-core key so the core registry's schema validation doesn't complain.
		unset( $args['safety'] );

		return $args;
	}

	/**
	 * Names already warned for, so the deprecation fires at most once per ability.
	 *
	 * @var array<string, true>
	 */
	private static array $warned_legacy_destructive = array();

	/**
	 * Reconcile destructive flag between legacy `safety.destructive` and core's
	 * `meta.annotations.destructive` (added to WP_Ability in WordPress 6.9).
	 *
	 * Resolution:
	 *   1. If `meta.annotations.destructive` is set, that wins.
	 *   2. Else if `safety.destructive` is set, mirror it into meta.annotations
	 *      and emit a one-time `_doing_it_wrong`.
	 * In either case the resolved value is also written back into
	 * `safety.destructive` so the AbilityWrapper (which still reads from the
	 * safety array at execute time) sees the same value.
	 *
	 * @param array<string, mixed> $args Ability args.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed>
	 */
	private function reconcile_destructive( array $args, string $name ): array {
		$annotations = array();
		if ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) ) {
			$annotations = $args['meta']['annotations'];
		}

		$has_legacy = array_key_exists( 'destructive', $args['safety'] );
		$has_meta   = array_key_exists( 'destructive', $annotations ) && null !== $annotations['destructive'];

		if ( $has_legacy ) {
			if ( ! isset( self::$warned_legacy_destructive[ $name ] ) ) {
				self::$warned_legacy_destructive[ $name ] = true;
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong(
						'AbilityGuard',
						"AbilityGuard: 'safety.destructive' is deprecated. Use 'meta.annotations.destructive' (shipped in WordPress 6.9).",
						'1.3.0'
					);
				}
			}

			if ( ! $has_meta ) {
				$annotations['destructive'] = (bool) $args['safety']['destructive'];
				$args['meta']                = is_array( $args['meta'] ?? null ) ? $args['meta'] : array();
				$args['meta']['annotations'] = $annotations;
			}
		}

		// Make sure the wrapper sees the canonical value. After this, both
		// surfaces agree.
		if ( array_key_exists( 'destructive', $annotations ) && null !== $annotations['destructive'] ) {
			$args['safety']['destructive'] = (bool) $annotations['destructive'];
		}

		return $args;
	}
}
