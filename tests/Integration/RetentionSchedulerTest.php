<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Installer;
use AbilityGuard\Retention\Scheduler;
use WP_UnitTestCase;

/**
 * Integration tests for the Scheduler against real WP-Cron internals.
 */
final class RetentionSchedulerTest extends WP_UnitTestCase {

	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		$this->scheduler = new Scheduler();

		// Start clean.
		wp_clear_scheduled_hook( Scheduler::HOOK );
	}

	protected function tearDown(): void {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		parent::tearDown();
	}

	public function test_schedule_registers_next_run(): void {
		$this->scheduler->schedule();

		$timestamp = wp_next_scheduled( Scheduler::HOOK );

		$this->assertNotFalse( $timestamp );
		$this->assertGreaterThan( 0, $timestamp );
	}

	public function test_unschedule_removes_event(): void {
		$this->scheduler->schedule();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );

		$this->scheduler->unschedule();

		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_schedule_is_idempotent(): void {
		$this->scheduler->schedule();
		$first = wp_next_scheduled( Scheduler::HOOK );

		$this->scheduler->schedule();
		$second = wp_next_scheduled( Scheduler::HOOK );

		// Same timestamp - no duplicate events.
		$this->assertSame( $first, $second );
	}
}
