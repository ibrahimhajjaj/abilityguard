<?php
/**
 * Approval queue orchestration service.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Approval;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use WP_Error;

/**
 * Manages the lifecycle of approval requests: request, approve, reject.
 *
 * The static $approving flag is used to short-circuit RegistrationFilter::filter_args()
 * during approve() so the ability's execute_callback is not re-wrapped and re-queued.
 */
final class ApprovalService {

	/**
	 * Set to true while approve() is executing to prevent re-queueing.
	 *
	 * @var bool
	 */
	private static bool $approving = false;

	/**
	 * Approval repository.
	 *
	 * @var ApprovalRepository
	 */
	private ApprovalRepository $repo;

	/**
	 * Log repository.
	 *
	 * @var LogRepository
	 */
	private LogRepository $logs;

	/**
	 * Constructor.
	 *
	 * @param ApprovalRepository|null $repo Approval repository (defaults to production wiring).
	 * @param LogRepository|null      $logs Log repository (defaults to production wiring).
	 */
	public function __construct(
		?ApprovalRepository $repo = null,
		?LogRepository $logs = null
	) {
		$this->repo = $repo ?? new ApprovalRepository();
		$this->logs = $logs ?? new LogRepository();
	}

	/**
	 * Whether an approval is currently being processed.
	 */
	public static function is_approving(): bool {
		return self::$approving;
	}

	/**
	 * Persist a new approval request row.
	 *
	 * @param string                          $ability_name  Registered ability name.
	 * @param mixed                           $input         Original input passed to execute_callback.
	 * @param string                          $invocation_id UUID for this invocation.
	 * @param int                             $log_id        Primary key of the log row created for this invocation.
	 * @param array<int, array{cap?: string}> $stages        Optional multi-stage spec. Empty = single stage with default cap.
	 *
	 * @return int Approval row id (0 on failure).
	 */
	public function request( string $ability_name, mixed $input, string $invocation_id, int $log_id, array $stages = array() ): int {
		global $wpdb;
		$table = Installer::table( 'approvals' );

		$input_json = null !== $input ? wp_json_encode( $input ) : null;
		if ( false === $input_json ) {
			$input_json = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'log_id'       => $log_id,
				'ability_name' => $ability_name,
				'input_json'   => $input_json,
				'status'       => 'pending',
				'requested_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'decided_by'   => 0,
				'decided_at'   => null,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return 0;
		}
		$approval_id = (int) $wpdb->insert_id;

		// Always write per-stage rows. A single-stage approval (the common
		// case) is just one row with stage_index=0. This unifies the
		// state machine - approve/reject never fall through to a legacy
		// no-stages path.
		if ( array() === $stages ) {
			$stages = array( array( 'cap' => CapabilityManager::CAP ) );
		}
		$stage_table = Installer::table( 'approval_stages' );
		foreach ( array_values( $stages ) as $idx => $stage ) {
			$cap = is_array( $stage ) && isset( $stage['cap'] ) && is_string( $stage['cap'] )
				? $stage['cap']
				: CapabilityManager::CAP;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$stage_table,
				array(
					'approval_id'  => $approval_id,
					'stage_index'  => $idx,
					'required_cap' => $cap,
					'status'       => 0 === $idx ? 'waiting' : 'pending',
					'decided_by'   => 0,
					'decided_at'   => null,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s' )
			);
		}

		/**
		 * Fires when a new approval request is recorded.
		 *
		 * Hook this to send Slack/email/webhook notifications to approvers.
		 *
		 * @since 0.5.0
		 *
		 * @param int    $approval_id   Newly inserted approval row id.
		 * @param string $ability_name  Registered ability name.
		 * @param int    $log_id        Audit log row id for the pending invocation.
		 * @param mixed  $input         Original input the requester passed to the ability.
		 * @param string $invocation_id UUID for this invocation.
		 */
		do_action( 'abilityguard_approval_requested', $approval_id, $ability_name, $log_id, $input, $invocation_id );

		return $approval_id;
	}

	/**
	 * Approve a pending request: run the original callback and mark approved.
	 *
	 * Guards (in order):
	 *  1. Capability: $user_id must have `manage_abilityguard_approvals`.
	 *  2. Self-approval: $user_id must differ from the original requester.
	 *  3. Filter `abilityguard_can_approve`: site owners may veto with custom logic.
	 *
	 * @param int $approval_id Approval row id.
	 * @param int $user_id     User making the decision.
	 *
	 * @return true|WP_Error
	 */
	public function approve( int $approval_id, int $user_id ): bool|WP_Error {
		$row = $this->repo->find( $approval_id );
		if ( null === $row ) {
			return new WP_Error( 'abilityguard_not_found', 'Approval row not found.' );
		}

		if ( $user_id === (int) $row['requested_by'] ) {
			return new WP_Error(
				'abilityguard_approve_self_forbidden',
				'Approvers cannot approve their own requests.'
			);
		}

		// @phpstan-var bool $can
		$can = apply_filters( 'abilityguard_can_approve', true, $row, $user_id );
		if ( ! $can ) {
			return new WP_Error(
				'abilityguard_approve_denied',
				'Approval was denied by the abilityguard_can_approve filter.'
			);
		}

		if ( 'pending' !== $row['status'] ) {
			return new WP_Error(
				'abilityguard_already_decided',
				sprintf( 'Approval %d has already been decided (status: %s).', $approval_id, $row['status'] )
			);
		}

		$active_stage = $this->repo->find_active_stage( $approval_id );
		if ( null === $active_stage ) {
			return new WP_Error(
				'abilityguard_no_active_stage',
				'Approval has no waiting stage - already fully decided.'
			);
		}

		$required_cap = (string) $active_stage['required_cap'];
		if ( ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error(
				'abilityguard_approve_capability_missing',
				sprintf( 'User %d does not have the %s capability.', $user_id, $required_cap )
			);
		}

		$claimed = $this->claim_stage( $approval_id, (int) $active_stage['stage_index'], $user_id, 'approved' );
		if ( ! $claimed ) {
			return new WP_Error(
				'abilityguard_stage_already_decided',
				'Another approver already decided this stage.'
			);
		}

		$next_stage = $this->advance_to_next_stage( $approval_id, (int) $active_stage['stage_index'] );
		if ( null !== $next_stage ) {
			/**
			 * Fires when a multi-stage approval advances to its next stage.
			 *
			 * Hook this to re-emit notifications targeted at the new stage's approvers.
			 *
			 * @since 1.1.0
			 *
			 * @param int                  $approval_id     Approval row id.
			 * @param int                  $new_stage_index Newly-active stage index.
			 * @param string               $required_cap    Capability required to act on the new stage.
			 * @param array<string, mixed> $approval_row    Latest approval row.
			 */
			do_action( 'abilityguard_approval_advanced', $approval_id, (int) $next_stage['stage_index'], (string) $next_stage['required_cap'], $row );
			return true;
		}

		// Last stage: execute the ability and finalise.
		$ability_name = (string) $row['ability_name'];
		$ability      = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
		if ( null === $ability ) {
			return new WP_Error( 'abilityguard_ability_not_found', "Ability not found: {$ability_name}" );
		}

		$input = null;
		if ( ! empty( $row['input_json'] ) ) {
			$decoded = json_decode( (string) $row['input_json'], true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$input = $decoded;
			}
		}

		self::$approving = true;
		try {
			$result = $ability->execute( $input );
		} finally {
			self::$approving = false;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$now   = current_time( 'mysql', true );
		$table = Installer::table( 'approvals' );
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array(
				'status'     => 'approved',
				'decided_by' => $user_id,
				'decided_at' => $now,
			),
			array( 'id' => $approval_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->logs->update_status( (int) $row['log_id'], 'ok' );

		return true;
	}

	/**
	 * Atomic stage claim. Updates the stage row's status only if it's
	 * still 'waiting' - defeats the race where two approvers both click
	 * Approve at the same instant. The UPDATE-with-WHERE-current-status
	 * pattern is the standard relational defence; we don't lean on triggers.
	 *
	 * @param int    $approval_id Approval id.
	 * @param int    $stage_index Stage index to claim.
	 * @param int    $user_id     User making the decision.
	 * @param string $status      'approved' | 'rejected'.
	 *
	 * @return bool True if this call won the race, false otherwise.
	 */
	private function claim_stage( int $approval_id, int $stage_index, int $user_id, string $status ): bool {
		global $wpdb;
		$table = Installer::table( 'approval_stages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'decided_by' => $user_id,
				'decided_at' => current_time( 'mysql', true ),
			),
			array(
				'approval_id' => $approval_id,
				'stage_index' => $stage_index,
				'status'      => 'waiting',
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d', '%s' )
		);
		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Promote stage_index+1 from 'pending' to 'waiting'. Returns the new
	 * active stage row, or null if there is no next stage (i.e. the just-
	 * approved stage was the last one).
	 *
	 * @param int $approval_id     Approval id.
	 * @param int $current_stage   Stage index that was just decided.
	 *
	 * @return array<string, mixed>|null
	 */
	private function advance_to_next_stage( int $approval_id, int $current_stage ): ?array {
		global $wpdb;
		$table = Installer::table( 'approval_stages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			array( 'status' => 'waiting' ),
			array(
				'approval_id' => $approval_id,
				'stage_index' => $current_stage + 1,
				'status'      => 'pending',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
		return $this->repo->find_active_stage( $approval_id );
	}

	/**
	 * Reject a pending approval request.
	 *
	 * Applies the same three guards as approve(): capability, self-reject, filter.
	 *
	 * @param int $approval_id Approval row id.
	 * @param int $user_id     User making the decision.
	 *
	 * @return true|WP_Error
	 */
	public function reject( int $approval_id, int $user_id ): bool|WP_Error {
		$row = $this->repo->find( $approval_id );
		if ( null === $row ) {
			return new WP_Error( 'abilityguard_not_found', 'Approval row not found.' );
		}

		if ( $user_id === (int) $row['requested_by'] ) {
			return new WP_Error(
				'abilityguard_approve_self_forbidden',
				'Approvers cannot approve their own requests.'
			);
		}

		// @phpstan-var bool $can
		$can = apply_filters( 'abilityguard_can_approve', true, $row, $user_id );
		if ( ! $can ) {
			return new WP_Error(
				'abilityguard_approve_denied',
				'Approval was denied by the abilityguard_can_approve filter.'
			);
		}

		if ( 'pending' !== $row['status'] ) {
			return new WP_Error(
				'abilityguard_already_decided',
				sprintf( 'Approval %d has already been decided (status: %s).', $approval_id, $row['status'] )
			);
		}

		$active_stage = $this->repo->find_active_stage( $approval_id );
		if ( null === $active_stage ) {
			return new WP_Error( 'abilityguard_no_active_stage', 'Approval has no waiting stage.' );
		}

		$required_cap = (string) $active_stage['required_cap'];
		if ( ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error(
				'abilityguard_approve_capability_missing',
				sprintf( 'User %d does not have the %s capability.', $user_id, $required_cap )
			);
		}

		$claimed = $this->claim_stage( $approval_id, (int) $active_stage['stage_index'], $user_id, 'rejected' );
		if ( ! $claimed ) {
			return new WP_Error(
				'abilityguard_stage_already_decided',
				'Another approver already decided this stage.'
			);
		}

		// Sequential semantics: any reject kills the whole chain. Cancel
		// every remaining pending stage so the chain can never be
		// resurrected by an old advance() call.
		global $wpdb;
		$stage_table = Installer::table( 'approval_stages' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Installer::table(), not user input.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$stage_table} SET status = %s WHERE approval_id = %d AND status = %s",
				'cancelled',
				$approval_id,
				'pending'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$now            = current_time( 'mysql', true );
		$approval_table = Installer::table( 'approvals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$approval_table,
			array(
				'status'     => 'rejected',
				'decided_by' => $user_id,
				'decided_at' => $now,
			),
			array( 'id' => $approval_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->logs->update_status( (int) $row['log_id'], 'rejected' );

		return true;
	}
}
