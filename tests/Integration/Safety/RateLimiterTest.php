<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Safety;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Safety\RateLimiter;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_Error;
use WP_UnitTestCase;

/**
 * Integration tests for src/Safety/RateLimiter.php. Each test registers an
 * ability through the abilities-api so the full wrap fires (observer +
 * enforcement filter + RateLimiter callback).
 */
final class RateLimiterTest extends WP_UnitTestCase {

	/**
	 * Track every key we touch so we can purge in tearDown without leaking
	 * state between tests.
	 *
	 * @var string[]
	 */
	private array $keys_to_purge = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_test_category();
	}

	protected function tearDown(): void {
		foreach ( $this->keys_to_purge as $key ) {
			delete_transient( $key );
		}
		$this->keys_to_purge = array();

		// Reset filter state so window-tweak filters from one test don't
		// bleed into the next.
		RateLimiter::reset_for_tests();
		RateLimiter::register();

		parent::tearDown();
	}

	private function ensure_test_category(): void {
		if ( wp_has_ability_category( 'abilityguard-tests' ) ) {
			return;
		}
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

	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		$this->assertNotNull( $result );
		return $result;
	}

	/**
	 * Mirrors the private keying logic so tests can purge transients.
	 */
	private function transient_key_for( int $user_id, string $ability_name ): string {
		$key = 'abilityguard_rl_' . $user_id . '_' . substr( md5( $ability_name ), 0, 16 );
		$this->keys_to_purge[] = $key;
		return $key;
	}

	private function register_counter_ability( string $name, int $limit ): \WP_Ability {
		return $this->register_via_init(
			$name,
			static fn() => array(
				'label'               => 'Counter',
				'description'         => 'Increments a static counter for assertion purposes.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array(
					'max_calls_per_hour' => $limit,
					'skip_drift_check'   => true,
				),
			)
		);
	}

	public function test_under_limit_calls_succeed(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability_name = 'abilityguard-tests/rl-under';
		$this->transient_key_for( $user_id, $ability_name );

		$ability = $this->register_counter_ability( $ability_name, 3 );

		for ( $i = 0; $i < 3; $i++ ) {
			$response = $ability->execute( array() );
			$this->assertSame( array( 'ok' => true ), $response, "call $i should succeed" );
		}

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'ok', $row['status'] );
		}
	}

	public function test_n_plus_one_call_is_blocked_with_429(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability_name = 'abilityguard-tests/rl-cap';
		$this->transient_key_for( $user_id, $ability_name );

		$ability = $this->register_counter_ability( $ability_name, 2 );

		$ability->execute( array() );
		$ability->execute( array() );

		$response = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'abilityguard_rate_limited', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 2, $data['limit'] );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThanOrEqual( 0, $data['retry_after'] );

		// The blocked call still produces an audit row, finalized as error
		// (the wrap's pre-execute decision short-circuit path).
		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	public function test_different_users_get_different_buckets(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$ability_name = 'abilityguard-tests/rl-per-user';
		$this->transient_key_for( $user_a, $ability_name );
		$this->transient_key_for( $user_b, $ability_name );

		$ability = $this->register_counter_ability( $ability_name, 1 );

		wp_set_current_user( $user_a );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		$blocked = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'abilityguard_rate_limited', $blocked->get_error_code() );

		// User B starts fresh.
		wp_set_current_user( $user_b );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
	}

	public function test_different_abilities_get_different_buckets(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability_x = 'abilityguard-tests/rl-x';
		$ability_y = 'abilityguard-tests/rl-y';
		$this->transient_key_for( $user_id, $ability_x );
		$this->transient_key_for( $user_id, $ability_y );

		$x = $this->register_counter_ability( $ability_x, 1 );
		$y = $this->register_counter_ability( $ability_y, 1 );

		$this->assertSame( array( 'ok' => true ), $x->execute( array() ) );
		$blocked = $x->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $blocked );

		// Y is still callable.
		$this->assertSame( array( 'ok' => true ), $y->execute( array() ) );
	}

	public function test_window_seconds_filter_changes_transient_ttl(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability_name = 'abilityguard-tests/rl-window';
		$key          = $this->transient_key_for( $user_id, $ability_name );

		$shrink = static fn(): int => 60;
		add_filter( 'abilityguard_rate_limit_window_seconds', $shrink );

		try {
			$ability = $this->register_counter_ability( $ability_name, 5 );
			$ability->execute( array() );

			// WP stores transient expiry as an absolute timestamp under
			// _transient_timeout_<key>. With a 60s window it must land in
			// the [now, now+60] band.
			$timeout = (int) get_option( '_transient_timeout_' . $key, 0 );
			$this->assertGreaterThan( 0, $timeout );
			$this->assertLessThanOrEqual( time() + 60, $timeout );
			$this->assertGreaterThan( time(), $timeout );
		} finally {
			remove_filter( 'abilityguard_rate_limit_window_seconds', $shrink );
		}
	}

	public function test_no_max_calls_per_hour_config_is_no_op(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability_name = 'abilityguard-tests/rl-off';

		$ability = $this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'No rate limit',
				'description'         => 'Ability without max_calls_per_hour, no quota enforcement.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array(
					'skip_drift_check' => true,
				),
			)
		);

		// 10 calls with no config should all pass.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		}

		// Counter transient was never written.
		$key = 'abilityguard_rl_' . $user_id . '_' . substr( md5( $ability_name ), 0, 16 );
		$this->assertFalse( get_transient( $key ) );
	}
}
