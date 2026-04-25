<?php
/**
 * Plugin Name:       AbilityGuard Transient Collector (example)
 * Description:       Reference implementation of a custom CollectorInterface.
 * Version:           0.0.1
 * Requires Plugins:  abilityguard
 *
 * @package AbilityGuardTransientCollectorExample
 */

declare( strict_types=1 );

namespace AbilityGuard\Examples\TransientCollector;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Snapshot\Collector\CollectorInterface;

/**
 * Captures + restores a list of transients.
 *
 * Spec shape:    string[] $transient_keys (raw key, no `_transient_` prefix)
 * Captured shape: array<string, array{value: mixed, expires_at: ?int}|null>
 *                 null means the transient did not exist at snapshot time.
 *
 * Why this is a separate example plugin and not built into AbilityGuard:
 * transients are a cache surface; restoring them is a judgement call (some
 * teams want them rebuilt by the next cache miss, others want them
 * preserved). Distributed as an opt-in example so authors can lift it.
 */
final class TransientCollector implements CollectorInterface {

	/**
	 * Read current values for the declared transients.
	 *
	 * @param mixed $spec string[] of transient keys.
	 *
	 * @return array<string, array{value: mixed, expires_at: ?int}|null>
	 */
	public function collect( $spec ): array {
		if ( ! is_array( $spec ) ) {
			return array();
		}

		$out = array();
		foreach ( $spec as $key ) {
			$key   = (string) $key;
			$value = get_transient( $key );

			if ( false === $value ) {
				$out[ $key ] = null;
				continue;
			}

			// Read the timeout option directly so we can restore the same
			// expiry. get_option returns false when no timeout is set
			// (transients without expiry); store as null to differentiate.
			$timeout     = get_option( '_transient_timeout_' . $key );
			$out[ $key ] = array(
				'value'      => $value,
				'expires_at' => false === $timeout ? null : (int) $timeout,
			);
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Restore previously-captured transient values.
	 *
	 * @param array<string, array{value: mixed, expires_at: ?int}|null> $captured Captured payload.
	 */
	public function restore( array $captured ): void {
		$now = time();
		foreach ( $captured as $key => $state ) {
			$key = (string) $key;

			if ( null === $state ) {
				delete_transient( $key );
				continue;
			}

			$expiration = 0;
			if ( null !== ( $state['expires_at'] ?? null ) ) {
				$expiration = max( 1, (int) $state['expires_at'] - $now );
			}

			set_transient( $key, $state['value'], $expiration );
		}
	}
}

/**
 * Workaround pattern (current): use safety.snapshot to capture via your own
 * collector, persist captured state alongside the invocation, and restore via
 * the abilityguard_rollback action. Until safety.collectors lands, this is
 * the canonical extensibility seam.
 */
add_action(
	'wp_abilities_api_categories_init',
	static function ( $registry ): void {
		$registry->register(
			'examples-transient',
			array(
				'label'       => 'Transient collector example',
				'description' => 'Reference for custom CollectorInterface implementations.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'examples-transient/refresh-cache',
			array(
				'label'               => 'Refresh a cached value',
				'description'         => 'Overwrites a transient. Demonstrates a TransientCollector round-trip.',
				'category'            => 'examples-transient',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array( 'type' => 'string' ),
						'value' => array( 'type' => 'string' ),
					),
					'required'   => array( 'key', 'value' ),
				),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'execute_callback'    => static function ( $input ): array {
					$key = (string) $input['key'];
					set_transient( $key, (string) $input['value'], HOUR_IN_SECONDS );

					// Workaround: capture-and-stash on the side. AbilityGuard's
					// built-in surfaces don't know about transients, so we hand
					// the captured payload to a per-invocation option and pick
					// it up in the rollback handler below.
					$collector = new TransientCollector();
					$captured  = $collector->collect( array( $key ) );

					$invocation_id = $GLOBALS['abilityguard_current_invocation_id'] ?? null;
					if ( null === $invocation_id ) {
						// Fall back: read the most recent log row's invocation_id.
						global $wpdb;
						$invocation_id = (string) $wpdb->get_var(
							"SELECT invocation_id FROM {$wpdb->prefix}abilityguard_log ORDER BY id DESC LIMIT 1"
						);
					}
					if ( '' !== (string) $invocation_id ) {
						update_option( 'examples_transient_snap_' . $invocation_id, $captured, false );
					}

					return array(
						'ok'  => true,
						'key' => $key,
					);
				},
				// Declares post_meta=[] just so AbilityGuard treats this as a
				// safety-bearing ability. The real snapshot is taken inside
				// execute_callback via the workaround above.
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => static fn() => array(),
				),
			)
		);
	}
);

add_action(
	'abilityguard_invocation_started',
	static function ( string $invocation_id ): void {
		$GLOBALS['abilityguard_current_invocation_id'] = $invocation_id;
	}
);

add_action(
	'abilityguard_rollback',
	static function ( array $log ): void {
		if ( 'examples-transient/refresh-cache' !== ( $log['ability_name'] ?? '' ) ) {
			return;
		}
		$key      = 'examples_transient_snap_' . $log['invocation_id'];
		$captured = get_option( $key );
		if ( is_array( $captured ) ) {
			( new TransientCollector() )->restore( $captured );
			delete_option( $key );
		}
	},
	10,
	1
);
