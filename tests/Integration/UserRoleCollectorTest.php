<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Snapshot\Collector\UserRoleCollector;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Integration tests for UserRoleCollector against real $wpdb.
 */
final class UserRoleCollectorTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	public function test_collect_and_restore_subscriber_role(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$collector = new UserRoleCollector();
		$spec      = array( $user_id );

		// Snapshot original state.
		$captured = $collector->collect( $spec );

		$this->assertArrayHasKey( $user_id, $captured );
		$this->assertContains( 'subscriber', $captured[ $user_id ]['roles'] );

		// Mutate: promote to editor and add a custom cap.
		$user = new \WP_User( $user_id );
		$user->set_role( 'editor' );
		$user->add_cap( 'foo_cap', true );

		$mutated = new \WP_User( $user_id );
		$this->assertContains( 'editor', $mutated->roles );
		$this->assertTrue( $mutated->has_cap( 'foo_cap' ) );

		// Restore.
		$collector->restore( $captured );

		$restored = new \WP_User( $user_id );
		$this->assertContains( 'subscriber', $restored->roles );
		$this->assertNotContains( 'editor', $restored->roles );
		$this->assertFalse( $restored->has_cap( 'foo_cap' ) );
	}

	public function test_collect_skips_nonexistent_user(): void {
		$collector = new UserRoleCollector();
		$captured  = $collector->collect( array( 999999 ) );

		$this->assertArrayNotHasKey( 999999, $captured );
	}

	public function test_restore_skips_nonexistent_user(): void {
		$collector = new UserRoleCollector();

		// Should not throw.
		$collector->restore(
			array(
				999999 => array(
					'roles' => array( 'subscriber' ),
					'caps'  => array(),
				),
			)
		);

		$this->assertTrue( true );
	}

	public function test_defaults_wiring_persists_user_role_surface(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$service = new SnapshotService( new SnapshotStore() );
		$result  = $service->capture(
			'inv-role-wire-1',
			array(
				'snapshot' => array(
					'user_role' => array( $user_id ),
				),
			),
			null
		);

		$this->assertNotNull( $result['snapshot_id'] );
		$this->assertArrayHasKey( 'user_role', $result['surfaces'] );
	}
}
