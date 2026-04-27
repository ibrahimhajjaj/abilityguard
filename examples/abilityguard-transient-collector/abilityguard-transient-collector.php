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
 * Wires the collector via safety.collectors. This is the v0.8+ idiomatic
 * way: declare the surface in safety.snapshot and pass an instance under
 * safety.collectors with the matching key. AbilityGuard handles capture,
 * post-state snapshot, drift detection, and restore - no side-channel
 * options, no global invocation_id chasing.
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
					return array(
						'ok'  => true,
						'key' => $key,
					);
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'snapshot'   => static fn( $input ) => array(
						'transient' => array( (string) ( $input['key'] ?? '' ) ),
					),
					'collectors' => array(
						'transient' => new TransientCollector(),
					),
				),
			)
		);
	}
);
