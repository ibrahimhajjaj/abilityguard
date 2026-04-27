<?php
/**
 * Dry-run mode: per-call opt-in via safety.dry_run = true.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Safety;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Diff\DiffService;
use AbilityGuard\Registry\InvocationContext;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_Error;

/**
 * Lets a caller preview a destructive ability without committing the change.
 *
 * Flow when safety.dry_run is on for a given invocation:
 *   1. Pre-snapshot is captured (InvocationObserver before-hook).
 *   2. The actual execute_callback runs.
 *   3. Post-snapshot is captured (the wrap, before this filter fires).
 *   4. Diff is computed pre vs post.
 *   5. Rollback runs with force=true (we just wrote the post-state ourselves
 *      a millisecond ago, drift detection would only slow this down and risk
 *      false positives on shared surfaces).
 *   6. The diff is persisted to LogMeta and the original $result returns
 *      unchanged so it validates against the ability's output_schema.
 *
 * The caller learns "this was a dry run" via:
 *   - response headers (X-AbilityGuard-Dry-Run: 1, X-AbilityGuard-Invocation-Id)
 *     when the call lands through REST/MCP,
 *   - the audit row, which carries dry_run=1 meta + dry_run_diff json,
 *   - the GET /abilityguard/v1/dry-run/{invocation_id} endpoint, which
 *     returns the diff JIT,
 *   - or the abilityguard_get_dry_run_result() PHP helper.
 *
 * Audit row outcome:
 *   - happy path: status flips ok -> rolled_back, dry_run=1 + dry_run_diff
 *     meta set.
 *   - rollback failed: status set to 'error', dry_run_failed meta set, and
 *     the caller gets a WP_Error - the original execute succeeded but state
 *     may now be inconsistent, the caller needs to know.
 *   - ability has no snapshot config: nothing to roll back. dry_run=1 meta
 *     is still set (so callers can detect they asked for dry-run), but
 *     dry_run_diff is empty and rolled_back is false.
 *
 * Error path is unchanged: when execute_callback returns WP_Error or throws,
 * the wrap finalizes the row as 'error' and never reaches this filter.
 */
final class DryRun {

	/**
	 * Boot guard.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Wire the post-execute filter. Idempotent.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'abilityguard_post_execute_result', array( self::class, 'maybe_apply' ), 10, 4 );
	}

	/**
	 * Test helper.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$registered = false;
		remove_filter( 'abilityguard_post_execute_result', array( self::class, 'maybe_apply' ), 10 );
	}

	/**
	 * Filter callback: transform the result + trigger rollback when dry-run is on.
	 *
	 * @param mixed                $result       Original execute result.
	 * @param string               $ability_name Ability name.
	 * @param mixed                $input        Input.
	 * @param array<string, mixed> $context      invocation_id, log_id, snapshot_id, safety, ...
	 *
	 * @return mixed The original $result, unchanged. Dry-run details are
	 *               surfaced via LogMeta + the dry-run REST endpoint so the
	 *               result keeps validating against the ability's output_schema.
	 */
	public static function maybe_apply( mixed $result, string $ability_name, mixed $input, array $context ): mixed {
		$safety = is_array( $context['safety'] ?? null ) ? $context['safety'] : array();
		if ( empty( $safety['dry_run'] ) ) {
			return $result;
		}

		$invocation_id = (string) ( $context['invocation_id'] ?? '' );
		$log_id        = (int) ( $context['log_id'] ?? 0 );

		// Always tag the row, even if there's nothing to diff. Callers
		// pulling /dry-run/{invocation_id} need to see "yes, you asked for
		// a dry run" before they see "nothing was rolled back."
		LogMeta::set( $log_id, 'dry_run', '1' );
		self::emit_dry_run_headers( $invocation_id );

		$store    = new SnapshotStore();
		$snapshot = '' !== $invocation_id ? $store->find_by_invocation_id( $invocation_id ) : null;

		// No snapshot row means there was no snapshot spec to capture against
		// (or capture failed). Without pre-state we can't diff and have
		// nothing to roll back to. Caller still gets the original result.
		if ( null === $snapshot || empty( $snapshot['surfaces'] ) ) {
			LogMeta::set( $log_id, 'dry_run_diff', wp_json_encode( array() ) );
			LogMeta::set( $log_id, 'dry_run_rolled_back', '0' );
			return $result;
		}

		$diff = ( new DiffService() )->compute( $snapshot );

		$rollback  = self::build_rollback_service();
		$rb_result = $rollback->rollback( $log_id, true );

		if ( $rb_result instanceof WP_Error ) {
			// Rollback failed AFTER a successful execute. Caller now has a
			// state inconsistency. Mark the audit row as error + meta the
			// failure reason so it shows up in the dashboard, and return
			// WP_Error so the caller doesn't think it was a no-op.
			self::mark_audit_row_failed( $log_id, $invocation_id, $rb_result );
			return $rb_result;
		}

		// Persist the diff so the REST endpoint and PHP helper can return
		// it long after the post-state has been overwritten by rollback.
		$encoded_diff = wp_json_encode( $diff );
		LogMeta::set( $log_id, 'dry_run_diff', false === $encoded_diff ? '[]' : $encoded_diff );
		LogMeta::set( $log_id, 'dry_run_rolled_back', '1' );

		// The after-hook listener has not yet run; it will try to overwrite
		// status='ok' if we don't short it out by completing the row now.
		$ctx = InvocationContext::find_for( $ability_name );
		if ( null !== $ctx ) {
			$duration_ms = (int) ( $context['duration_ms'] ?? 0 );
			( new AuditLogger() )->complete(
				$ctx->log_id,
				array(
					'result_json' => self::encode_result( $result ),
					'duration_ms' => $duration_ms,
				)
			);
			// Re-assert rolled_back: complete() leaves status alone unless
			// passed, but be explicit.
			( new LogRepository() )->update_status( $ctx->log_id, 'rolled_back' );
			$ctx->completed = true;
		}

		return $result;
	}

	/**
	 * Emit response headers when we're inside a REST request. Tells AI
	 * agents and other HTTP callers that the response body is the raw
	 * execute result and that real dry-run details live at the
	 * /dry-run/{invocation_id} endpoint.
	 *
	 * @param string $invocation_id Invocation UUID.
	 */
	private static function emit_dry_run_headers( string $invocation_id ): void {
		if ( headers_sent() ) {
			return;
		}
		// Only emit during HTTP requests; CLI and direct-PHP callers don't
		// need them and emitting on CLI breaks phpunit's output buffering.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_TESTS' ) && DOING_TESTS ) ) {
			return;
		}
		if ( 'cli' === PHP_SAPI ) {
			return;
		}
		header( 'X-AbilityGuard-Dry-Run: 1', true );
		if ( '' !== $invocation_id ) {
			header( 'X-AbilityGuard-Invocation-Id: ' . $invocation_id, true );
		}
	}

	/**
	 * Read back a dry-run result for an invocation, in the shape both the
	 * REST endpoint and the public helper return.
	 *
	 * @param string $invocation_id UUID.
	 *
	 * @return array{invocation_id: string, ability_name: string, executed_at: string, rolled_back: bool, diff: array<string,mixed>}|WP_Error
	 */
	public static function fetch_result( string $invocation_id ): array|WP_Error {
		if ( '' === $invocation_id ) {
			return new WP_Error( 'abilityguard_dry_run_missing_id', 'invocation_id is required.', array( 'status' => 400 ) );
		}
		$row = ( new LogRepository() )->find_by_invocation_id( $invocation_id );
		if ( null === $row ) {
			return new WP_Error( 'abilityguard_dry_run_not_found', 'No invocation found for that id.', array( 'status' => 404 ) );
		}
		$log_id = (int) ( $row['id'] ?? 0 );
		$dry_run_flag = LogMeta::get_all( $log_id, 'dry_run' );
		if ( array() === $dry_run_flag || '1' !== (string) $dry_run_flag[0] ) {
			return new WP_Error( 'abilityguard_dry_run_not_a_dry_run', 'Invocation was not a dry run.', array( 'status' => 409 ) );
		}
		$diff_rows   = LogMeta::get_all( $log_id, 'dry_run_diff' );
		$diff_json   = array() !== $diff_rows ? (string) $diff_rows[0] : '';
		$diff        = '' !== $diff_json ? json_decode( $diff_json, true ) : array();
		$rb_rows     = LogMeta::get_all( $log_id, 'dry_run_rolled_back' );
		$rolled_back = array() !== $rb_rows && '1' === (string) $rb_rows[0];
		return array(
			'invocation_id' => $invocation_id,
			'ability_name'  => (string) ( $row['ability_name'] ?? '' ),
			'executed_at'   => (string) ( $row['created_at'] ?? '' ),
			'rolled_back'   => $rolled_back,
			'diff'          => is_array( $diff ) ? $diff : array(),
		);
	}

	/**
	 * Build the rollback service with default collectors. Kept as a small
	 * helper so tests can subclass + swap if needed.
	 */
	private static function build_rollback_service(): RollbackService {
		return new RollbackService( new LogRepository(), new SnapshotStore() );
	}

	/**
	 * Encode the result for audit storage. Mirrors AuditLogger expectations
	 * (json or null). We don't redact here because the wrap already passed
	 * the result through redaction at audit-row creation; for dry-run we just
	 * need a non-null marker that "this is what would have been returned."
	 *
	 * @param mixed $result Result.
	 */
	private static function encode_result( mixed $result ): ?string {
		if ( null === $result ) {
			return null;
		}
		$encoded = wp_json_encode( $result );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * Mark the audit row as error + record dry_run_failed reason on rollback failure.
	 *
	 * @param int      $log_id        Log id.
	 * @param string   $invocation_id UUID (kept for caller signature, unused).
	 * @param WP_Error $err           Rollback error.
	 */
	private static function mark_audit_row_failed( int $log_id, string $invocation_id, WP_Error $err ): void {
		( new LogRepository() )->update_status( $log_id, 'error' );
		LogMeta::set( $log_id, 'dry_run', '1' );
		LogMeta::set( $log_id, 'dry_run_failed', (string) $err->get_error_code() );

		$ctx = InvocationContext::current();
		if ( null !== $ctx ) {
			$ctx->completed = true;
		}
	}
}
