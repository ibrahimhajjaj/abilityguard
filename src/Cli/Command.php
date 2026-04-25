<?php
/**
 * WP-CLI command surface.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Cli;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Retention\RetentionService;
use AbilityGuard\Rollback\BulkRollbackService;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp abilityguard ...`
 *
 * Manage AbilityGuard's audit log and roll back invocations.
 */
final class Command {

	/**
	 * Audit log read API.
	 *
	 * @var LogRepository
	 */
	private LogRepository $logs;

	/**
	 * Constructor.
	 *
	 * @param LogRepository|null $logs Log repository (defaults to production wiring).
	 */
	public function __construct( ?LogRepository $logs = null ) {
		$this->logs = $logs ?? new LogRepository();
	}

	/**
	 * Register all subcommands. Idempotent.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'abilityguard log', array( __CLASS__, 'cmd_log_root' ) );
		WP_CLI::add_command( 'abilityguard rollback', array( __CLASS__, 'cmd_rollback' ) );
		WP_CLI::add_command( 'abilityguard prune', array( __CLASS__, 'cmd_prune' ) );
		WP_CLI::add_command( 'abilityguard approval', array( __CLASS__, 'cmd_approval_root' ) );
	}

	/**
	 * Dispatch `wp abilityguard log <sub>` (list|show).
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : One of: list, show.
	 *
	 * [<reference>]
	 * : For `show`: numeric log id or invocation uuid.
	 *
	 * [--ability=<name>]
	 * : Filter by ability name (list).
	 *
	 * [--status=<status>]
	 * : Filter by status (list): ok|error|rolled_back|pending.
	 *
	 * [--per-page=<n>]
	 * : Page size (list, default 50, max 500).
	 *
	 * [--format=<format>]
	 * : Output format: table|csv|json|yaml. Default: table.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function cmd_log( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';
		switch ( $sub ) {
			case 'list':
				$this->log_list( $assoc_args );
				return;
			case 'show':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Pass a log id or invocation uuid: wp abilityguard log show <ref>' );
				}
				$this->log_show( (string) $args[1], $assoc_args );
				return;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}" );
		}
	}

	/**
	 * Static dispatcher used by WP_CLI::add_command.
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public static function cmd_log_root( array $args, array $assoc_args ): void {
		( new self() )->cmd_log( $args, $assoc_args );
	}

	/**
	 * Roll back an invocation (or a batch via --batch).
	 *
	 * ## OPTIONS
	 *
	 * [<reference>]
	 * : Numeric log id or invocation uuid. Ignored when --batch is set.
	 *
	 * [--yes]
	 * : Skip confirmation (single-id mode only).
	 *
	 * [--force]
	 * : Ignore drift and restore even if live state has changed since capture.
	 *
	 * [--batch=<filter>]
	 * : Comma-separated filter list, e.g. ability=acme/foo,status=ok,destructive=1.
	 *   When set, positional arg is ignored and no per-row confirmation is asked.
	 *   Matched ids are capped at 500.
	 *
	 * [--dry-run]
	 * : When --batch is set, print matched ids without rolling back.
	 *
	 * ## EXAMPLES
	 *
	 *     wp abilityguard rollback 42
	 *     wp abilityguard rollback --batch=ability=acme/foo,status=ok --dry-run
	 *     wp abilityguard rollback --batch=destructive=1,status=ok --force
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public static function cmd_rollback( array $args, array $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );

		if ( ! empty( $assoc_args['batch'] ) ) {
			( new self() )->bulk_rollback( (string) $assoc_args['batch'], $force, ! empty( $assoc_args['dry-run'] ) );
			return;
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Pass a log id or invocation uuid, or use --batch.' );
		}
		$ref = $args[0];

		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Roll back invocation {$ref}?", $assoc_args );
		}

		$service = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result  = $service->rollback( ctype_digit( $ref ) ? (int) $ref : $ref, $force );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( "Rolled back invocation {$ref}." );
	}

	/**
	 * Handle bulk rollback via --batch filter.
	 *
	 * @param string $batch_filter Comma-separated key=value filter list.
	 * @param bool   $force        Whether to ignore drift.
	 * @param bool   $dry_run      When true, only print matched ids.
	 */
	private function bulk_rollback( string $batch_filter, bool $force, bool $dry_run ): void {
		$filters             = $this->parse_batch_filter( $batch_filter );
		$filters['per_page'] = 500;

		$rows = $this->logs->list( $filters );
		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No invocations matched the given filters.' );
			return;
		}

		$ids = array_map( static fn( $r ) => (int) $r['id'], $rows );

		if ( $dry_run ) {
			WP_CLI::line( 'Dry-run: matched ' . count( $ids ) . ' id(s):' );
			foreach ( $ids as $id ) {
				WP_CLI::line( "  #{$id}" );
			}
			return;
		}

		$service = new BulkRollbackService( new RollbackService( new LogRepository(), new SnapshotStore() ) );
		$summary = $service->rollback_many( $ids, $force );

		foreach ( $summary['rolled_back'] as $id ) {
			WP_CLI::line( "\u{2713} #{$id} rolled back" );
		}
		foreach ( $summary['skipped'] as $id => $code ) {
			WP_CLI::line( "\u{26A0} #{$id} skipped ({$code})" );
		}
		foreach ( $summary['errors'] as $id => $code ) {
			WP_CLI::line( "\u{2717} #{$id} error ({$code})" );
		}

		$rb  = count( $summary['rolled_back'] );
		$sk  = count( $summary['skipped'] );
		$err = count( $summary['errors'] );
		WP_CLI::line( "Bulk rollback complete: {$rb} rolled back, {$sk} skipped, {$err} errors." );

		if ( $err > 0 ) {
			WP_CLI::warning( 'Some ids encountered hard errors. See output above.' );
		}
	}

	/**
	 * Parse a comma-separated batch filter string into a filters array.
	 *
	 * Accepted keys: ability (→ ability_name), status, destructive, caller_id.
	 *
	 * @param string $raw Raw filter string, e.g. "ability=acme/foo,status=ok".
	 *
	 * @return array<string, mixed>
	 */
	private function parse_batch_filter( string $raw ): array {
		$filters = array();
		$pairs   = explode( ',', $raw );
		foreach ( $pairs as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			[ $key, $val ] = $parts;
			$key           = trim( $key );
			$val           = trim( $val );
			switch ( $key ) {
				case 'ability':
					$filters['ability_name'] = $val;
					break;
				case 'status':
					$filters['status'] = $val;
					break;
				case 'destructive':
					$filters['destructive'] = '1' === $val || 'true' === strtolower( $val );
					break;
				case 'caller_id':
					$filters['caller_id'] = $val;
					break;
			}
		}
		return $filters;
	}

	/**
	 * Prune expired log rows and orphaned snapshots.
	 *
	 * ## EXAMPLES
	 *
	 *     wp abilityguard prune
	 *
	 * @param array<int, string>   $args       Positional (unused).
	 * @param array<string, mixed> $assoc_args Flags (unused).
	 */
	public static function cmd_prune( array $args, array $assoc_args ): void {
		$result = ( new RetentionService() )->prune();
		WP_CLI::success(
			sprintf(
				'Pruned %d log row(s) and %d snapshot(s).',
				$result['logs_deleted'],
				$result['snapshots_deleted']
			)
		);
	}

	/**
	 * Dispatch `wp abilityguard approval <sub>` (list|approve|reject).
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : One of: list, approve, reject.
	 *
	 * [<id>]
	 * : For `approve` and `reject`: numeric approval id.
	 *
	 * [--status=<s>]
	 * : Filter by status (list): pending|approved|rejected.
	 *
	 * [--format=<format>]
	 * : Output format: table|csv|json|yaml. Default: table.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function cmd_approval( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'list';
		switch ( $sub ) {
			case 'list':
				$this->approval_list( $assoc_args );
				return;
			case 'approve':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Pass an approval id: wp abilityguard approval approve <id>' );
				}
				$this->approval_decide( (int) $args[1], 'approve' );
				return;
			case 'reject':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Pass an approval id: wp abilityguard approval reject <id>' );
				}
				$this->approval_decide( (int) $args[1], 'reject' );
				return;
			default:
				WP_CLI::error( "Unknown subcommand: {$sub}" );
		}
	}

	/**
	 * Static dispatcher for `wp abilityguard approval`.
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public static function cmd_approval_root( array $args, array $assoc_args ): void {
		( new self() )->cmd_approval( $args, $assoc_args );
	}

	/**
	 * Render `approval list`.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function approval_list( array $assoc_args ): void {
		$filters = array();
		if ( ! empty( $assoc_args['status'] ) ) {
			$filters['status'] = (string) $assoc_args['status'];
		}

		$repo   = new \AbilityGuard\Approval\ApprovalRepository();
		$rows   = $repo->list( $filters );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fields = array( 'id', 'log_id', 'ability_name', 'status', 'requested_by', 'decided_by', 'decided_at', 'created_at' );

		Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Approve or reject an approval row.
	 *
	 * @param int    $approval_id Approval row id.
	 * @param string $action      'approve' or 'reject'.
	 */
	private function approval_decide( int $approval_id, string $action ): void {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$service = new \AbilityGuard\Approval\ApprovalService();

		$result = 'approve' === $action
			? $service->approve( $approval_id, $user_id )
			: $service->reject( $approval_id, $user_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$verb = 'approve' === $action ? 'Approved' : 'Rejected';
		WP_CLI::success( "{$verb} approval {$approval_id}." );
	}

	/**
	 * Render `log list`.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function log_list( array $assoc_args ): void {
		$filters = array();
		if ( ! empty( $assoc_args['ability'] ) ) {
			$filters['ability_name'] = (string) $assoc_args['ability'];
		}
		if ( ! empty( $assoc_args['status'] ) ) {
			$filters['status'] = (string) $assoc_args['status'];
		}
		if ( ! empty( $assoc_args['per-page'] ) ) {
			$filters['per_page'] = (int) $assoc_args['per-page'];
		}

		$rows   = $this->logs->list( $filters );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fields = array( 'id', 'invocation_id', 'ability_name', 'status', 'destructive', 'duration_ms', 'created_at' );

		Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Render `log show`.
	 *
	 * @param string               $reference  Log id or uuid.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function log_show( string $reference, array $assoc_args ): void {
		$row = ctype_digit( $reference )
			? $this->logs->find( (int) $reference )
			: $this->logs->find_by_invocation_id( $reference );
		if ( null === $row ) {
			WP_CLI::error( "No invocation matched: {$reference}" );
		}

		$format = (string) ( $assoc_args['format'] ?? 'yaml' );
		Utils\format_items( $format, array( $row ), array_keys( $row ) );
	}
}
