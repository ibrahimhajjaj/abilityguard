<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Registry\RegistrationFilter;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\Collector\CollectorInterface;
use AbilityGuard\Snapshot\Collector\CollectorRegistry;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration test for the safety.collectors shorthand.
 *
 * Custom collector for an in-memory `_test_inbox` surface - captures + restores
 * a key/value array stored on a global so rollback is observable without
 * touching $wpdb tables.
 */
final class SafetyCollectorsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		CollectorRegistry::reset_for_tests();
		$GLOBALS['__ag_inbox_store'] = array();

		if ( ! wp_has_ability_category( 'abilityguard-tests' ) ) {
			$cb = static function ( $registry ): void {
				$registry->register(
					'abilityguard-tests',
					array(
						'label'       => 'AbilityGuard tests',
						'description' => 'Category for AbilityGuard integration tests.',
					)
				);
			};
			add_action( 'wp_abilities_api_categories_init', $cb );
			WP_Ability_Categories_Registry::get_instance();
			do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
			remove_action( 'wp_abilities_api_categories_init', $cb );
		}
	}

	protected function tearDown(): void {
		CollectorRegistry::reset_for_tests();
		unset( $GLOBALS['__ag_inbox_store'] );
		parent::tearDown();
	}

	public function test_safety_collectors_round_trip_capture_and_rollback(): void {
		$collector = new class() implements CollectorInterface {

			public function collect( $spec ): array {
				if ( ! is_array( $spec ) ) {
					return array();
				}
				$store = $GLOBALS['__ag_inbox_store'] ?? array();
				$out   = array();
				foreach ( $spec as $key ) {
					$key         = (string) $key;
					$out[ $key ] = $store[ $key ] ?? null;
				}
				return $out;
			}

			public function restore( array $captured ): void {
				$store = $GLOBALS['__ag_inbox_store'] ?? array();
				foreach ( $captured as $key => $value ) {
					if ( null === $value ) {
						unset( $store[ (string) $key ] );
					} else {
						$store[ (string) $key ] = $value;
					}
				}
				$GLOBALS['__ag_inbox_store'] = $store;
			}
		};

		// Bootstrap the registration pipeline so wp_register_ability_args fires.
		( new RegistrationFilter(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger()
		) )->register();

		// Seed inbox state before the ability runs.
		$GLOBALS['__ag_inbox_store'] = array( 'topic' => 'before' );

		$ability_name = 'abilityguard-tests/safety-collectors-' . uniqid();
		$registered   = WP_Abilities_Registry::get_instance()->register(
			$ability_name,
			array(
				'label'               => 'Test custom collector',
				'description'         => 'Exercises safety.collectors shorthand.',
				'category'            => 'abilityguard-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () {
					$GLOBALS['__ag_inbox_store']['topic'] = 'after';
					return array( 'ok' => true );
				},
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'snapshot'   => array(
						'inbox' => array( 'topic' ),
					),
					'collectors' => array(
						'inbox' => $collector,
					),
				),
			)
		);

		$this->assertNotNull( $registered, 'register() should accept safety.collectors' );
		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, 'ability must register cleanly with safety.collectors' );

		// Run it.
		$result = $ability->execute( array() );
		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 'after', $GLOBALS['__ag_inbox_store']['topic'], 'execute should mutate inbox' );

		// Find the log row and roll back.
		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertNotEmpty( $rows );
		$log_id = (int) $rows[0]['id'];

		$rollback = new RollbackService( $repo, new SnapshotStore() );
		$ok       = $rollback->rollback( $log_id );
		if ( is_wp_error( $ok ) ) {
			$this->fail( 'rollback returned WP_Error: ' . $ok->get_error_code() . ' - ' . $ok->get_error_message() );
		}
		$this->assertTrue( true === $ok, 'rollback should succeed via the custom collector' );

		// Custom collector restored the surface.
		$this->assertSame( 'before', $GLOBALS['__ag_inbox_store']['topic'] );
	}

	public function test_safety_collectors_cannot_silently_replace_built_in_surface(): void {
		$malicious = new class() implements CollectorInterface {

			public function collect( $spec ): array {
				return array( 'this' => 'should never be used' );
			}

			public function restore( array $captured ): void {
				$GLOBALS['__ag_malicious_called'] = true;
			}
		};

		CollectorRegistry::register( 'options', $malicious );

		// CollectorRegistry::defaults() must keep the real OptionsCollector
		// even though `options` was just registered as a custom collector.
		$collectors = CollectorRegistry::defaults();
		$this->assertInstanceOf(
			\AbilityGuard\Snapshot\Collector\OptionsCollector::class,
			$collectors['options'],
			'built-in surface name must not be overridable via the registry'
		);
	}
}
