<?php
/**
 * Execute-callback wrapper. Owns enforcement (lock + approval + extension
 * filter + execute + error-path audit). Observability (snapshot capture,
 * audit-row creation, completion actions) lives in InvocationObserver and
 * runs from the WP 6.9 wp_before_execute_ability / wp_after_execute_ability
 * hooks. When no context has been pushed by the observer (pre-6.9 hosts,
 * direct callable invocation) this class falls back to running the full
 * legacy flow on its own so behavior is unchanged.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Audit\LogMeta;
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

			// Legacy path: no observer context (pre-6.9 host, or callable
			// invoked directly without going through WP_Ability::execute).
			// Run the full self-contained flow so behavior matches v1.2.
			if ( null === $ctx ) {
				return $this->run_legacy( $original_callback, $input );
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

			// Success: the wp_after_execute_ability listener finalizes the
			// audit row and fires abilityguard_invocation_completed. Just
			// return; the listener will run after core's output validation.
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
	 * Pre-6.9 / no-observer fallback. Mirrors the v1.2 wrap end to end so
	 * behavior is unchanged when wp_before_execute_ability never fires.
	 *
	 * @param callable $original_callback Original.
	 * @param mixed    $input             Input.
	 */
	private function run_legacy( callable $original_callback, mixed $input ): mixed {
		$invocation_id        = InvocationHelpers::uuid4();
		$parent_invocation_id = InvocationStack::current();
		$destructive          = (bool) ( $this->safety['destructive'] ?? false );
		$requires_approval    = ! empty( $this->safety['requires_approval'] );

		$lock_key       = null;
		$lock_inherited = false;
		$lock_timeout   = $this->resolve_lock_timeout();
		$has_spec       = ! empty( $this->safety['snapshot'] );

		if ( $has_spec && $lock_timeout >= 0 ) {
			$resolved_spec = $this->resolve_spec_for_lock( $input );
			if ( array() !== $resolved_spec ) {
				$lock_key = Lock::key_for_spec( $resolved_spec );
				if ( null !== $parent_invocation_id && Lock::is_held( $lock_key ) ) {
					$lock_inherited = true;
				} elseif ( ! Lock::acquire( $lock_key, $lock_timeout ) ) {
					return new WP_Error(
						'abilityguard_lock_timeout',
						'Another invocation is in progress for the same surfaces. Please retry.',
						array( 'status' => 429 )
					);
				}
			}
		}

		// Approval-pending short-circuit (legacy: insert pending row, return 202).
		if ( $requires_approval && ! ApprovalService::is_approving() ) {
			$snapshot = $this->snapshots->capture( $invocation_id, $this->safety, $input );

			$log_id = $this->audit->log(
				array(
					'invocation_id'        => $invocation_id,
					'parent_invocation_id' => $parent_invocation_id,
					'ability_name'         => $this->ability_name,
					'caller_type'          => InvocationHelpers::detect_caller_type(),
					'user_id'              => InvocationHelpers::current_user_id(),
					'args_json'            => InvocationHelpers::encode_or_null( InvocationHelpers::redact_value( $this->safety, $input, 'input' ) ),
					'result_json'          => null,
					'status'               => 'pending',
					'destructive'          => $destructive,
					'duration_ms'          => 0,
					'pre_hash'             => $snapshot['pre_hash'],
					'post_hash'            => null,
					'snapshot_id'          => $snapshot['snapshot_id'],
				)
			);

			if ( null !== $lock_key && ! $lock_inherited ) {
				Lock::release( $lock_key );
			}

			$stages = array();
			if ( is_array( $this->safety['requires_approval'] ?? null )
				&& isset( $this->safety['requires_approval']['stages'] )
				&& is_array( $this->safety['requires_approval']['stages'] )
			) {
				$stages = $this->safety['requires_approval']['stages'];
			}
			$approval_service = new ApprovalService();
			$approval_id      = $approval_service->request( $this->ability_name, $input, $invocation_id, $log_id, $stages );

			return new WP_Error(
				'abilityguard_pending_approval',
				sprintf( 'Ability "%s" requires approval before execution.', $this->ability_name ),
				array(
					'status'      => 202,
					'approval_id' => $approval_id,
					'log_id'      => $log_id,
				)
			);
		}

		try {
			$snapshot = $this->snapshots->capture( $invocation_id, $this->safety, $input );

			do_action(
				'abilityguard_invocation_started',
				$invocation_id,
				$this->ability_name,
				$input,
				array(
					'destructive' => $destructive,
					'snapshot_id' => (int) ( $snapshot['snapshot_id'] ?? 0 ),
					'caller_type' => InvocationHelpers::detect_caller_type(),
				)
			);

			// Pre-execute decision filter (legacy path also exposes it).
			$decision = apply_filters(
				'abilityguard_pre_execute_decision',
				null,
				$this->ability_name,
				$input,
				array(
					'invocation_id' => $invocation_id,
					'caller_type'   => InvocationHelpers::detect_caller_type(),
					'caller_id'     => McpContext::current(),
					'destructive'   => $destructive,
					'safety'        => $this->safety,
				)
			);
			if ( $decision instanceof WP_Error ) {
				$this->write_legacy_audit_row( $invocation_id, $parent_invocation_id, $snapshot, $input, null, 'error', 0, $destructive );
				return $decision;
			}

			$start  = hrtime( true );
			$result = null;
			$status = 'ok';
			$thrown = null;
			InvocationStack::push( $invocation_id );
			try {
				$result = $original_callback( $input );
				if ( is_wp_error( $result ) ) {
					$status = 'error';
				}
			} catch ( Throwable $e ) {
				$status = 'error';
				$thrown = $e;
			} finally {
				InvocationStack::pop();
			}
			$duration_ms = (int) ( ( hrtime( true ) - $start ) / 1_000_000 );

			if ( 'error' === $status ) {
				do_action( 'abilityguard_invocation_error', $invocation_id, $this->ability_name, $thrown, $result, $duration_ms );
				if ( null !== $thrown ) {
					do_action( 'abilityguard_invocation_failed', $invocation_id, $this->ability_name, $thrown, $duration_ms );
				}
			}

			if ( 'ok' === $status && null !== $snapshot['snapshot_id'] ) {
				$this->snapshots->capture_post( $snapshot['snapshot_id'], $this->safety, $input );
			}

			$this->write_legacy_audit_row( $invocation_id, $parent_invocation_id, $snapshot, $input, $thrown ? null : $result, $status, $duration_ms, $destructive );

			if ( null !== $thrown ) {
				throw $thrown;
			}
			return $result;
		} finally {
			if ( null !== $lock_key && ! $lock_inherited ) {
				Lock::release( $lock_key );
			}
		}
	}

	/**
	 * Insert a single audit row (legacy path).
	 *
	 * @param string               $invocation_id        UUID.
	 * @param string|null          $parent_invocation_id Parent UUID.
	 * @param array<string, mixed> $snapshot             Snapshot row.
	 * @param mixed                $input                Input.
	 * @param mixed                $result               Result (null on error).
	 * @param string               $status               'ok' or 'error'.
	 * @param int                  $duration_ms          Duration.
	 * @param bool                 $destructive          Destructive flag.
	 */
	private function write_legacy_audit_row(
		string $invocation_id,
		?string $parent_invocation_id,
		array $snapshot,
		mixed $input,
		mixed $result,
		string $status,
		int $duration_ms,
		bool $destructive
	): void {
		$mcp_id      = McpContext::current();
		$caller_type = null !== $mcp_id ? 'mcp' : InvocationHelpers::detect_caller_type();

		$post_hash = InvocationHelpers::hash_or_null( $result );

		$args_shape   = InvocationHelpers::shape_for_log( $this->safety, $input, 'input', 'args', 'abilityguard_max_args_bytes', 65536 );
		$result_shape = InvocationHelpers::shape_for_log( $this->safety, $result, 'result', 'result', 'abilityguard_max_result_bytes', 131072 );

		if ( $args_shape['truncated'] || $result_shape['truncated'] ) {
			InvocationHelpers::maybe_doing_it_wrong( $this->ability_name );
		}

		$log_id = $this->audit->log(
			array(
				'invocation_id'        => $invocation_id,
				'parent_invocation_id' => $parent_invocation_id,
				'ability_name'         => $this->ability_name,
				'caller_type'          => $caller_type,
				'caller_id'            => $mcp_id,
				'user_id'              => InvocationHelpers::current_user_id(),
				'args_json'            => $args_shape['json'],
				'result_json'          => $result_shape['json'],
				'status'               => $status,
				'destructive'          => $destructive,
				'duration_ms'          => $duration_ms,
				'pre_hash'             => $snapshot['pre_hash'],
				'post_hash'            => $post_hash,
				'snapshot_id'          => $snapshot['snapshot_id'],
			)
		);

		if ( $log_id > 0 && ! empty( $this->safety['skip_drift_check'] ) ) {
			LogMeta::set( $log_id, 'skip_drift_check', '1' );
		}

		do_action(
			'abilityguard_invocation_completed',
			$invocation_id,
			$this->ability_name,
			$status,
			$duration_ms,
			array(
				'destructive'      => $destructive,
				'caller_type'      => $caller_type,
				'caller_id'        => $mcp_id,
				'snapshot_id'      => (int) ( $snapshot['snapshot_id'] ?? 0 ),
				'args_truncated'   => (bool) $args_shape['truncated'],
				'result_truncated' => (bool) $result_shape['truncated'],
			)
		);
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
