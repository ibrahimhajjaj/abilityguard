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
	 * @param string $ability_name  Registered ability name.
	 * @param mixed  $input         Original input passed to execute_callback.
	 * @param string $invocation_id UUID for this invocation.
	 * @param int    $log_id        Primary key of the log row created for this invocation.
	 *
	 * @return int Approval row id (0 on failure).
	 */
	public function request( string $ability_name, mixed $input, string $invocation_id, int $log_id ): int {
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
		if ( ! user_can( $user_id, CapabilityManager::CAP ) ) {
			return new WP_Error(
				'abilityguard_approve_capability_missing',
				sprintf( 'User %d does not have the %s capability.', $user_id, CapabilityManager::CAP )
			);
		}

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
		if ( ! user_can( $user_id, CapabilityManager::CAP ) ) {
			return new WP_Error(
				'abilityguard_approve_capability_missing',
				sprintf( 'User %d does not have the %s capability.', $user_id, CapabilityManager::CAP )
			);
		}

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

		$now   = current_time( 'mysql', true );
		$table = Installer::table( 'approvals' );
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
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
