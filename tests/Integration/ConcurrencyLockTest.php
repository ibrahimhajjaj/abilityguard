<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Concurrency\Lock;
use AbilityGuard\Installer;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration tests for the advisory lock layer.
 *
 * Exercises real MySQL GET_LOCK / RELEASE_LOCK behaviour via $wpdb.
 * The concurrency (fork) test is skipped when pcntl is unavailable.
 */
final class ConcurrencyLockTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		$this->ensure_test_category();
	}

	/**
	 * Register the ability category used by these tests.
	 */
	private function ensure_test_category(): void {
		if ( ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}
		if ( wp_has_ability_category( 'abilityguard-lock-tests' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'abilityguard-lock-tests',
				array(
					'label'       => 'AbilityGuard lock tests',
					'description' => 'Category for AbilityGuard concurrency lock tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	/**
	 * Register an ability via the registry directly, bypassing the doing_action guard.
	 *
	 * @param string   $name  Ability name.
	 * @param callable $build Returns the args array.
	 *
	 * @return \WP_Ability
	 */
	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail( "register() returned null for {$name}." );
		}
		return $result;
	}

	/**
	 * Basic acquire + release round-trip works against real $wpdb.
	 */
	public function test_acquire_and_release_round_trip(): void {
		$key = Lock::key_for_spec(
			array(
				'options' => array( '_abilityguard_lock_test_option' ),
			)
		);

		$this->assertStringStartsWith( 'abilityguard:', $key, 'Key must carry the namespace prefix.' );

		$acquired = Lock::acquire( $key, 2 );
		$this->assertTrue( $acquired, 'First acquire must succeed.' );

		$released = Lock::release( $key );
		$this->assertTrue( $released, 'Release by same connection must succeed.' );
	}

	/**
	 * Second invocation that finds the lock held returns WP_Error(429) with no log row.
	 */
	public function test_second_invocation_gets_lock_timeout_error(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		if ( ! class_exists( '\mysqli' ) ) {
			$this->markTestSkipped( 'mysqli ext required for cross-connection lock test' );
		}

		$ability_name = 'abilityguard-lock-tests/serialised-write';
		$post_id      = self::factory()->post->create();
		update_post_meta( $post_id, '_ag_lock_val', 'initial' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Serialised write',
				'description'         => 'Writes a post meta value; used to test lock contention.',
				'category'            => 'abilityguard-lock-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_lock_val', 'mutated' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'snapshot'     => static fn( $in ) => array(
						'post_meta' => array( (int) $in['post_id'] => array( '_ag_lock_val' ) ),
					),
					'lock_timeout' => 0,
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		// Hold the lock on a SEPARATE MySQL connection so the wrapper's
		// $wpdb-based acquire sees it as contested (MySQL GET_LOCK is
		// re-entrant per connection but blocks across connections).
		$spec     = array(
			'post_meta' => array( $post_id => array( '_ag_lock_val' ) ),
		);
		$lock_key = Lock::key_for_spec( $spec );

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		if ( $other->connect_errno ) {
			$this->markTestSkipped( 'Could not open a second MySQL connection: ' . $other->connect_error );
		}
		$stmt = $other->prepare( 'SELECT GET_LOCK(?, 0)' );
		$stmt->bind_param( 's', $lock_key );
		$stmt->execute();
		$got = $stmt->get_result()->fetch_row()[0] ?? null;
		$stmt->close();
		$this->assertSame( '1', (string) $got, 'External connection must hold the lock first.' );

		try {
			$result = $ability->execute( array( 'post_id' => $post_id ) );

			$this->assertInstanceOf( \WP_Error::class, $result, 'Should return WP_Error when lock is held.' );
			$this->assertSame( 'abilityguard_lock_timeout', $result->get_error_code() );

			$data = $result->get_error_data();
			$this->assertIsArray( $data );
			$this->assertSame( 429, $data['status'] );

			// The callback must NOT have mutated state.
			$this->assertSame( 'initial', get_post_meta( $post_id, '_ag_lock_val', true ) );

			// No log row must have been written.
			$logs = new LogRepository();
			$rows = $logs->list( array( 'ability_name' => $ability_name ) );
			$this->assertCount( 0, $rows, 'No audit row must be written on lock timeout.' );
		} finally {
			$rel = $other->prepare( 'SELECT RELEASE_LOCK(?)' );
			$rel->bind_param( 's', $lock_key );
			$rel->execute();
			$rel->close();
			$other->close();
		}
	}

	/**
	 * Disjoint specs (different post ids) produce different lock keys.
	 */
	public function test_disjoint_specs_produce_different_keys(): void {
		$key_a = Lock::key_for_spec(
			array( 'post_meta' => array( 100 => array( '_price' ) ) )
		);
		$key_b = Lock::key_for_spec(
			array( 'post_meta' => array( 200 => array( '_price' ) ) )
		);

		$this->assertNotSame( $key_a, $key_b, 'Different post ids must yield different lock keys.' );

		// Both should be acquirable simultaneously on the same connection.
		// MySQL advisory locks are re-entrant per key per connection.
		$this->assertTrue( Lock::acquire( $key_a, 0 ) );
		$this->assertTrue( Lock::acquire( $key_b, 0 ) );

		Lock::release( $key_a );
		Lock::release( $key_b );
	}

	/**
	 * Same spec always produces the same lock key (deterministic).
	 */
	public function test_same_spec_produces_same_key(): void {
		$spec = array(
			'post_meta' => array( 42 => array( '_price', '_regular_price' ) ),
			'options'   => array( 'woocommerce_last_price_change' ),
		);

		$key1 = Lock::key_for_spec( $spec );
		$key2 = Lock::key_for_spec( $spec );

		$this->assertSame( $key1, $key2 );
		$this->assertStringStartsWith( 'abilityguard:', $key1 );
	}

	/**
	 * Lock_timeout = -1 disables locking; execution succeeds even when the key is externally held.
	 */
	public function test_lock_disabled_skips_locking(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$ability_name = 'abilityguard-lock-tests/no-lock';
		$post_id      = self::factory()->post->create();
		update_post_meta( $post_id, '_ag_no_lock', 'before' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'No lock',
				'description'         => 'Runs without advisory locking.',
				'category'            => 'abilityguard-lock-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_no_lock', 'after' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'snapshot'     => static fn( $in ) => array(
						'post_meta' => array( (int) $in['post_id'] => array( '_ag_no_lock' ) ),
					),
					'lock_timeout' => -1,
				),
			)
		);

		$ability = wp_get_ability( $ability_name );

		// Acquire the would-be lock key externally - must NOT affect execution.
		$spec     = array( 'post_meta' => array( $post_id => array( '_ag_no_lock' ) ) );
		$lock_key = Lock::key_for_spec( $spec );
		$this->assertTrue( Lock::acquire( $lock_key, 0 ) );

		try {
			$result = $ability->execute( array( 'post_id' => $post_id ) );

			$this->assertNotInstanceOf( \WP_Error::class, $result, 'Disabled lock must not produce WP_Error.' );
			$this->assertSame( 'after', get_post_meta( $post_id, '_ag_no_lock', true ) );
		} finally {
			Lock::release( $lock_key );
		}
	}

	/**
	 * Approval-pending path acquires then releases lock before returning WP_Error(202).
	 */
	public function test_approval_pending_path_releases_lock_before_returning(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$ability_name = 'abilityguard-lock-tests/approval-lock-release';
		$post_id      = self::factory()->post->create();
		update_post_meta( $post_id, '_ag_approval_lock', 'stable' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Approval lock release',
				'description'         => 'Approval-gated; verifies lock is released after pending write.',
				'category'            => 'abilityguard-lock-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_approval_lock', 'mutated' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive'       => true,
					'requires_approval' => true,
					'lock_timeout'      => 2,
					'snapshot'          => static fn( $in ) => array(
						'post_meta' => array( (int) $in['post_id'] => array( '_ag_approval_lock' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_pending_approval', $result->get_error_code() );

		// Lock must be released - we must be able to re-acquire on this connection.
		$spec     = array( 'post_meta' => array( $post_id => array( '_ag_approval_lock' ) ) );
		$lock_key = Lock::key_for_spec( $spec );

		$acquired = Lock::acquire( $lock_key, 0 );
		$this->assertTrue( $acquired, 'Lock must be released after the approval-pending path returns.' );
		Lock::release( $lock_key );

		// Audit row must exist and be pending.
		$logs = new LogRepository();
		$rows = $logs->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
	}

	/**
	 * Key_for_spec is order-independent across surfaces and meta keys.
	 */
	public function test_key_for_spec_multi_surface_is_order_independent(): void {
		$spec_a = array(
			'post_meta' => array( 42 => array( '_price', '_regular_price' ) ),
			'options'   => array( 'woocommerce_last_price_change' ),
		);

		$spec_b = array(
			'options'   => array( 'woocommerce_last_price_change' ),
			'post_meta' => array( 42 => array( '_regular_price', '_price' ) ),
		);

		$this->assertSame( Lock::key_for_spec( $spec_a ), Lock::key_for_spec( $spec_b ) );
	}

	/**
	 * Ability with no snapshot configured skips locking and executes normally.
	 */
	public function test_empty_spec_skips_lock(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$ability_name = 'abilityguard-lock-tests/no-snapshot';
		$post_id      = self::factory()->post->create();

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'No snapshot',
				'description'         => 'No snapshot configured; lock must be skipped.',
				'category'            => 'abilityguard-lock-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => array( 'noop' => true ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'lock_timeout' => 0,
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'Empty spec must skip locking and succeed.' );
	}
}
