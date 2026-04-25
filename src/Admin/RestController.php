<?php
/**
 * REST endpoints powering the admin UI.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Admin;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Rollback\BulkRollbackService;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Routes:
 *  - GET  /abilityguard/v1/log
 *  - GET  /abilityguard/v1/log/<id>
 *  - POST /abilityguard/v1/rollback/<id>
 *  - POST /abilityguard/v1/rollback/bulk
 *
 * All gated by manage_options.
 */
final class RestController {

	private const NAMESPACE = 'abilityguard/v1';

	/**
	 * Hook into rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'list_log' ),
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 500,
					),
					'offset'   => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'status'   => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/log/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'show_log' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rollback/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'do_rollback' ),
				'args'                => array(
					'force' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rollback/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'do_bulk_rollback' ),
				'args'                => array(
					'ids'   => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'force' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Capability gate.
	 */
	public static function check_perms(): bool {
		return current_user_can( AdminMenu::CAPABILITY );
	}

	/**
	 * GET /log - list invocations.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function list_log( WP_REST_Request $req ): WP_REST_Response {
		$repo    = new LogRepository();
		$filters = array(
			'per_page' => (int) $req->get_param( 'per_page' ),
			'offset'   => (int) $req->get_param( 'offset' ),
		);
		$status  = $req->get_param( 'status' );
		if ( is_string( $status ) && '' !== $status ) {
			$filters['status'] = $status;
		}
		return new WP_REST_Response( $repo->list( $filters ), 200 );
	}

	/**
	 * GET /log/<id> - single invocation including its snapshot.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function show_log( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$id   = (int) $req->get_param( 'id' );
		$repo = new LogRepository();
		$row  = $repo->find( $id );
		if ( null === $row ) {
			return new WP_Error( 'abilityguard_not_found', 'No invocation with that id.', array( 'status' => 404 ) );
		}

		$snapshot = null;
		if ( ! empty( $row['invocation_id'] ) ) {
			$snapshot = ( new SnapshotStore() )->find_by_invocation_id( (string) $row['invocation_id'] );
		}
		return new WP_REST_Response(
			array(
				'log'      => $row,
				'snapshot' => $snapshot,
			),
			200
		);
	}

	/**
	 * POST /rollback/<id> - trigger rollback for an invocation.
	 *
	 * Accepts an optional `force` query/body parameter (boolean). When true,
	 * drift is ignored and the rollback proceeds regardless of live-state
	 * changes since the snapshot was taken.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function do_rollback( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$id      = (int) $req->get_param( 'id' );
		$force   = (bool) $req->get_param( 'force' );
		$service = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result  = $service->rollback( $id, $force );

		if ( is_wp_error( $result ) ) {
			$status = 'abilityguard_already_rolled_back' === $result->get_error_code() ? 409 : 400;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		$repo = new LogRepository();
		$row  = $repo->find( $id );
		return new WP_REST_Response(
			array(
				'ok'            => true,
				'log'           => $row,
				'snapshot_id'   => $row['snapshot_id'] ?? null,
				'invocation_id' => $row['invocation_id'] ?? null,
			),
			200
		);
	}

	/**
	 * POST /rollback/bulk - roll back multiple invocations by id.
	 *
	 * Body: { "ids": [int], "force": bool }
	 * Cap: 500 ids per request; 501+ returns 400.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function do_bulk_rollback( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$raw_ids = $req->get_param( 'ids' );
		if ( ! is_array( $raw_ids ) ) {
			return new WP_Error( 'abilityguard_invalid_ids', 'ids must be an array of integers.', array( 'status' => 400 ) );
		}

		if ( count( $raw_ids ) > 500 ) {
			return new WP_Error(
				'abilityguard_bulk_limit_exceeded',
				'A maximum of 500 ids may be submitted per request.',
				array( 'status' => 400 )
			);
		}

		$ids     = array_map( 'intval', $raw_ids );
		$force   = (bool) $req->get_param( 'force' );
		$service = BulkRollbackService::default();
		$summary = $service->rollback_many( $ids, $force );

		return new WP_REST_Response( $summary, 200 );
	}
}
