<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Approval\CapabilityManager;
use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Installer;
use WP_UnitTestCase;

/**
 * Tests for v1.2 role-based approval routing and separation of duties.
 *
 * Fills the wordpress-mcp gap: that plugin had user_confirmed and a notion
 * of multi-step gating, but Automattic deprecated it. Compliance-driven
 * orgs need both role routing (any-of) and SOD across the chain.
 */
final class ApprovalRoleRoutingTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		CapabilityManager::grant_to_administrators();

		// Editors get the cap so role-routing can target them. The cap
		// check still runs orthogonally to the role gate.
		$role = get_role( 'editor' );
		if ( $role && ! $role->has_cap( CapabilityManager::CAP ) ) {
			$role->add_cap( CapabilityManager::CAP );
		}
	}

	private function seed_log_row( string $ability ): int {
		$logger = new AuditLogger();
		return $logger->log(
			array(
				'invocation_id' => 'role-' . wp_generate_uuid4(),
				'ability_name'  => $ability,
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'status'        => 'pending',
				'destructive'   => 1,
				'duration_ms'   => 0,
			)
		);
	}

	public function test_user_outside_listed_roles_is_rejected(): void {
		$log_id  = $this->seed_log_row( 'role/wrong-role' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/wrong-role',
			new \stdClass(),
			'inv-wrong-role',
			$log_id,
			array(
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor' ),
				),
			)
		);

		// Administrator has the cap but not the editor role.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$err = $service->approve( $id, $admin );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'abilityguard_approve_wrong_role', $err->get_error_code() );
	}

	public function test_user_in_one_of_listed_roles_succeeds(): void {
		$log_id  = $this->seed_log_row( 'role/any-of' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/any-of',
			new \stdClass(),
			'inv-any-of',
			$log_id,
			array(
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor', 'administrator' ),
				),
			)
		);

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->register_no_op_ability( 'role/any-of' );

		$ok = $service->approve( $id, $editor );
		$this->assertTrue( true === $ok );

		$repo   = new ApprovalRepository();
		$stages = $repo->find_stages( $id );
		$this->assertSame( 'editor', (string) $stages[0]['decided_by_role'] );
	}

	public function test_same_user_cannot_decide_two_stages_even_via_reject(): void {
		// Stage 0 approved by user X, then user X tries to reject stage 1.
		$log_id  = $this->seed_log_row( 'role/same-user' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/same-user',
			new \stdClass(),
			'inv-same-user',
			$log_id,
			array(
				array( 'cap' => CapabilityManager::CAP ),
				array( 'cap' => CapabilityManager::CAP ),
			)
		);

		$u = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ok = $service->approve( $id, $u );
		$this->assertTrue( true === $ok );

		// Same user attempting to act on stage 1 - even by rejecting.
		$err = $service->reject( $id, $u );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'abilityguard_sod_same_user', $err->get_error_code() );
	}

	public function test_same_role_blocked_in_multi_role_chain(): void {
		$log_id  = $this->seed_log_row( 'role/same-role' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/same-role',
			new \stdClass(),
			'inv-same-role',
			$log_id,
			array(
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor', 'administrator' ),
				),
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor', 'administrator' ),
				),
			)
		);

		$editor1 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$editor2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		$ok = $service->approve( $id, $editor1 );
		$this->assertTrue( true === $ok );

		$err = $service->approve( $id, $editor2 );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'abilityguard_sod_same_role', $err->get_error_code() );
	}

	public function test_same_role_allowed_in_single_role_chain(): void {
		// approval_roles=['administrator'] at every stage is a legitimate
		// config: two different admins decide. SOD only blocks the same
		// user, not the role, when the chain is single-role.
		$log_id  = $this->seed_log_row( 'role/single-role-chain' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/single-role-chain',
			new \stdClass(),
			'inv-single-role-chain',
			$log_id,
			array(
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'administrator' ),
				),
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'administrator' ),
				),
			)
		);

		$admin1 = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin2 = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->register_no_op_ability( 'role/single-role-chain' );

		$this->assertTrue( true === $service->approve( $id, $admin1 ) );
		$this->assertTrue( true === $service->approve( $id, $admin2 ) );

		$repo = new ApprovalRepository();
		$row  = $repo->find( $id );
		$this->assertSame( 'approved', $row['status'] );
	}

	public function test_multi_role_chain_succeeds_when_different_roles_decide(): void {
		$log_id  = $this->seed_log_row( 'role/cross-role' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/cross-role',
			new \stdClass(),
			'inv-cross-role',
			$log_id,
			array(
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor', 'administrator' ),
				),
				array(
					'cap'            => CapabilityManager::CAP,
					'approval_roles' => array( 'editor', 'administrator' ),
				),
			)
		);

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->register_no_op_ability( 'role/cross-role' );

		$this->assertTrue( true === $service->approve( $id, $editor ) );
		$this->assertTrue( true === $service->approve( $id, $admin ) );

		$repo = new ApprovalRepository();
		$row  = $repo->find( $id );
		$this->assertSame( 'approved', $row['status'] );
	}

	public function test_legacy_null_role_rows_skip_role_sod(): void {
		// Pre-v1.2 stages have NULL decided_by_role. The chain must still
		// be decidable - SOD only enforces the same-user rule for those.
		$log_id  = $this->seed_log_row( 'role/legacy' );
		$service = new ApprovalService();
		$id      = $service->request(
			'role/legacy',
			new \stdClass(),
			'inv-legacy',
			$log_id,
			array(
				array( 'cap' => CapabilityManager::CAP ),
				array( 'cap' => CapabilityManager::CAP ),
			)
		);

		$u1 = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$u2 = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->register_no_op_ability( 'role/legacy' );

		$this->assertTrue( true === $service->approve( $id, $u1 ) );
		$this->assertTrue( true === $service->approve( $id, $u2 ) );
	}

	private function register_no_op_ability( string $name ): void {
		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! wp_has_ability_category( 'abilityguard-tests' ) ) {
			$cb = static function ( $reg ) {
				$reg->register(
					'abilityguard-tests',
					array(
						'label'       => 't',
						'description' => 't',
					)
				);
			};
			add_action( 'wp_abilities_api_categories_init', $cb );
			\WP_Ability_Categories_Registry::get_instance();
			do_action( 'wp_abilities_api_categories_init', \WP_Ability_Categories_Registry::get_instance() );
			remove_action( 'wp_abilities_api_categories_init', $cb );
		}
		$registry->register(
			$name,
			array(
				'label'               => 'noop',
				'description'         => 'noop',
				'category'            => 'abilityguard-tests',
				'input_schema'        => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => array( 'ok' => true ),
			)
		);
	}
}
