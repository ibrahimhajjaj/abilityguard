<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Rest;

use AbilityGuard\Installer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared helpers for REST endpoint integration tests.
 *
 * Not a WP_UnitTestCase - just a trait mixed into each test class.
 */
trait RestHelpers {

	/**
	 * Admin user id created in setUpRestHelpers().
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	/**
	 * Non-admin (subscriber) user id.
	 *
	 * @var int
	 */
	private int $subscriber_user_id = 0;

	/**
	 * Call once from setUp() to install tables and create test users.
	 */
	protected function set_up_rest_helpers(): void {
		Installer::install();

		// Ensure REST server is initialised.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// Create reusable users - factories handle teardown automatically.
		$this->admin_user_id      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Dispatch a request through the real WordPress REST stack.
	 *
	 * @param string              $method GET|POST|PUT|DELETE.
	 * @param string              $path   Full route, e.g. '/abilityguard/v1/log'.
	 * @param array<string,mixed> $params Query/body parameters.
	 *
	 * @return WP_REST_Response
	 */
	protected function dispatch( string $method, string $path, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$result = rest_get_server()->dispatch( $request );
		// Always return a concrete WP_REST_Response (dispatch() converts WP_Error).
		return rest_ensure_response( $result );
	}

	/**
	 * Set current user to admin, run $fn, then restore the previous user.
	 *
	 * @param callable $fn Callback to run as admin.
	 *
	 * @return mixed Return value of $fn.
	 */
	protected function as_admin( callable $fn ): mixed {
		$prev = get_current_user_id();
		wp_set_current_user( $this->admin_user_id );
		try {
			return $fn();
		} finally {
			wp_set_current_user( $prev );
		}
	}

	/**
	 * Insert a log row directly via $wpdb and return the inserted id.
	 *
	 * @param array<string,mixed> $overrides Column overrides (merged over defaults).
	 *
	 * @return int Inserted log id.
	 */
	protected function seed_log_row( array $overrides = array() ): int {
		global $wpdb;
		$table = Installer::table( 'log' );

		$defaults = array(
			'invocation_id' => wp_generate_uuid4(),
			'ability_name'  => 'test/seed-ability',
			'caller_type'   => 'cli',
			'caller_id'     => null,
			'user_id'       => 0,
			'args_json'     => null,
			'result_json'   => null,
			'status'        => 'ok',
			'destructive'   => 0,
			'duration_ms'   => 1,
			'pre_hash'      => str_repeat( 'a', 64 ),
			'post_hash'     => str_repeat( 'b', 64 ),
			'snapshot_id'   => null,
			'created_at'    => current_time( 'mysql', true ),
		);

		$row = array_merge( $defaults, $overrides );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			$row,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a snapshot row for a given invocation and return the snapshot id.
	 *
	 * @param string $invocation_id UUID matching a log row's invocation_id.
	 *
	 * @return int Inserted snapshot id.
	 */
	protected function seed_snapshot_row( string $invocation_id ): int {
		global $wpdb;
		$table = Installer::table( 'snapshots' );

		$surfaces = array( 'options' => array( 'blogname' => 'Test Site' ) );
		$json     = \AbilityGuard\Support\Json::encode_compressed( $surfaces );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'invocation_id' => $invocation_id,
				'surfaces_json' => $json,
				'pre_hash'      => str_repeat( 'c', 64 ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}
}
