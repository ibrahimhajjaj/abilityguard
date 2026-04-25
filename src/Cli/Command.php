<?php
/**
 * WP-CLI command surface.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Cli;

use AbilityGuard\Audit\LogRepository;
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
	 * Roll back an invocation.
	 *
	 * ## OPTIONS
	 *
	 * <reference>
	 * : Numeric log id or invocation uuid.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array<int, string>   $args       Positional.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public static function cmd_rollback( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Pass a log id or invocation uuid.' );
		}
		$ref = $args[0];

		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Roll back invocation {$ref}?", $assoc_args );
		}

		$service = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result  = $service->rollback( ctype_digit( $ref ) ? (int) $ref : $ref );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( "Rolled back invocation {$ref}." );
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
