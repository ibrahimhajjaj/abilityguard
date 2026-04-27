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
 *   5. Rollback is invoked with force=true (we just wrote the post-state
 *      ourselves a millisecond ago, drift detection would only slow this
 *      down and risk false positives on shared surfaces).
 *   6. The wrap's return becomes
 *        { result, diff, rolled_back }
 *      instead of the raw execute result.
 *
 * Audit row outcome:
 *   - happy path: status flips ok -> rolled_back via RollbackService, with a
 *     dry_run=1 meta marker for filtering.
 *   - rollback failed: status set to 'error', dry_run_failed meta set, and
 *     the caller gets a WP_Error (not the envelope) - the original execute
 *     succeeded but state may now be inconsistent, the caller needs to know.
 *   - ability has no snapshot config: nothing to roll back. We still wrap
 *     the result in an envelope with rolled_back=false and a note so the
 *     caller can surface the limitation.
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
	 * @return mixed Either the original $result (no dry-run) or the envelope.
	 */
	public static function maybe_apply( mixed $result, string $ability_name, mixed $input, array $context ): mixed {
		$safety = is_array( $context['safety'] ?? null ) ? $context['safety'] : array();
		if ( empty( $safety['dry_run'] ) ) {
			return $result;
		}

		$invocation_id = (string) ( $context['invocation_id'] ?? '' );
		$log_id        = (int) ( $context['log_id'] ?? 0 );
		$snapshot_id   = (int) ( $context['snapshot_id'] ?? 0 );

		$store    = new SnapshotStore();
		$snapshot = '' !== $invocation_id ? $store->find_by_invocation_id( $invocation_id ) : null;

		// No snapshot row means there was no snapshot spec to capture against
		// (or capture failed). Without pre-state we can't diff and have
		// nothing to roll back to. Surface that to the caller.
		if ( null === $snapshot || empty( $snapshot['surfaces'] ) ) {
			return array(
				'result'      => $result,
				'diff'        => array(),
				'rolled_back' => false,
				'note'        => 'dry_run: no snapshot config for this ability, nothing to roll back',
			);
		}

		$diff = ( new DiffService() )->compute( $snapshot );

		$rollback = self::build_rollback_service();

		// reference: prefer log_id on the 6.9+ path, fall back to invocation_id
		// for the legacy path where the wrap doesn't yet expose the log row id
		// to the filter (it was written immediately before this filter fires
		// but the resolver handles the uuid form just as well).
		$reference = $log_id > 0 ? $log_id : $invocation_id;

		$rb_result = $rollback->rollback( $reference, true );

		if ( $rb_result instanceof WP_Error ) {
			// Rollback failed AFTER a successful execute. Caller now has a
			// state inconsistency. Mark the audit row as error + meta the
			// failure reason so it shows up in the dashboard, and return
			// WP_Error to the caller so they don't think it was a no-op.
			self::mark_audit_row_failed( $log_id, $invocation_id, $rb_result );
			return $rb_result;
		}

		// rolled_back status was set by RollbackService::rollback. Tag the
		// row with dry_run=1 so filters can distinguish this from a manual
		// rollback after-the-fact.
		$resolved_log_id = $log_id > 0 ? $log_id : self::resolve_log_id( $invocation_id );
		if ( $resolved_log_id > 0 ) {
			LogMeta::set( $resolved_log_id, 'dry_run', '1' );

			// On the 6.9+ path the after-hook listener has not yet run; it
			// will try to overwrite status='ok' if we don't short it out.
			$ctx = InvocationContext::find_for( $ability_name );
			if ( null !== $ctx ) {
				// Patch the audit row with the result + duration the
				// after-hook would have written, so the row reflects what
				// actually happened (the original execute result) before
				// status flipped to rolled_back.
				$audit       = new AuditLogger();
				$duration_ms = (int) ( $context['duration_ms'] ?? 0 );
				$audit->complete(
					$ctx->log_id,
					array(
						'result_json' => self::encode_result( $result ),
						'duration_ms' => $duration_ms,
					)
				);
				// Re-assert rolled_back in case the ->complete() call above
				// touched status (it doesn't unless we pass it, but be
				// explicit).
				( new LogRepository() )->update_status( $ctx->log_id, 'rolled_back' );
				$ctx->completed = true;
			}
		}

		return array(
			'result'      => $result,
			'diff'        => $diff,
			'rolled_back' => true,
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
	 * Look up a log id by invocation uuid. Used when the legacy path didn't
	 * thread log_id through the filter context.
	 *
	 * @param string $invocation_id UUID.
	 */
	private static function resolve_log_id( string $invocation_id ): int {
		if ( '' === $invocation_id ) {
			return 0;
		}
		$row = ( new LogRepository() )->find_by_invocation_id( $invocation_id );
		return null === $row ? 0 : (int) ( $row['id'] ?? 0 );
	}

	/**
	 * Mark the audit row as error + record dry_run_failed reason on rollback failure.
	 *
	 * @param int     $log_id        Log id (may be 0 on legacy path).
	 * @param string  $invocation_id UUID.
	 * @param WP_Error $err          Rollback error.
	 */
	private static function mark_audit_row_failed( int $log_id, string $invocation_id, WP_Error $err ): void {
		$resolved = $log_id > 0 ? $log_id : self::resolve_log_id( $invocation_id );
		if ( $resolved <= 0 ) {
			return;
		}
		( new LogRepository() )->update_status( $resolved, 'error' );
		LogMeta::set( $resolved, 'dry_run', '1' );
		LogMeta::set( $resolved, 'dry_run_failed', (string) $err->get_error_code() );

		$ctx = InvocationContext::current();
		if ( null !== $ctx ) {
			$ctx->completed = true;
		}
	}
}
