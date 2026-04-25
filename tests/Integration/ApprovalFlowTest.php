<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * End-to-end approval flow through real services + real $wpdb.
 *
 * Skipped if the abilities-api plugin isn't loaded.
 */
final class ApprovalFlowTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		\AbilityGuard\Approval\CapabilityManager::grant_to_administrators();
		$this->ensure_test_category();
	}

	/**
	 * Register the ability category used by these tests.
	 */
	private function ensure_test_category(): void {
		if ( wp_has_ability_category( 'abilityguard-approval-tests' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'abilityguard-approval-tests',
				array(
					'label'       => 'AbilityGuard approval tests',
					'description' => 'Category for AbilityGuard approval flow tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	/**
	 * Register an ability via the registry directly, bypassing the doing_action guard.
	 *
	 * @param string   $name  Ability name.
	 * @param callable $build Returns the args array.
	 *
	 * @return \WP_Ability
	 */
	private function register_via_init( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail(
				"register() returned null for {$name}. Has category? "
				. ( wp_has_ability_category( (string) ( $args['category'] ?? '' ) ) ? 'yes' : 'no' )
				. '; already registered? '
				. ( $registry->is_registered( $name ) ? 'yes' : 'no' )
			);
		}
		return $result;
	}

	/**
	 * Executing a requires_approval ability blocks execution and returns WP_Error.
	 */
	public function test_execute_with_requires_approval_blocks_and_logs_pending(): void {
		$post_id      = self::factory()->post->create();
		$ability_name = 'abilityguard-approval-tests/destructive-action';

		update_post_meta( $post_id, '_ag_state', 'original' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Destructive action requiring approval',
				'description'         => 'Mutates state but requires approval first.',
				'category'            => 'abilityguard-approval-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_state', 'mutated' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive'       => true,
					'requires_approval' => true,
					'snapshot'          => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_ag_state' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		// Callback must NOT have run.
		$this->assertSame( 'original', get_post_meta( $post_id, '_ag_state', true ), 'State must be unchanged after blocked execution.' );

		// Must return WP_Error with correct code.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_pending_approval', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 202, $data['status'] );
		$this->assertGreaterThan( 0, $data['approval_id'] );

		// Log row must be pending.
		$logs = new LogRepository();
		$rows = $logs->list( array( 'ability_name' => $ability_name ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );

		// Approval row must exist.
		$approvals = new ApprovalRepository();
		$approval  = $approvals->find( (int) $data['approval_id'] );
		$this->assertNotNull( $approval );
		$this->assertSame( 'pending', $approval['status'] );
		$this->assertSame( $ability_name, $approval['ability_name'] );
	}

	/**
	 * Approving a pending request executes the callback, flips log to ok, approval to approved.
	 */
	public function test_approve_executes_callback_and_marks_approved(): void {
		$post_id      = self::factory()->post->create();
		$ability_name = 'abilityguard-approval-tests/approve-me';

		update_post_meta( $post_id, '_ag_state', 'before' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Approve me',
				'description'         => 'Mutates state on approve.',
				'category'            => 'abilityguard-approval-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_state', 'after' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive'       => true,
					'requires_approval' => true,
					'snapshot'          => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_ag_state' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$approval_id = (int) $result->get_error_data()['approval_id'];
		$log_id      = (int) $result->get_error_data()['log_id'];

		$service        = new ApprovalService();
		$approve_result = $service->approve( $approval_id, 1 );

		$this->assertTrue( $approve_result, 'approve() should return true on success.' );

		// Callback must have run.
		$this->assertSame( 'after', get_post_meta( $post_id, '_ag_state', true ), 'State must be mutated after approval.' );

		// Log row must now be ok.
		$logs = new LogRepository();
		$row  = $logs->find( $log_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'ok', $row['status'] );

		// Approval row must be approved with decided_by + decided_at.
		$approvals = new ApprovalRepository();
		$approval  = $approvals->find( $approval_id );
		$this->assertNotNull( $approval );
		$this->assertSame( 'approved', $approval['status'] );
		$this->assertSame( '1', (string) $approval['decided_by'] );
		$this->assertNotNull( $approval['decided_at'] );
	}

	/**
	 * Rejecting a pending request leaves state unchanged, flips both rows to rejected.
	 */
	public function test_reject_leaves_state_unchanged_and_marks_rejected(): void {
		$post_id      = self::factory()->post->create();
		$ability_name = 'abilityguard-approval-tests/reject-me';

		update_post_meta( $post_id, '_ag_state', 'stable' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Reject me',
				'description'         => 'Would mutate state but gets rejected.',
				'category'            => 'abilityguard-approval-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_state', 'should-not-happen' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive'       => true,
					'requires_approval' => true,
					'snapshot'          => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_ag_state' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$approval_id = (int) $result->get_error_data()['approval_id'];
		$log_id      = (int) $result->get_error_data()['log_id'];

		// User 2 needs the approval capability to reject.
		$approver_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$service       = new ApprovalService();
		$reject_result = $service->reject( $approval_id, $approver_id );

		$this->assertTrue( $reject_result, 'reject() should return true on success.' );

		// State must be unchanged.
		$this->assertSame( 'stable', get_post_meta( $post_id, '_ag_state', true ), 'State must be unchanged after rejection.' );

		// Log row must be rejected.
		$logs = new LogRepository();
		$row  = $logs->find( $log_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'rejected', $row['status'] );

		// Approval row must be rejected.
		$approvals = new ApprovalRepository();
		$approval  = $approvals->find( $approval_id );
		$this->assertNotNull( $approval );
		$this->assertSame( 'rejected', $approval['status'] );
		$this->assertSame( '2', (string) $approval['decided_by'] );
		$this->assertNotNull( $approval['decided_at'] );
	}

	/**
	 * Approving an already-decided approval returns WP_Error('abilityguard_already_decided').
	 */
	public function test_double_approve_returns_already_decided_error(): void {
		$post_id      = self::factory()->post->create();
		$ability_name = 'abilityguard-approval-tests/double-approve';

		update_post_meta( $post_id, '_ag_state', 'initial' );

		$this->register_via_init(
			$ability_name,
			static fn() => array(
				'label'               => 'Double approve',
				'description'         => 'Tests double-approve guard.',
				'category'            => 'abilityguard-approval-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					update_post_meta( (int) $input['post_id'], '_ag_state', 'done' );
					return array( 'done' => true );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'safety'              => array(
					'destructive'       => true,
					'requires_approval' => true,
					'snapshot'          => static fn( $input ) => array(
						'post_meta' => array( (int) $input['post_id'] => array( '_ag_state' ) ),
					),
				),
			)
		);

		$ability = wp_get_ability( $ability_name );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$approval_id = (int) $result->get_error_data()['approval_id'];

		$service = new ApprovalService();
		$this->assertTrue( $service->approve( $approval_id, 1 ) );

		$second = $service->approve( $approval_id, 1 );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'abilityguard_already_decided', $second->get_error_code() );
	}
}
