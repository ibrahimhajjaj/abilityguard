<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Rest;

use AbilityGuard\Installer;
use WP_UnitTestCase;

/**
 * Integration tests for GET /abilityguard/v1/stats.
 */
final class StatsEndpointTest extends WP_UnitTestCase {

	use RestHelpers;

	private const ROUTE = '/abilityguard/v1/stats';

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_rest_helpers();
	}

	public function test_anonymous_user_receives_401(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', self::ROUTE );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_subscriber_receives_403(): void {
		wp_set_current_user( $this->subscriber_user_id );
		$response = $this->dispatch( 'GET', self::ROUTE );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_empty_log_returns_zeroed_shape(): void {
		$response = $this->as_admin( fn() => $this->dispatch( 'GET', self::ROUTE ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'counts', $data );
		$this->assertArrayHasKey( 'timings_ms', $data );
		$this->assertArrayHasKey( 'top_abilities', $data );

		foreach ( array( 'pending', 'approved', 'rejected', 'ok', 'error', 'rolled_back' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['counts'] );
			$this->assertSame( 0, $data['counts'][ $key ] );
		}

		$this->assertArrayHasKey( 'p50', $data['timings_ms'] );
		$this->assertArrayHasKey( 'p95', $data['timings_ms'] );
		$this->assertNull( $data['timings_ms']['p50'] );
		$this->assertNull( $data['timings_ms']['p95'] );

		$this->assertSame( array(), $data['top_abilities'] );
	}

	public function test_admin_aggregates_counts_and_timings(): void {
		// 3 ok, 1 error, 1 rolled_back, 1 pending.
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/a',
				'status'       => 'ok',
				'duration_ms'  => 10,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/a',
				'status'       => 'ok',
				'duration_ms'  => 20,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/a',
				'status'       => 'ok',
				'duration_ms'  => 30,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/b',
				'status'       => 'error',
				'duration_ms'  => 100,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/c',
				'status'       => 'rolled_back',
				'duration_ms'  => 5,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'foo/d',
				'status'       => 'pending',
				'duration_ms'  => 0,
			)
		);

		// Seed an approved approval row to verify counts['approved'] sources from approvals table.
		global $wpdb;
		$approvals = Installer::table( 'approvals' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$approvals,
			array(
				'log_id'       => 0,
				'ability_name' => 'foo/a',
				'status'       => 'approved',
				'requested_by' => 0,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		$response = $this->as_admin( fn() => $this->dispatch( 'GET', self::ROUTE ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 3, $data['counts']['ok'] );
		$this->assertSame( 1, $data['counts']['error'] );
		$this->assertSame( 1, $data['counts']['rolled_back'] );
		$this->assertSame( 1, $data['counts']['pending'] );
		$this->assertSame( 0, $data['counts']['rejected'] );
		$this->assertSame( 1, $data['counts']['approved'] );

		// p50/p95 are computed over finished rows only (5 rows: 5,10,20,30,100).
		$this->assertNotNull( $data['timings_ms']['p50'] );
		$this->assertNotNull( $data['timings_ms']['p95'] );
		$this->assertGreaterThanOrEqual( 10, $data['timings_ms']['p50'] );
		$this->assertGreaterThanOrEqual( $data['timings_ms']['p50'], $data['timings_ms']['p95'] );

		// Top abilities: foo/a leads with 3.
		$this->assertNotEmpty( $data['top_abilities'] );
		$this->assertLessThanOrEqual( 10, count( $data['top_abilities'] ) );
		$this->assertSame( 'foo/a', $data['top_abilities'][0]['name'] );
		$this->assertSame( 3, $data['top_abilities'][0]['count'] );
	}
}
