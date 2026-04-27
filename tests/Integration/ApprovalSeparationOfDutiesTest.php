<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Approval\CapabilityManager;
use AbilityGuard\Installer;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * Integration tests for v0.4 approval separation of duties.
 *
 * Covers:
 *  - Capability guard (approve + reject).
 *  - Self-approval guard (approve + reject).
 *  - Successful decision by a separate user with the cap.
 *  - abilityguard_can_approve filter veto.
 *  - Activation grants cap to administrator role.
 *  - Uninstall removes cap from all roles.
 */
final class ApprovalSeparationOfDutiesTest extends WP_UnitTestCase {

	private const CATEGORY = 'abilityguard-sod-tests';

	private int $requester_id = 0;
	private int $approver_id  = 0;
	private int $no_cap_id    = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		Installer::install();
		$this->ensure_category();

		// Create three users.
		$this->requester_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->approver_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->no_cap_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Approver and requester both get the cap. Subscriber does not.
		$approver_user  = get_userdata( $this->approver_id );
		$requester_user = get_userdata( $this->requester_id );
		if ( $approver_user ) {
			$approver_user->add_cap( CapabilityManager::CAP );
		}
		if ( $requester_user ) {
			$requester_user->add_cap( CapabilityManager::CAP );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'abilityguard_can_approve' );
		parent::tearDown();
	}

	private function ensure_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				ApprovalSeparationOfDutiesTest::CATEGORY,
				array(
					'label'       => 'AbilityGuard SOD tests',
					'description' => 'Category for separation-of-duties tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	/**
	 * Register an ability via the registry directly.
	 *
	 * @param string   $name  Unique ability name.
	 * @param callable $build Returns the args array.
	 */
	private function register_ability( string $name, callable $build ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$args     = $build( $registry );
		$result   = $registry->register( $name, $args );
		if ( null === $result ) {
			$this->fail( "register() returned null for ability: {$name}" );
		}
		return $result;
	}

	/**
	 * Queue a pending approval as $requester_id and return the approval id.
	 *
	 * @param string $ability_name Unique ability name slug for this test.
	 */
	private function queue_pending( string $ability_name ): int {
		wp_set_current_user( $this->requester_id );
		$ability = $this->register_ability(
			$ability_name,
			fn() => array(
				'label'               => $ability_name,
				'description'         => 'SOD test ability.',
				'category'            => self::CATEGORY,
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => array( 'ok' => true ),
				'meta'                => array(
					'annotations' => array( 'destructive' => true ),
				),
				'safety'              => array(
					'requires_approval' => true,
				),
			)
		);

		$result = $ability->execute( null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		return (int) $data['approval_id'];
	}

	public function test_approve_missing_capability_returns_error(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/cap-guard-approve' );
		$service     = new ApprovalService();

		$result = $service->approve( $approval_id, $this->no_cap_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_capability_missing', $result->get_error_code() );
	}

	public function test_reject_missing_capability_returns_error(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/cap-guard-reject' );
		$service     = new ApprovalService();

		$result = $service->reject( $approval_id, $this->no_cap_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_capability_missing', $result->get_error_code() );
	}

	public function test_approve_own_request_returns_self_forbidden(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/self-approve' );
		$service     = new ApprovalService();

		$result = $service->approve( $approval_id, $this->requester_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_self_forbidden', $result->get_error_code() );
	}

	public function test_reject_own_request_returns_self_forbidden(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/self-reject' );
		$service     = new ApprovalService();

		$result = $service->reject( $approval_id, $this->requester_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_self_forbidden', $result->get_error_code() );
	}

	public function test_approve_by_different_user_with_cap_succeeds(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/success-approve' );
		$service     = new ApprovalService();

		$result = $service->approve( $approval_id, $this->approver_id );

		$this->assertTrue( $result );

		$repo     = new ApprovalRepository();
		$approval = $repo->find( $approval_id );
		$this->assertNotNull( $approval );
		$this->assertSame( 'approved', $approval['status'] );
		$this->assertSame( (string) $this->approver_id, (string) $approval['decided_by'] );
	}

	public function test_reject_by_different_user_with_cap_succeeds(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/success-reject' );
		$service     = new ApprovalService();

		$result = $service->reject( $approval_id, $this->approver_id );

		$this->assertTrue( $result );

		$repo     = new ApprovalRepository();
		$approval = $repo->find( $approval_id );
		$this->assertNotNull( $approval );
		$this->assertSame( 'rejected', $approval['status'] );
		$this->assertSame( (string) $this->approver_id, (string) $approval['decided_by'] );
	}

	public function test_filter_veto_returns_approve_denied(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/filter-veto' );
		$service     = new ApprovalService();

		add_filter( 'abilityguard_can_approve', '__return_false' );

		$result = $service->approve( $approval_id, $this->approver_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_denied', $result->get_error_code() );
	}

	public function test_filter_veto_on_reject_returns_approve_denied(): void {
		$approval_id = $this->queue_pending( self::CATEGORY . '/filter-veto-reject' );
		$service     = new ApprovalService();

		add_filter( 'abilityguard_can_approve', '__return_false' );

		$result = $service->reject( $approval_id, $this->approver_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_approve_denied', $result->get_error_code() );
	}

	public function test_grant_to_administrators_adds_cap_to_administrator_role(): void {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$role->remove_cap( CapabilityManager::CAP );
		$this->assertFalse( $role->has_cap( CapabilityManager::CAP ) );

		CapabilityManager::grant_to_administrators();

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( CapabilityManager::CAP ) );
	}

	public function test_remove_from_all_roles_strips_cap_everywhere(): void {
		CapabilityManager::grant_to_administrators();

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( CapabilityManager::CAP ) );

		CapabilityManager::remove_from_all_roles();

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( CapabilityManager::CAP ) );
	}
}
