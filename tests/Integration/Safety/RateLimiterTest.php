<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Safety;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Safety\RateLimit\Storage;
use AbilityGuard\Safety\RateLimiter;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Integration tests for the multi-policy sliding-window-counter rate
 * limiter. Each test registers an ability through the abilities-api so the
 * full wrap fires (observer + enforcement filter + RateLimiter callback).
 */
final class RateLimiterTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		$this->ensure_test_category();
	}

	protected function tearDown(): void {
		// Remove any clock filter we may have installed.
		remove_all_filters( 'abilityguard_rate_limit_now' );
		remove_all_filters( 'abilityguard_rate_limit_principal' );
		remove_all_filters( 'abilityguard_rate_limiter_storage' );
		// Drop the wp_cache group used by Redis/ObjectCache impls.
		wp_cache_flush();

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
	 * Register a test ability with the given rate-limit policies.
	 *
	 * @param string                                            $name     Ability name.
	 * @param array<int, array{id:string,limit:int,window:int}> $policies Policy entries.
	 */
	private function register_ability( string $name, array $policies ): \WP_Ability {
		return $this->register_via_init(
			$name,
			static fn() => array(
				'label'               => 'Counter',
				'description'         => 'Test ability.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array(
					'rate_limits'      => array( 'policies' => $policies ),
					'skip_drift_check' => true,
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// Ported from the previous suite.
	// -----------------------------------------------------------------

	/**
	 * No policies declared means the limiter is a no-op.
	 */
	public function test_no_policies_is_no_op(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_via_init(
			'abilityguard-tests/rl-noop',
			static fn() => array(
				'label'               => 'No rate limit',
				'description'         => 'Ability with no policies declared.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'safety'              => array( 'skip_drift_check' => true ),
			)
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		}
	}

	public function test_zero_limit_policy_is_skipped(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-zero',
			array(
				array(
					'id'     => 'noop',
					'limit'  => 0,
					'window' => 60,
				),
			)
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		}
	}

	public function test_earlier_handler_block_is_respected(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$earlier = static fn() => new WP_Error( 'first', 'blocked', array( 'status' => 451 ) );
		add_filter( 'abilityguard_pre_execute_decision', $earlier, 5 );

		try {
			$ability  = $this->register_ability(
				'abilityguard-tests/rl-earlier',
				array(
					array(
						'id'     => 'sustained',
						'limit'  => 1,
						'window' => 60,
					),
				)
			);
			$response = $ability->execute( array() );
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertSame( 'first', $response->get_error_code() );
		} finally {
			remove_filter( 'abilityguard_pre_execute_decision', $earlier, 5 );
		}
	}

	public function test_per_user_per_ability_isolation(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$ability = $this->register_ability(
			'abilityguard-tests/rl-iso',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 1,
					'window' => 60,
				),
			)
		);

		wp_set_current_user( $user_a );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		$blocked = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $blocked );

		// User B starts fresh.
		wp_set_current_user( $user_b );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
	}

	// -----------------------------------------------------------------
	// Multi-policy block variants.
	// -----------------------------------------------------------------

	/**
	 * Burst policy blocks while sustained still has headroom.
	 */
	public function test_burst_blocks_but_sustained_does_not(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-burst-only',
			array(
				array(
					'id'     => 'burst',
					'limit'  => 2,
					'window' => 1,
				),
				array(
					'id'     => 'sustained',
					'limit'  => 99,
					'window' => 60,
				),
			)
		);

		// Two calls land in the same 1-second bucket and exhaust burst.
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );

		$resp = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'abilityguard_rate_limited', $resp->get_error_code() );

		$data = $resp->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$ids = array_column( $data['policies'], 'id' );
		$this->assertContains( 'burst', $ids );
		$this->assertNotContains( 'sustained', $ids );
	}

	public function test_sustained_blocks_but_burst_is_fresh(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// Walk the clock: each call lands in a fresh 1s burst bucket but
		// piles up under the 60s sustained window.
		$now = 1_700_000_000;
		add_filter(
			'abilityguard_rate_limit_now',
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$ability = $this->register_ability(
			'abilityguard-tests/rl-sus-only',
			array(
				array(
					'id'     => 'burst',
					'limit'  => 5,
					'window' => 1,
				),
				array(
					'id'     => 'sustained',
					'limit'  => 3,
					'window' => 60,
				),
			)
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( array( 'ok' => true ), $ability->execute( array() ), "call $i" );
			++$now; // next 1s bucket.
		}

		$resp = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$data = $resp->get_error_data();
		$ids  = array_column( $data['policies'], 'id' );
		$this->assertContains( 'sustained', $ids );
		$this->assertNotContains( 'burst', $ids );
	}

	public function test_both_policies_block(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-both',
			array(
				array(
					'id'     => 'burst',
					'limit'  => 2,
					'window' => 1,
				),
				array(
					'id'     => 'sustained',
					'limit'  => 2,
					'window' => 60,
				),
			)
		);

		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );

		$resp = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$data = $resp->get_error_data();
		$ids  = array_column( $data['policies'], 'id' );
		$this->assertContains( 'burst', $ids );
		$this->assertContains( 'sustained', $ids );
	}

	public function test_blocked_call_writes_error_audit_row(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$name    = 'abilityguard-tests/rl-audit';
		$ability = $this->register_ability(
			$name,
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 1,
					'window' => 60,
				),
			)
		);

		$ability->execute( array() );
		$ability->execute( array() );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $name ) );
		$this->assertCount( 2, $rows );
		$statuses = array_column( $rows, 'status' );
		$this->assertContains( 'error', $statuses );
		$this->assertContains( 'ok', $statuses );
	}

	// -----------------------------------------------------------------
	// Sliding-window math at boundary.
	// -----------------------------------------------------------------

	/**
	 * Sliding-window weighted formula admits at bucket boundary.
	 */
	public function test_sliding_window_admits_at_boundary_via_weighted_formula(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// 60s window, limit 10. Simulate: 10 calls in the previous bucket.
		// Then jump to 30s into the new bucket (elapsed=30, weight=0.5).
		// Estimate from prev only = 10 * 0.5 = 5. Should admit five more
		// before the sustained estimate hits the limit.
		$base_window = 60;
		// Align to a bucket boundary so the math is predictable.
		$first_now  = ( intdiv( 1_700_000_000, $base_window ) ) * $base_window;
		$second_now = $first_now + $base_window + 30; // 30s into next bucket.

		$now = $first_now;
		add_filter(
			'abilityguard_rate_limit_now',
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$ability = $this->register_ability(
			'abilityguard-tests/rl-boundary',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 10,
					'window' => $base_window,
				),
			)
		);

		// Fill the previous bucket.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( array( 'ok' => true ), $ability->execute( array() ), "fill $i" );
		}

		// Jump forward.
		$now = $second_now;

		// Weighted estimate at start of new bucket = 10 * 0.5 + 0 = 5.0,
		// limit is 10, so we should admit ~5 calls before blocking.
		$admitted = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$resp = $ability->execute( array() );
			if ( $resp instanceof WP_Error ) {
				break;
			}
			++$admitted;
		}

		// Allow off-by-one slack from float rounding at the boundary.
		$this->assertGreaterThanOrEqual( 4, $admitted );
		$this->assertLessThanOrEqual( 6, $admitted );
	}

	// -----------------------------------------------------------------
	// Retry-After is the max across exhausted policies.
	// -----------------------------------------------------------------

	/**
	 * Retry-After picks the longest reset across exhausted policies.
	 */
	public function test_retry_after_is_max_across_exhausted_policies(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// Pin clock to a common bucket-start so reset = window for both
		// policies (LCM of 5 and 47 = 235; pick a multiple of 235).
		$base = 235 * 7_234_042; // 1_699_999_870; < 2038.
		add_filter( 'abilityguard_rate_limit_now', static fn(): int => $base );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-retry',
			array(
				array(
					'id'     => 'short',
					'limit'  => 1,
					'window' => 5,
				),
				array(
					'id'     => 'long',
					'limit'  => 1,
					'window' => 47,
				),
			)
		);

		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );
		$resp = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$data = $resp->get_error_data();
		$this->assertSame( 47, $data['retry_after'] );
	}

	// -----------------------------------------------------------------
	// Headers on success and 429 via real REST dispatch.
	// -----------------------------------------------------------------

	/**
	 * IETF RateLimit headers attach on both admit and 429 responses.
	 */
	public function test_headers_emitted_on_admit_and_429(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$name = 'abilityguard-tests/rl-headers';
		$this->register_via_init(
			$name,
			static fn() => array(
				'label'               => 'Headers',
				'description'         => 'REST-exposed ability for header inspection.',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'meta'                => array( 'show_in_rest' => true ),
				'safety'              => array(
					'rate_limits'      => array(
						'policies' => array(
							array(
								'id'     => 'burst',
								'limit'  => 1,
								'window' => 1,
							),
							array(
								'id'     => 'sustained',
								'limit'  => 1,
								'window' => 60,
							),
						),
					),
					'skip_drift_check' => true,
				),
			)
		);

		// Make the abilities-api REST controller boot.
		do_action( 'rest_api_init' );

		$server = rest_get_server();

		$req = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $name . '/run' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( '{"input":{}}' );

		$ok = $server->dispatch( $req );
		// `WP_REST_Server::dispatch()` returns the raw response; the
		// `rest_post_dispatch` filter is only invoked from `serve_request`
		// (the HTTP entry point). Apply it manually here so the test
		// mirrors the live request path.
		$ok = apply_filters( 'rest_post_dispatch', rest_ensure_response( $ok ), $server, $req );
		$this->assertInstanceOf( WP_REST_Response::class, $ok );
		$this->assertSame( 200, $ok->get_status() );
		$headers = $ok->get_headers();
		$this->assertArrayHasKey( 'RateLimit-Policy', $headers );
		$this->assertArrayHasKey( 'RateLimit', $headers );
		$this->assertStringContainsString( '"burst"', (string) $headers['RateLimit-Policy'] );
		$this->assertStringContainsString( '"sustained"', (string) $headers['RateLimit-Policy'] );

		// Second call: blocked.
		$req2 = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $name . '/run' );
		$req2->set_header( 'content-type', 'application/json' );
		$req2->set_body( '{"input":{}}' );
		$blocked = $server->dispatch( $req2 );
		$blocked = apply_filters( 'rest_post_dispatch', rest_ensure_response( $blocked ), $server, $req2 );
		$this->assertSame( 429, $blocked->get_status() );
		$bh = $blocked->get_headers();
		$this->assertArrayHasKey( 'Retry-After', $bh );
		$this->assertArrayHasKey( 'RateLimit', $bh );
	}

	// -----------------------------------------------------------------
	// Principal three-tier fallback.
	// -----------------------------------------------------------------

	/**
	 * Principal resolution falls through user then caller then IP.
	 */
	public function test_principal_falls_through_user_then_caller_then_ip(): void {
		$captured = array();
		add_filter(
			'abilityguard_rate_limit_principal',
			static function ( string $p ) use ( &$captured ): string {
				$captured[] = $p;
				return $p;
			}
		);

		// Authenticated user.
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-principal-1',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 1,
					'window' => 60,
				),
			)
		);
		$ability->execute( array() );

		// Anonymous + caller_id via context. We trigger this by hooking
		// pre_execute_decision before RateLimiter (priority 5) and stuffing
		// caller_id; but the filter signature is read-only for that arg.
		// Easier: log out, set caller_id via the wrap context by spoofing
		// the InvocationObserver observer path is too invasive here, so we
		// test by directly invoking maybe_block.
		wp_set_current_user( 0 );
		$ability_caller = $this->register_ability(
			'abilityguard-tests/rl-principal-2',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 99,
					'window' => 60,
				),
			)
		);
		// Reach into the filter directly to simulate the wrap passing a caller_id.
		RateLimiter::maybe_block(
			null,
			'abilityguard-tests/rl-principal-2',
			array(),
			array(
				'caller_type' => 'mcp',
				'caller_id'   => 'mcp-server-xyz',
				'safety'      => array(
					'rate_limits' => array(
						'policies' => array(
							array(
								'id'     => 'sustained',
								'limit'  => 99,
								'window' => 60,
							),
						),
					),
				),
			)
		);

		// Anonymous, no caller_id, REMOTE_ADDR set.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$ability_anon           = $this->register_ability(
			'abilityguard-tests/rl-principal-3',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 99,
					'window' => 60,
				),
			)
		);
		$ability_anon->execute( array() );

		// We expect at least three principals captured, with the right prefixes.
		$this->assertNotEmpty( $captured );
		$prefixes = array_map(
			static fn( string $p ): string => substr( explode( '@', $p, 2 )[0], 0, 2 ),
			$captured
		);
		$this->assertContains( 'u:', $prefixes );
		$this->assertContains( 'c:', $prefixes );
		$this->assertContains( 'ip', $prefixes );
	}

	public function test_two_anon_ips_do_not_share_a_bucket(): void {
		wp_set_current_user( 0 );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-anon-iso',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 1,
					'window' => 60,
				),
			)
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		// Different IP → different bucket → still admitted.
		$this->assertSame( array( 'ok' => true ), $ability->execute( array() ) );

		// Same IP again → blocked.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$resp                   = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $resp );
	}

	// -----------------------------------------------------------------
	// Storage fail-open.
	// -----------------------------------------------------------------

	/**
	 * A throwing storage backend admits the call (Stripe fail-open).
	 */
	public function test_storage_exception_admits_call(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$throwing = new class() implements Storage {
			public function increment( string $key, int $ttl ): int {
				throw new \RuntimeException( 'backend down' );
			}
			public function get( string $key ): int {
				throw new \RuntimeException( 'backend down' );
			}
			public function delete( string $key ): void {
			}
			public function is_atomic(): bool {
				return false;
			}
		};

		add_filter(
			'abilityguard_rate_limiter_storage',
			static fn() => $throwing
		);

		// Suppress the error_log output during test.
		$prev = ini_set( 'error_log', '/dev/null' );

		try {
			RateLimiter::reset_for_tests();
			RateLimiter::register();

			$ability = $this->register_ability(
				'abilityguard-tests/rl-failopen',
				array(
					array(
						'id'     => 'sustained',
						'limit'  => 1,
						'window' => 60,
					),
				)
			);

			// With a throwing backend, every call must be admitted.
			for ( $i = 0; $i < 3; $i++ ) {
				$this->assertSame( array( 'ok' => true ), $ability->execute( array() ), "call $i" );
			}
		} finally {
			if ( false !== $prev ) {
				ini_set( 'error_log', $prev );
			}
		}
	}

	// -----------------------------------------------------------------
	// Multisite bucket isolation.
	// -----------------------------------------------------------------

	/**
	 * Multisite principals include the blog id so buckets stay isolated.
	 */
	public function test_multisite_principal_includes_blog_id(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'multisite-only test' );
		}

		$captured = array();
		add_filter(
			'abilityguard_rate_limit_principal',
			static function ( string $p ) use ( &$captured ): string {
				$captured[] = $p;
				return $p;
			}
		);

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$ability = $this->register_ability(
			'abilityguard-tests/rl-ms',
			array(
				array(
					'id'     => 'sustained',
					'limit'  => 99,
					'window' => 60,
				),
			)
		);
		$ability->execute( array() );

		$this->assertNotEmpty( $captured );
		$this->assertMatchesRegularExpression( '/@\d+$/', $captured[0] );
	}
}
