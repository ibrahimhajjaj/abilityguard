<?php
/**
 * Execute-callback wrapper. Owns enforcement (lock + approval + extension
 * filter + execute + error-path audit). Observability (snapshot capture,
 * audit-row creation, completion actions) lives in InvocationObserver and
 * runs from the WP 6.9 wp_before_execute_ability / wp_after_execute_ability
 * hooks. If no observer context exists (callable invoked outside
 * WP_Ability::execute), the wrap is a transparent pass-through.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Concurrency\Lock;
use AbilityGuard\Installer;
use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;
use Throwable;
use WP_Error;

/**
 * Returned callable replaces the ability's execute_callback. Responsibilities
 * after the v1.3 split:
 *
 *   1. Acquire the per-surface advisory lock (or reject 429).
 *   2. Run abilityguard_pre_execute_decision filter; if it returns a
 *      WP_Error, short-circuit (this is the seam DryRun and RateLimiter
 *      will plug into in follow-up tickets).
 *   3. Approval gate: when requires_approval && !is_approving(), record a
 *      pending audit row and return WP_Error 202.
 *   4. Invoke the original callback and translate exceptions into
 *      WP_Error-style failures with full audit completion.
 *
 * Observability that used to live here moved to InvocationObserver:
 *   - pre-snapshot capture and audit-row insert run in the
 *     wp_before_execute_ability listener.
 *   - post-snapshot capture and audit-row completion run in the
 *     wp_after_execute_ability listener (success only).
 *   - We still finalize audit rows on the error path because
 *     wp_after_execute_ability does NOT fire when execute returns WP_Error.
 */
final class AbilityWrapper {

	/**
	 * Constructor.
	 *
	 * @param SnapshotServiceInterface $snapshots    Snapshot collaborator.
	 * @param AuditLoggerInterface     $audit        Audit collaborator.
	 * @param string                   $ability_name Registered ability name.
	 * @param array<string, mixed>     $safety       The safety config sub-array.
	 */
	public function __construct(
		private SnapshotServiceInterface $snapshots,
		private AuditLoggerInterface $audit,
		private string $ability_name,
		private array $safety
	) {}

	/**
	 * Build the wrapped callable to replace execute_callback.
	 *
	 * @param callable $original_callback Original execute_callback from the ability args.
	 *
	 * @return callable
	 */
	public function wrap( callable $original_callback ): callable {
		return function ( $input = null ) use ( $original_callback ) {
			$ctx = InvocationContext::find_for( $this->ability_name );

			// No observer context: the callable was invoked outside the
			// abilities-api flow (no wp_before_execute_ability fired). Pass
			// straight through without enforcement or audit. The plugin's
			// real entry point is WP_Ability::execute().
			if ( null === $ctx ) {
				return $original_callback( $input );
			}

			return $this->run_with_context( $ctx, $original_callback, $input );
		};
	}

	/**
	 * Enforcement-only path on WP 6.9+ where the observer set up ctx.
	 *
	 * @param InvocationContext $ctx               The context the observer pushed.
	 * @param callable          $original_callback Original callback.
	 * @param mixed             $input             Input.
	 */
	private function run_with_context( InvocationContext $ctx, callable $original_callback, mixed $input ): mixed {
		$destructive       = (bool) ( $this->safety['destructive'] ?? false );
		$requires_approval = ! empty( $this->safety['requires_approval'] );

		// ---------------------------------------------------------------
		// Advisory lock: serialize invocations sharing the same surface set.
		// ---------------------------------------------------------------
		$lock_key       = null;
		$lock_inherited = false;
		$lock_timeout   = $this->resolve_lock_timeout();
		$has_spec       = ! empty( $this->safety['snapshot'] );

		if ( $has_spec && $lock_timeout >= 0 ) {
			$resolved_spec = $this->resolve_spec_for_lock( $input );
			if ( array() !== $resolved_spec ) {
				$lock_key = Lock::key_for_spec( $resolved_spec );
				if ( null !== $ctx->parent_invocation_id && Lock::is_held( $lock_key ) ) {
					$lock_inherited = true;
				} elseif ( ! Lock::acquire( $lock_key, $lock_timeout ) ) {
					// Lock-timeout contract: NO audit row. Drop the
					// placeholder the before-hook inserted and pop ctx so
					// the sweeper doesn't resurrect it as 'error'.
					$this->discard_placeholder_row( $ctx );
					return new WP_Error(
						'abilityguard_lock_timeout',
						'Another invocation is in progress for the same surfaces. Please retry.',
						array( 'status' => 429 )
					);
				}
			}
		}

		try {
			// -------------------------------------------------------------
			// Extension seam for follow-up enforcement plugins (DryRun,
			// RateLimiter). Filter returning a WP_Error short-circuits.
			// -------------------------------------------------------------
			$decision = apply_filters(
				'abilityguard_pre_execute_decision',
				null,
				$this->ability_name,
				$input,
				array(
					'invocation_id' => $ctx->invocation_id,
					'caller_type'   => $ctx->caller_type,
					'caller_id'     => $ctx->caller_id,
					'destructive'   => $destructive,
					'safety'        => $this->safety,
				)
			);
			if ( $decision instanceof WP_Error ) {
				$this->finalize_audit_for_error( $ctx, $decision, 0 );
				return $decision;
			}

			// -------------------------------------------------------------
			// Approval gate: pending row + WP_Error 202 short-circuit.
			// -------------------------------------------------------------
			if ( $requires_approval && ! ApprovalService::is_approving() ) {
				$this->audit->complete(
					$ctx->log_id,
					array(
						'result_json' => null,
						'status'      => 'pending',
						'duration_ms' => 0,
					)
				);
				$ctx->completed         = true;
				$ctx->approval_handled = true;

				$stages = array();
				if ( is_array( $this->safety['requires_approval'] ?? null )
					&& isset( $this->safety['requires_approval']['stages'] )
					&& is_array( $this->safety['requires_approval']['stages'] )
				) {
					$stages = $this->safety['requires_approval']['stages'];
				}
				$approval_service = new ApprovalService();
				$approval_id      = $approval_service->request( $this->ability_name, $input, $ctx->invocation_id, $ctx->log_id, $stages );

				return new WP_Error(
					'abilityguard_pending_approval',
					sprintf( 'Ability "%s" requires approval before execution.', $this->ability_name ),
					array(
						'status'      => 202,
						'approval_id' => $approval_id,
						'log_id'      => $ctx->log_id,
					)
				);
			}

			// -------------------------------------------------------------
			// Execute.
			// -------------------------------------------------------------
			$start  = hrtime( true );
			$result = null;
			$thrown = null;
			InvocationStack::push( $ctx->invocation_id );
			try {
				$result = $original_callback( $input );
			} catch ( Throwable $e ) {
				$thrown = $e;
			} finally {
				InvocationStack::pop();
			}
			$duration_ms = (int) ( ( hrtime( true ) - $start ) / 1_000_000 );

			$is_error = ( null !== $thrown ) || is_wp_error( $result );

			if ( $is_error ) {
				/**
				 * Fires when the wrapped callback throws or returns WP_Error.
				 *
				 * @since 0.5.0
				 *
				 * @param string         $invocation_id UUID.
				 * @param string         $ability_name  Ability name.
				 * @param Throwable|null $thrown        Exception (null if WP_Error).
				 * @param mixed          $result        WP_Error returned (null if exception).
				 * @param int            $duration_ms   Time spent in the callback.
				 */
				do_action( 'abilityguard_invocation_error', $ctx->invocation_id, $this->ability_name, $thrown, $result, $duration_ms );

				if ( null !== $thrown ) {
					/**
					 * Fires only when the wrapped callback threw an uncaught exception.
					 *
					 * @since 1.2.0
					 *
					 * @param string    $invocation_id UUID.
					 * @param string    $ability_name  Ability name.
					 * @param Throwable $thrown        The exception.
					 * @param int       $duration_ms   Duration in ms.
					 */
					do_action( 'abilityguard_invocation_failed', $ctx->invocation_id, $this->ability_name, $thrown, $duration_ms );
				}

				$this->finalize_audit_for_error( $ctx, $thrown ?? $result, $duration_ms );

				if ( null !== $thrown ) {
					throw $thrown;
				}
				return $result;
			}

			// Success path. The wp_after_execute_ability listener will
			// normally finalize the audit row + capture post-snapshot. But
			// dry-run (and other post-execute filters) need the post-snapshot
			// available NOW so they can diff and roll back before output
			// validation runs against a transformed envelope. Capture it here
			// so the filter has something to compare against; the after-hook
			// listener will harmlessly overwrite the same post_state_json if
			// it still fires (DryRun marks ctx->completed to short it out).
			if ( null !== ( $ctx->snapshot['snapshot_id'] ?? null ) ) {
				$this->snapshots->capture_post( (int) $ctx->snapshot['snapshot_id'], $this->safety, $input );
			}

			/**
			 * Filters the result returned from the wrapped execute_callback
			 * after the post-snapshot has been captured. Returning a
			 * transformed value lets follow-up plugins (DryRun) wrap the
			 * result in an envelope and trigger side effects like
			 * auto-rollback. Implementations that complete the audit row +
			 * mark $ctx->completed prevent the after-hook listener from
			 * double-writing.
			 *
			 * @since 1.3.0
			 *
			 * @param mixed                $result       The execute result.
			 * @param string               $ability_name Ability name.
			 * @param mixed                $input        Input passed to execute.
			 * @param array<string, mixed> $context      invocation_id, log_id,
			 *                                           snapshot_id, caller_type,
			 *                                           caller_id, destructive,
			 *                                           safety, duration_ms.
			 */
			$result = apply_filters(
				'abilityguard_post_execute_result',
				$result,
				$this->ability_name,
				$input,
				array(
					'invocation_id' => $ctx->invocation_id,
					'log_id'        => $ctx->log_id,
					'snapshot_id'   => (int) ( $ctx->snapshot['snapshot_id'] ?? 0 ),
					'caller_type'   => $ctx->caller_type,
					'caller_id'     => $ctx->caller_id,
					'destructive'   => $destructive,
					'safety'        => $this->safety,
					'duration_ms'   => $duration_ms,
				)
			);
			return $result;
		} finally {
			if ( null !== $lock_key && ! $lock_inherited ) {
				Lock::release( $lock_key );
			}
		}
	}

	/**
	 * Delete the placeholder audit row inserted by the before-hook.
	 *
	 * Used by the lock-timeout path which contractually leaves no log
	 * trace. Pops the context too so the shutdown sweeper skips it.
	 *
	 * @param InvocationContext $ctx Context.
	 */
	private function discard_placeholder_row( InvocationContext $ctx ): void {
		if ( $ctx->log_id > 0 ) {
			global $wpdb;
			$table = Installer::table( 'log' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $table, array( 'id' => $ctx->log_id ), array( '%d' ) );
		}
		$ctx->completed = true;
		$top            = InvocationContext::current();
		if ( null !== $top && $top->invocation_id === $ctx->invocation_id ) {
			InvocationContext::pop();
		}
	}

	/**
	 * Mark the context's audit row as completed on the error path. The
	 * after-hook does NOT fire when the wrap returns WP_Error or when an
	 * exception escapes, so we have to write the terminal fields here.
	 *
	 * @param InvocationContext $ctx         The context.
	 * @param mixed             $err         WP_Error or Throwable for hash purposes.
	 * @param int               $duration_ms Duration.
	 */
	private function finalize_audit_for_error( InvocationContext $ctx, mixed $err, int $duration_ms ): void {
		if ( $ctx->completed ) {
			return;
		}

		// We don't redact-and-store WP_Error/Throwable bodies; result_json
		// stays null on error to mirror the v1.2 behavior.
		$this->audit->complete(
			$ctx->log_id,
			array(
				'result_json' => null,
				'status'      => 'error',
				'duration_ms' => $duration_ms,
				'post_hash'   => null,
				'caller_type' => $ctx->caller_type,
				'caller_id'   => $ctx->caller_id,
			)
		);
		$ctx->completed = true;

		/**
		 * Fires after the audit row is patched on the error path.
		 *
		 * @since 0.5.0
		 *
		 * @param string $invocation_id UUID.
		 * @param string $ability_name  Ability name.
		 * @param string $status        'ok' or 'error'.
		 * @param int    $duration_ms   Time spent.
		 * @param array<string, mixed> $context Context.
		 */
		do_action(
			'abilityguard_invocation_completed',
			$ctx->invocation_id,
			$this->ability_name,
			'error',
			$duration_ms,
			array(
				'destructive'      => (bool) ( $this->safety['destructive'] ?? false ),
				'caller_type'      => $ctx->caller_type,
				'caller_id'        => $ctx->caller_id,
				'snapshot_id'      => (int) ( $ctx->snapshot['snapshot_id'] ?? 0 ),
				'args_truncated'   => false,
				'result_truncated' => false,
			)
		);

		// Pop our context off the stack so the sweeper doesn't double-flip
		// it to error at shutdown.
		$top = InvocationContext::current();
		if ( null !== $top && $top->invocation_id === $ctx->invocation_id ) {
			InvocationContext::pop();
		}
	}

	/**
	 * Resolve the effective lock timeout from safety config and filter.
	 */
	private function resolve_lock_timeout(): int {
		if ( array_key_exists( 'lock_timeout', $this->safety ) ) {
			return (int) $this->safety['lock_timeout'];
		}
		return (int) apply_filters( 'abilityguard_lock_timeout', 5 );
	}

	/**
	 * Resolve the snapshot spec for computing the lock key.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_spec_for_lock( mixed $input ): array {
		if ( empty( $this->safety['snapshot'] ) ) {
			return array();
		}
		$snapshot = $this->safety['snapshot'];
		if ( is_callable( $snapshot ) ) {
			$resolved = $snapshot( $input );
			return is_array( $resolved ) ? $resolved : array();
		}
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}
		return array();
	}
}
