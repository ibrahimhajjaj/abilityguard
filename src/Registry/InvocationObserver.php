<?php
/**
 * WP 6.9 before/after-execute-ability listeners that own observability.
 *
 * The wrap (AbilityWrapper) used to do everything: pre-snapshot, audit
 * insert, execute, post-snapshot, audit complete, plus enforcement (lock,
 * approval). After WP 6.9 added wp_before_execute_ability and
 * wp_after_execute_ability we can split observability out cleanly:
 *
 *   - Before-hook: capture pre-snapshot + insert placeholder audit row +
 *     fire abilityguard_invocation_started.
 *   - After-hook (success path only): capture post-snapshot + complete the
 *     audit row + fire abilityguard_invocation_completed.
 *   - The wrap keeps enforcement: lock, abilityguard_pre_execute_decision
 *     filter, approval gate, execute, and the error-path audit completion
 *     (the after-hook does NOT fire on WP_Error or output-validation
 *     failure, so the wrap has to handle that case itself).
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;

/**
 * Wires the WP 6.9 hooks. Idempotent.
 */
final class InvocationObserver {

	/**
	 * Map ability_name => safety[] for abilities we wrap.
	 *
	 * Populated by RegistrationFilter at filter_args() time so the listener
	 * doesn't have to fish around inside WP_Abilities_Registry to know which
	 * abilities have safety config.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $safety_by_ability = array();

	/**
	 * Whether we've wired hooks already.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param SnapshotServiceInterface $snapshots Snapshot collaborator.
	 * @param AuditLoggerInterface     $audit     Audit collaborator.
	 */
	public function __construct(
		private SnapshotServiceInterface $snapshots,
		private AuditLoggerInterface $audit
	) {}

	/**
	 * Wire the hooks. Idempotent.
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_before_execute_ability', array( $this, 'on_before' ), 10, 2 );
		add_action( 'wp_after_execute_ability', array( $this, 'on_after' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'sweep' ), 1 );
	}

	/**
	 * Record that an ability has safety config so the before-hook can pick it up.
	 *
	 * @param string               $ability_name Ability name.
	 * @param array<string, mixed> $safety       Safety array (post-reconcile, post-collectors-strip).
	 */
	public static function register_safety( string $ability_name, array $safety ): void {
		self::$safety_by_ability[ $ability_name ] = $safety;
	}

	/**
	 * Look up safety for an ability. Used by AbilityWrapper as fallback when
	 * no context is on the stack (pre-6.9 / no-hooks path).
	 *
	 * @param string $ability_name Ability name.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function safety_for( string $ability_name ): ?array {
		return self::$safety_by_ability[ $ability_name ] ?? null;
	}

	/**
	 * Listener: wp_before_execute_ability.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Input.
	 */
	public function on_before( string $ability_name, mixed $input ): void {
		$safety = self::$safety_by_ability[ $ability_name ] ?? null;
		if ( null === $safety ) {
			return;
		}
		// Skip during approval execution: ApprovalService re-runs the ability
		// with is_approving() set, and the wrap short-circuits its own
		// observability in that mode. Mirror that here so we don't double-log.
		if ( ApprovalService::is_approving() ) {
			return;
		}

		$ctx = new InvocationContext(
			InvocationHelpers::uuid4(),
			InvocationStack::current(),
			$ability_name,
			InvocationHelpers::detect_caller_type(),
			McpContext::current(),
			InvocationHelpers::current_user_id(),
			(int) hrtime( true ),
			$safety,
			$input
		);

		// Pre-snapshot lives here now. The wrap reads ctx->snapshot when it
		// needs pre_hash / snapshot_id (e.g. for the approval-pending row).
		$ctx->snapshot = $this->snapshots->capture( $ctx->invocation_id, $safety, $input );

		// Placeholder audit row. The wrap or the after-hook will complete it.
		// We keep status='ok' as the optimistic default, and the sweeper
		// flips it to 'error' if the row is never completed (output
		// validation failure, fatal between hooks).
		$args_shape = InvocationHelpers::shape_for_log(
			$safety,
			$input,
			'input',
			'args',
			'abilityguard_max_args_bytes',
			65536
		);

		$ctx->log_id = $this->audit->log(
			array(
				'invocation_id'        => $ctx->invocation_id,
				'parent_invocation_id' => $ctx->parent_invocation_id,
				'ability_name'         => $ability_name,
				'caller_type'          => $ctx->caller_type,
				'caller_id'            => $ctx->caller_id,
				'user_id'              => $ctx->user_id,
				'args_json'            => $args_shape['json'],
				'result_json'          => null,
				'status'               => 'ok',
				'destructive'          => (bool) ( $safety['destructive'] ?? false ),
				'duration_ms'          => 0,
				'pre_hash'             => $ctx->snapshot['pre_hash'] ?? null,
				'post_hash'            => null,
				'snapshot_id'          => $ctx->snapshot['snapshot_id'] ?? null,
			)
		);

		if ( $args_shape['truncated'] ) {
			InvocationHelpers::maybe_doing_it_wrong( $ability_name );
		}

		if ( $ctx->log_id > 0 && ! empty( $safety['skip_drift_check'] ) ) {
			LogMeta::set( $ctx->log_id, 'skip_drift_check', '1' );
		}

		InvocationContext::push( $ctx );

		/**
		 * Fires immediately before the wrapped callback runs.
		 *
		 * Same payload as before; only the call site moved from inside the
		 * wrap to the wp_before_execute_ability listener.
		 *
		 * @since 0.5.0
		 *
		 * @param string $invocation_id UUID.
		 * @param string $ability_name  Ability name.
		 * @param mixed  $input         Input (un-redacted).
		 * @param array<string, mixed> $context destructive, snapshot_id, caller_type.
		 */
		do_action(
			'abilityguard_invocation_started',
			$ctx->invocation_id,
			$ability_name,
			$input,
			array(
				'destructive' => (bool) ( $safety['destructive'] ?? false ),
				'snapshot_id' => (int) ( $ctx->snapshot['snapshot_id'] ?? 0 ),
				'caller_type' => $ctx->caller_type,
			)
		);
	}

	/**
	 * Listener: wp_after_execute_ability. Fires only on success path.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input        Input.
	 * @param mixed  $result       Result.
	 */
	public function on_after( string $ability_name, mixed $input, mixed $result ): void {
		$ctx = InvocationContext::find_for( $ability_name );
		if ( null === $ctx || $ctx->completed ) {
			return;
		}

		$safety = $ctx->safety;

		if ( null !== ( $ctx->snapshot['snapshot_id'] ?? null ) ) {
			$this->snapshots->capture_post( (int) $ctx->snapshot['snapshot_id'], $safety, $input );
		}

		$duration_ms = (int) ( ( hrtime( true ) - $ctx->start_hr ) / 1_000_000 );

		// Re-detect caller_type at completion to pick up MCP context that
		// might have been set by mcp_adapter_pre_tool_call AFTER our before-hook.
		$ctx->caller_type = InvocationHelpers::detect_caller_type();
		$ctx->caller_id   = McpContext::current();

		$post_hash    = InvocationHelpers::hash_or_null( $result );
		$result_shape = InvocationHelpers::shape_for_log(
			$safety,
			$result,
			'result',
			'result',
			'abilityguard_max_result_bytes',
			131072
		);

		if ( $result_shape['truncated'] ) {
			InvocationHelpers::maybe_doing_it_wrong( $ability_name );
		}

		$this->audit->complete(
			$ctx->log_id,
			array(
				'result_json' => $result_shape['json'],
				'status'      => 'ok',
				'duration_ms' => $duration_ms,
				'post_hash'   => $post_hash,
				'caller_type' => $ctx->caller_type,
				'caller_id'   => $ctx->caller_id,
			)
		);

		$ctx->completed = true;

		/**
		 * Fires after every successful invocation, after the audit row is patched.
		 *
		 * @since 0.5.0
		 *
		 * @param string $invocation_id UUID.
		 * @param string $ability_name  Ability name.
		 * @param string $status        'ok' or 'error'.
		 * @param int    $duration_ms   Time spent in the original callback.
		 * @param array<string, mixed> $context destructive, caller_type, caller_id, snapshot_id, args_truncated, result_truncated.
		 */
		do_action(
			'abilityguard_invocation_completed',
			$ctx->invocation_id,
			$ability_name,
			'ok',
			$duration_ms,
			array(
				'destructive'      => (bool) ( $safety['destructive'] ?? false ),
				'caller_type'      => $ctx->caller_type,
				'caller_id'        => $ctx->caller_id,
				'snapshot_id'      => (int) ( $ctx->snapshot['snapshot_id'] ?? 0 ),
				'args_truncated'   => false,
				'result_truncated' => (bool) $result_shape['truncated'],
			)
		);

		// Clean up: pop until the completed context is gone. Re-entrant
		// usage means the completed ctx may not be at the top - in practice
		// it's the same level the matching before-hook pushed at.
		InvocationContext::pop();
	}

	/**
	 * Shutdown sweeper: any context still on the stack at request end never
	 * had its after-hook fire. Most likely cause: output validation failed
	 * (core swallows the result, returns WP_Error, skips wp_after). Mark
	 * those rows as 'error' so we don't leak optimistic 'ok' rows with no
	 * result_json.
	 */
	public function sweep(): void {
		foreach ( InvocationContext::drain() as $ctx ) {
			if ( $ctx->completed ) {
				continue;
			}
			$this->audit->complete(
				$ctx->log_id,
				array(
					'status'      => 'error',
					'duration_ms' => (int) ( ( hrtime( true ) - $ctx->start_hr ) / 1_000_000 ),
				)
			);
		}
	}

	/**
	 * Test helper: clear registered hooks + state.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$safety_by_ability = array();
		self::$registered        = false;
	}
}
