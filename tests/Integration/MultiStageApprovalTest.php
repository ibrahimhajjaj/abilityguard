<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Approval\ApprovalRepository;
use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Approval\CapabilityManager;
use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use WP_UnitTestCase;

/**
 * Tests for the v1.1 multi-stage approval state machine.
 *
 * The user explicitly chose: zero users → no backward-compat path. Every
 * approval has at least one stage row. Single-stage approvals walk the
 * same code path as multi-stage.
 */
final class MultiStageApprovalTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		CapabilityManager::grant_to_administrators();

		// A custom cap used by the multi-stage tests so a 'reviewer' user
		// can be granted it without inheriting full admin caps.
		add_action(
			'init',
			static function () {
				$role = get_role( 'editor' );
				if ( $role && ! $role->has_cap( 'manage_abilityguard_approvals' ) ) {
					$role->add_cap( 'manage_abilityguard_approvals' );
				}
			},
			1
		);
		do_action( 'init' );
	}

	private function seed_log_row( string $ability ): int {
		$logger = new AuditLogger();
		return $logger->log(
			array(
				'invocation_id' => 'mstage-' . wp_generate_uuid4(),
				'ability_name'  => $ability,
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'status'        => 'pending',
				'destructive'   => 1,
				'duration_ms'   => 0,
			)
		);
	}

	public function test_single_stage_approval_writes_one_stage_row(): void {
		$log_id = $this->seed_log_row( 'mstage/single' );

		$service = new ApprovalService();
		$id      = $service->request( 'mstage/single', null, 'inv-single', $log_id );

		$repo   = new ApprovalRepository();
		$stages = $repo->find_stages( $id );
		$this->assertCount( 1, $stages, 'no-stages spec defaults to a single stage' );
		$this->assertSame( 'waiting', $stages[0]['status'] );
		$this->assertSame( CapabilityManager::CAP, $stages[0]['required_cap'] );
	}

	public function test_three_stage_round_trip_advances_and_finalises(): void {
		$log_id  = $this->seed_log_row( 'mstage/three' );
		$service = new ApprovalService();

		$cap_a = 'manage_abilityguard_approvals';
		$cap_b = 'manage_abilityguard_approvals';
		$cap_c = 'manage_abilityguard_approvals';

		$id = $service->request(
			'mstage/three',
			new \stdClass(),
			'inv-three',
			$log_id,
			array(
				array( 'cap' => $cap_a ),
				array( 'cap' => $cap_b ),
				array( 'cap' => $cap_c ),
			)
		);

		$repo   = new ApprovalRepository();
		$stages = $repo->find_stages( $id );
		$this->assertCount( 3, $stages );
		$this->assertSame( 'waiting', $stages[0]['status'] );
		$this->assertSame( 'pending', $stages[1]['status'] );
		$this->assertSame( 'pending', $stages[2]['status'] );

		// Three different approvers, none of whom is the requester (user 0).
		$u1 = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$u2 = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$u3 = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Mock wp_get_ability so the final-stage execute path doesn't blow up.
		$this->register_no_op_ability( 'mstage/three' );

		$advanced = array();
		add_action(
			'abilityguard_approval_advanced',
			static function ( int $aid, int $idx ) use ( &$advanced ) {
				$advanced[] = $idx;
			},
			10,
			2
		);

		// Stage 0 → approved → advance to stage 1.
		$ok = $service->approve( $id, $u1 );
		$this->assertTrue( true === $ok );
		$this->assertSame( array( 1 ), $advanced );

		// Stage 1 → approved → advance to stage 2.
		$ok = $service->approve( $id, $u2 );
		$this->assertTrue( true === $ok );
		$this->assertSame( array( 1, 2 ), $advanced );

		// Stage 2 → approved → finalise (no advance fires; ability runs).
		$ok = $service->approve( $id, $u3 );
		if ( is_wp_error( $ok ) ) {
			$this->fail( 'final approve returned WP_Error: ' . $ok->get_error_code() . ' - ' . $ok->get_error_message() );
		}
		$this->assertTrue( true === $ok );
		$this->assertSame( array( 1, 2 ), $advanced );

		$row = $repo->find( $id );
		$this->assertSame( 'approved', $row['status'], 'final stage approval flips overall status' );
	}

	public function test_rejection_kills_the_chain_and_cancels_pending_stages(): void {
		$log_id  = $this->seed_log_row( 'mstage/reject' );
		$service = new ApprovalService();
		$id      = $service->request(
			'mstage/reject',
			null,
			'inv-reject',
			$log_id,
			array(
				array( 'cap' => 'manage_abilityguard_approvals' ),
				array( 'cap' => 'manage_abilityguard_approvals' ),
				array( 'cap' => 'manage_abilityguard_approvals' ),
			)
		);

		$u  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ok = $service->reject( $id, $u );
		$this->assertTrue( true === $ok );

		$repo   = new ApprovalRepository();
		$stages = $repo->find_stages( $id );
		$this->assertSame( 'rejected', $stages[0]['status'] );
		$this->assertSame( 'cancelled', $stages[1]['status'], 'pending stages must be cancelled by reject' );
		$this->assertSame( 'cancelled', $stages[2]['status'] );

		$row = $repo->find( $id );
		$this->assertSame( 'rejected', $row['status'] );
	}

	public function test_atomic_stage_claim_defeats_concurrent_decisions(): void {
		// PHPUnit is single-threaded so we can't fire two PHP processes at the
		// same instant. We simulate the race by invoking the private
		// claim_stage() helper twice via reflection on the same stage index -
		// the underlying UPDATE-with-WHERE-status='waiting' is what guards the
		// real concurrent case, and it's testable here directly.
		$log_id  = $this->seed_log_row( 'mstage/race' );
		$service = new ApprovalService();
		$id      = $service->request( 'mstage/race', new \stdClass(), 'inv-race', $log_id );

		$repo  = new ApprovalRepository();
		$stage = $repo->find_active_stage( $id );
		$this->assertSame( 0, (int) $stage['stage_index'] );

		$u1 = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$u2 = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ref    = new \ReflectionClass( ApprovalService::class );
		$method = $ref->getMethod( 'claim_stage' );
		$method->setAccessible( true );

		$first  = $method->invoke( $service, $id, 0, $u1, 'approved' );
		$second = $method->invoke( $service, $id, 0, $u2, 'approved' );

		$this->assertTrue( $first, 'first claim must win' );
		$this->assertFalse( $second, 'second claim on already-decided stage must fail atomically' );
	}

	public function test_self_approval_is_blocked_at_first_stage(): void {
		$requester = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $requester );

		$log_id  = $this->seed_log_row( 'mstage/self' );
		$service = new ApprovalService();
		$id      = $service->request( 'mstage/self', null, 'inv-self', $log_id );

		$err = $service->approve( $id, $requester );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'abilityguard_approve_self_forbidden', $err->get_error_code() );
	}

	public function test_capability_check_uses_the_active_stages_cap(): void {
		$log_id  = $this->seed_log_row( 'mstage/cap' );
		$service = new ApprovalService();
		$id      = $service->request(
			'mstage/cap',
			null,
			'inv-cap',
			$log_id,
			array( array( 'cap' => 'manage_options' ) ) // Not the AG approval cap.
		);

		// Subscriber lacks manage_options → rejected.
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$err = $service->approve( $id, $sub );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'abilityguard_approve_capability_missing', $err->get_error_code() );
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
