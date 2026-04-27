<?php
/**
 * REST endpoints powering the admin UI.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Admin;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Approval\CapabilityManager;
use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Retention\RetentionService;
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
 *  - POST /abilityguard/v1/rollback/bulk           (manage_options)
 *  - GET  /abilityguard/v1/approval                (manage_abilityguard_approvals)
 *  - POST /abilityguard/v1/approval/<id>/approve   (manage_abilityguard_approvals)
 *  - POST /abilityguard/v1/approval/<id>/reject    (manage_abilityguard_approvals)
 *  - GET  /abilityguard/v1/retention               (manage_options)
 *  - GET  /abilityguard/v1/stats                   (manage_options)
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
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 500,
					),
					'offset'       => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'status'       => array( 'type' => 'string' ),
					'ability_name' => array( 'type' => 'string' ),
					'caller_id'    => array( 'type' => 'string' ),
					'destructive'  => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/log/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'export_log' ),
				'args'                => array(
					'format'       => array(
						'type'    => 'string',
						'default' => 'csv',
						'enum'    => array( 'csv', 'json', 'jsonl' ),
					),
					'limit'        => array(
						'type'    => 'integer',
						'default' => 5000,
						'minimum' => 1,
						'maximum' => 50000,
					),
					'status'       => array( 'type' => 'string' ),
					'ability_name' => array( 'type' => 'string' ),
					'caller_id'    => array( 'type' => 'string' ),
					'destructive'  => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
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

		register_rest_route(
			self::NAMESPACE,
			'/approval',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_approval_perms' ),
				'callback'            => array( __CLASS__, 'list_approvals' ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'default' => 'pending',
					),
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
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'get_stats' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'get_health' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/retention',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'get_retention' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/retention/prune',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_perms' ),
				'callback'            => array( __CLASS__, 'do_prune' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/approval/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'check_approval_perms' ),
				'callback'            => array( __CLASS__, 'export_approvals' ),
				'args'                => array(
					'format' => array(
						'type'    => 'string',
						'default' => 'csv',
						'enum'    => array( 'csv', 'json' ),
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 5000,
						'minimum' => 1,
						'maximum' => 50000,
					),
					'status' => array(
						'type'    => 'string',
						'default' => 'pending',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/approval/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_approval_perms' ),
				'callback'            => array( __CLASS__, 'do_bulk_approval' ),
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'approve', 'reject' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/approval/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_approval_perms' ),
				'callback'            => array( __CLASS__, 'do_approve' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/approval/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'check_approval_perms' ),
				'callback'            => array( __CLASS__, 'do_reject' ),
			)
		);
	}

	/**
	 * Capability gate for general (read/rollback) endpoints.
	 */
	public static function check_perms(): bool {
		return current_user_can( AdminMenu::CAPABILITY );
	}

	/**
	 * Capability gate for approval decision endpoints.
	 */
	public static function check_approval_perms(): bool {
		return current_user_can( CapabilityManager::CAP );
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

		$status = $req->get_param( 'status' );
		if ( is_string( $status ) && '' !== $status ) {
			$filters['status'] = $status;
		}

		$ability_name = $req->get_param( 'ability_name' );
		if ( is_string( $ability_name ) && '' !== $ability_name ) {
			$filters['ability_name'] = $ability_name;
		}

		$caller_id = $req->get_param( 'caller_id' );
		if ( is_string( $caller_id ) && '' !== $caller_id ) {
			$filters['caller_id'] = $caller_id;
		}

		// `destructive` is tri-state: true | false | unset.
		// Use has_param to distinguish "filter to non-destructive" from "no filter".
		if ( null !== $req->get_param( 'destructive' ) ) {
			$filters['destructive'] = (bool) $req->get_param( 'destructive' );
		}

		return new WP_REST_Response( $repo->list( $filters ), 200 );
	}

	/**
	 * GET /log/export - flat CSV or JSON dump of audit rows.
	 *
	 * Honours the same status / ability_name / caller_id / destructive
	 * filters as `/log`, plus `format` (csv|json) and `limit` (cap).
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function export_log( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$format = (string) $req->get_param( 'format' );
		$limit  = max( 1, min( 50000, (int) $req->get_param( 'limit' ) ) );

		$filters = array(
			'per_page' => $limit,
			'offset'   => 0,
		);
		foreach ( array( 'status', 'ability_name', 'caller_id' ) as $key ) {
			$value = $req->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}
		if ( null !== $req->get_param( 'destructive' ) ) {
			$filters['destructive'] = (bool) $req->get_param( 'destructive' );
		}

		$rows = ( new LogRepository() )->list( $filters );

		// We need raw CSV/JSON bytes, not a JSON-wrapped response. The REST
		// stack will serialise WP_REST_Response bodies as JSON, so for CSV
		// we hook rest_pre_serve_request to short-circuit serialisation.
		if ( 'csv' === $format ) {
			$columns = array(
				'id',
				'invocation_id',
				'parent_invocation_id',
				'ability_name',
				'caller_type',
				'caller_id',
				'user_id',
				'status',
				'destructive',
				'duration_ms',
				'pre_hash',
				'post_hash',
				'snapshot_id',
				'created_at',
			);

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream, not a real filesystem write.
			$buffer = fopen( 'php://temp', 'r+' );
			if ( false === $buffer ) {
				return new WP_Error( 'abilityguard_export_buffer', 'Could not open temp buffer.', array( 'status' => 500 ) );
			}
			fputcsv( $buffer, $columns );
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $columns as $column ) {
					$line[] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
				}
				fputcsv( $buffer, $line );
			}
			rewind( $buffer );
			$csv = (string) stream_get_contents( $buffer );
			fclose( $buffer );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			add_filter(
				'rest_pre_serve_request',
				static function ( bool $served, $result ) use ( $csv ): bool {
					unset( $result );
					if ( $served ) {
						return $served;
					}
					if ( ! headers_sent() ) {
						header( 'Content-Type: text/csv; charset=utf-8' );
						header( 'Content-Disposition: attachment; filename="abilityguard-log.csv"' );
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $csv;
					return true;
				},
				10,
				2
			);

			// Returning an empty 200 response satisfies the route signature;
			// rest_pre_serve_request above replaces the body with raw CSV.
			return new WP_REST_Response( '', 200 );
		}

		if ( 'jsonl' === $format ) {
			// One JSON object per line. Bypasses the REST JSON serializer
			// (which would wrap the whole response in another JSON layer)
			// via the same rest_pre_serve_request short-circuit used for CSV.
			$lines = array();
			foreach ( $rows as $row ) {
				$encoded = wp_json_encode( $row );
				if ( false !== $encoded ) {
					$lines[] = $encoded;
				}
			}
			$body = implode( "\n", $lines ) . ( array() === $lines ? '' : "\n" );

			add_filter(
				'rest_pre_serve_request',
				static function ( bool $served, $result ) use ( $body ): bool {
					unset( $result );
					if ( $served ) {
						return $served;
					}
					if ( ! headers_sent() ) {
						header( 'Content-Type: application/x-ndjson; charset=utf-8' );
						header( 'Content-Disposition: attachment; filename="abilityguard-log.jsonl"' );
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $body;
					return true;
				},
				10,
				2
			);
			return new WP_REST_Response( '', 200 );
		}

		// JSON path: REST stack handles serialisation; we just nudge the
		// download filename header.
		$response = new WP_REST_Response( $rows, 200 );
		$response->header( 'Content-Disposition', 'attachment; filename="abilityguard-log.json"' );
		return $response;
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

		// Parent (if any) - looked up by the parent's invocation_id.
		$parent = null;
		if ( ! empty( $row['parent_invocation_id'] ) ) {
			$parent = $repo->find_by_invocation_id( (string) $row['parent_invocation_id'] );
		}

		// Children - invocations that reported THIS row's invocation_id as their parent.
		$children = ! empty( $row['invocation_id'] )
			? $repo->find_children( (string) $row['invocation_id'] )
			: array();

		// Selected log_meta surfaces. Anything stored as JSON is decoded so
		// the React side doesn't have to re-parse.
		$meta = array();
		foreach ( array( 'skip_drift_check', 'files_changed_on_rollback', 'files_deleted_on_rollback' ) as $key ) {
			$values = LogMeta::get_all( $id, $key );
			if ( array() === $values ) {
				continue;
			}
			$decoded      = json_decode( (string) $values[0], true );
			$meta[ $key ] = is_array( $decoded ) ? $decoded : $values[0];
		}

		// Approval row + stage chain when this invocation is gated.
		$approval      = null;
		$stages        = array();
		$approval_repo = new ApprovalRepository();
		$approval_row  = $approval_repo->find_by_log_id( $id );
		if ( null !== $approval_row ) {
			$approval = $approval_row;
			$stages   = $approval_repo->find_stages( (int) $approval_row['id'] );
		}

		return new WP_REST_Response(
			array(
				'log'      => $row,
				'snapshot' => $snapshot,
				'parent'   => $parent,
				'children' => $children,
				'meta'     => $meta,
				'approval' => $approval,
				'stages'   => $stages,
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

	/**
	 * GET /approval - list approval rows.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function list_approvals( WP_REST_Request $req ): WP_REST_Response {
		$repo   = new ApprovalRepository();
		$status = $req->get_param( 'status' );
		if ( ! is_string( $status ) || '' === $status ) {
			$status = 'pending';
		}
		$filters = array(
			'status'   => $status,
			'per_page' => (int) $req->get_param( 'per_page' ),
			'offset'   => (int) $req->get_param( 'offset' ),
		);
		$rows    = $repo->list( $filters );

		// Inline the stage chain on each row so the React app can render
		// "stage N of M" without an extra REST round-trip per approval.
		foreach ( $rows as &$row ) {
			$row['stages'] = $repo->find_stages( (int) $row['id'] );
		}
		unset( $row );

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * GET /stats - aggregate counts, timing percentiles, and top abilities.
	 *
	 * Complements WordPress/ai PR #437 (provider-HTTP scope). This endpoint
	 * reports on the ability-execution layer: outcomes recorded by the audit
	 * logger, not provider request/response counts.
	 *
	 * @param \WP_REST_Request $req Unused.
	 */
	public static function get_stats( WP_REST_Request $req ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_REST_Response( ( new LogRepository() )->stats(), 200 );
	}

	/**
	 * GET /retention - return retention policy + last-prune metadata.
	 *
	 * @param \WP_REST_Request $req Unused - included for REST route handler signature.
	 */
	/**
	 * GET /health - operational metrics.
	 *
	 * @param \WP_REST_Request $req Unused.
	 */
	public static function get_health( WP_REST_Request $req ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wpdb;
		$log_table       = \AbilityGuard\Installer::table( 'log' );
		$approvals_table = \AbilityGuard\Installer::table( 'approvals' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$pending_logs      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table} WHERE status = 'pending'" );
		$pending_approvals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$approvals_table} WHERE status = 'pending'" );
		$total_logs        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'version'           => defined( 'ABILITYGUARD_VERSION' ) ? ABILITYGUARD_VERSION : 'unknown',
				'db_version'        => get_option( \AbilityGuard\Installer::DB_VERSION_OPTION, '0' ),
				'total_log_rows'    => $total_logs,
				'pending_log_rows'  => $pending_logs,
				'pending_approvals' => $pending_approvals,
				'last_pruned'       => get_option( 'abilityguard_last_pruned', null ),
				'last_pruned_count' => (int) get_option( 'abilityguard_last_pruned_count', 0 ),
				'multisite'         => function_exists( 'is_multisite' ) && is_multisite(),
			),
			200
		);
	}

	/**
	 * GET /retention - return retention policy + last-prune metadata.
	 *
	 * @param \WP_REST_Request $req Unused.
	 */
	public static function get_retention( WP_REST_Request $req ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$svc         = new RetentionService();
		$last_pruned = get_option( 'abilityguard_last_pruned', null );
		$rows_pruned = (int) get_option( 'abilityguard_last_pruned_count', 0 );
		return new WP_REST_Response(
			array(
				'normal_days'      => $svc->retention_days_normal(),
				'destructive_days' => $svc->retention_days_destructive(),
				'last_pruned'      => $last_pruned,
				'rows_pruned'      => $rows_pruned,
			),
			200
		);
	}

	/**
	 * POST /retention/prune - run the retention prune synchronously.
	 *
	 * Useful for ops who want to verify retention is wired without waiting
	 * for the daily cron, and for testing the dashboard's last-pruned widget.
	 *
	 * @param \WP_REST_Request $req Request (unused).
	 */
	public static function do_prune( WP_REST_Request $req ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$svc    = new RetentionService();
		$result = $svc->prune();
		return new WP_REST_Response(
			array(
				'ok'                => true,
				'logs_deleted'      => (int) ( $result['logs_deleted'] ?? 0 ),
				'snapshots_deleted' => (int) ( $result['snapshots_deleted'] ?? 0 ),
				'last_pruned'       => get_option( 'abilityguard_last_pruned', null ),
			),
			200
		);
	}

	/**
	 * POST /approval/<id>/approve - approve a pending request.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function do_approve( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$id      = (int) $req->get_param( 'id' );
		$user_id = get_current_user_id();
		$service = new ApprovalService();
		$result  = $service->approve( $id, $user_id );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = 'abilityguard_not_found' === $code ? 404 : 400;
			return new WP_Error( $code, $result->get_error_message(), array( 'status' => $status ) );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'approval_id' => $id,
			),
			200
		);
	}

	/**
	 * POST /approval/<id>/reject - reject a pending request.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function do_reject( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$id      = (int) $req->get_param( 'id' );
		$user_id = get_current_user_id();
		$service = new ApprovalService();
		$result  = $service->reject( $id, $user_id );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = 'abilityguard_not_found' === $code ? 404 : 400;
			return new WP_Error( $code, $result->get_error_message(), array( 'status' => $status ) );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'approval_id' => $id,
			),
			200
		);
	}

	/**
	 * POST /approval/bulk - approve or reject many at once.
	 *
	 * Body: `{ "ids": [int], "action": "approve"|"reject" }`. Cap of 100 ids
	 * per request (mirrors `/rollback/bulk`'s 500 cap but tighter - approval
	 * decisions tend to fan out to user notifications).
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function do_bulk_approval( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$raw_ids = $req->get_param( 'ids' );
		$action  = (string) $req->get_param( 'action' );

		if ( ! is_array( $raw_ids ) ) {
			return new WP_Error( 'abilityguard_invalid_ids', 'ids must be an array of integers.', array( 'status' => 400 ) );
		}
		if ( count( $raw_ids ) > 100 ) {
			return new WP_Error(
				'abilityguard_bulk_limit_exceeded',
				'Bulk approvals are capped at 100 ids per request.',
				array( 'status' => 400 )
			);
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $raw_ids ),
					static fn( int $i ): bool => $i > 0
				)
			)
		);

		$user_id = get_current_user_id();
		$service = new ApprovalService();

		$succeeded = array();
		$failed    = array();

		foreach ( $ids as $id ) {
			$result = 'approve' === $action
				? $service->approve( $id, $user_id )
				: $service->reject( $id, $user_id );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'approval_id' => $id,
					'code'        => $result->get_error_code(),
					'message'     => $result->get_error_message(),
				);
				continue;
			}
			$succeeded[] = $id;
		}

		return new WP_REST_Response(
			array(
				'action'    => $action,
				'succeeded' => $succeeded,
				'failed'    => $failed,
				'total'     => count( $ids ),
			),
			200
		);
	}

	/**
	 * GET /approval/export - flat CSV/JSON dump of approval rows.
	 *
	 * Mirrors `/log/export`. Honours the same status filter as `/approval`.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public static function export_approvals( WP_REST_Request $req ): WP_REST_Response|WP_Error {
		$format = (string) $req->get_param( 'format' );
		$limit  = max( 1, min( 50000, (int) $req->get_param( 'limit' ) ) );
		$status = (string) $req->get_param( 'status' );

		$filters = array(
			'status'   => '' !== $status ? $status : 'pending',
			'per_page' => $limit,
			'offset'   => 0,
		);

		$rows = ( new ApprovalRepository() )->list( $filters );

		if ( 'csv' === $format ) {
			$columns = array(
				'id',
				'log_id',
				'ability_name',
				'status',
				'requested_by',
				'decided_by',
				'decided_at',
				'created_at',
			);

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream.
			$buffer = fopen( 'php://temp', 'r+' );
			if ( false === $buffer ) {
				return new WP_Error( 'abilityguard_export_buffer', 'Could not open temp buffer.', array( 'status' => 500 ) );
			}
			fputcsv( $buffer, $columns );
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $columns as $column ) {
					$line[] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
				}
				fputcsv( $buffer, $line );
			}
			rewind( $buffer );
			$csv = (string) stream_get_contents( $buffer );
			fclose( $buffer );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			add_filter(
				'rest_pre_serve_request',
				static function ( bool $served, $result ) use ( $csv ): bool {
					unset( $result );
					if ( $served ) {
						return $served;
					}
					if ( ! headers_sent() ) {
						header( 'Content-Type: text/csv; charset=utf-8' );
						header( 'Content-Disposition: attachment; filename="abilityguard-approvals.csv"' );
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $csv;
					return true;
				},
				10,
				2
			);

			return new WP_REST_Response( '', 200 );
		}

		$response = new WP_REST_Response( $rows, 200 );
		$response->header( 'Content-Disposition', 'attachment; filename="abilityguard-approvals.json"' );
		return $response;
	}
}
