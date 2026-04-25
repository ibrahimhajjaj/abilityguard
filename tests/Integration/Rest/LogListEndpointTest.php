<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Rest;

use WP_UnitTestCase;

/**
 * Integration tests for GET /abilityguard/v1/log.
 *
 * Every test goes through the full WordPress REST stack:
 * route resolution → permission_callback → args sanitisation → callback → response shape.
 */
final class LogListEndpointTest extends WP_UnitTestCase {

	use RestHelpers;

	private const ROUTE = '/abilityguard/v1/log';

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_rest_helpers();
	}

	public function test_anonymous_user_receives_401(): void {
		wp_set_current_user( 0 );
		$response = $this->dispatch( 'GET', self::ROUTE );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_non_admin_subscriber_receives_403(): void {
		wp_set_current_user( $this->subscriber_user_id );
		$response = $this->dispatch( 'GET', self::ROUTE );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_empty_log_returns_200_empty_array(): void {
		$response = $this->as_admin( fn() => $this->dispatch( 'GET', self::ROUTE ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 0, $data );
	}

	public function test_admin_three_rows_seeded_returns_200_with_three_entries(): void {
		$this->seed_log_row(
			array(
				'ability_name' => 'test/alpha',
				'status'       => 'ok',
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/beta',
				'status'       => 'error',
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/gamma',
				'status'       => 'ok',
			)
		);

		$response = $this->as_admin( fn() => $this->dispatch( 'GET', self::ROUTE ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 3, $data );

		$first = $data[0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'invocation_id', $first );
		$this->assertArrayHasKey( 'ability_name', $first );
		$this->assertArrayHasKey( 'status', $first );
		$this->assertArrayHasKey( 'created_at', $first );
	}

	public function test_status_filter_returns_only_matching_rows(): void {
		$this->seed_log_row(
			array(
				'ability_name' => 'test/ok-1',
				'status'       => 'ok',
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/err-1',
				'status'       => 'error',
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/ok-2',
				'status'       => 'ok',
			)
		);

		$response = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'status' => 'ok' ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
		foreach ( $data as $row ) {
			$this->assertSame( 'ok', $row['status'] );
		}
	}

	public function test_pagination_per_page_and_offset(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->seed_log_row( array( 'ability_name' => "test/page-{$i}" ) );
		}

		$response = $this->as_admin(
			fn() => $this->dispatch(
				'GET',
				self::ROUTE,
				array(
					'per_page' => 2,
					'offset'   => 1,
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
	}

	public function test_ability_name_filter_returns_only_matching_rows(): void {
		$this->seed_log_row( array( 'ability_name' => 'plugin-a/do' ) );
		$this->seed_log_row( array( 'ability_name' => 'plugin-b/do' ) );
		$this->seed_log_row( array( 'ability_name' => 'plugin-a/do' ) );

		$response = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'ability_name' => 'plugin-a/do' ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data );
		foreach ( $data as $row ) {
			$this->assertSame( 'plugin-a/do', $row['ability_name'] );
		}
	}

	public function test_caller_id_filter_returns_only_matching_rows(): void {
		$this->seed_log_row(
			array(
				'ability_name' => 'test/mcp-a',
				'caller_type'  => 'mcp',
				'caller_id'    => 'server-x',
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/mcp-b',
				'caller_type'  => 'mcp',
				'caller_id'    => 'server-y',
			)
		);

		$response = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'caller_id' => 'server-x' ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'server-x', $data[0]['caller_id'] );
	}

	public function test_destructive_filter_partitions_results(): void {
		$this->seed_log_row(
			array(
				'ability_name' => 'test/destr',
				'destructive'  => 1,
			)
		);
		$this->seed_log_row(
			array(
				'ability_name' => 'test/safe',
				'destructive'  => 0,
			)
		);

		$only_destr = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'destructive' => true ) )
		);
		$only_safe  = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'destructive' => false ) )
		);

		$this->assertCount( 1, $only_destr->get_data() );
		$this->assertSame( 'test/destr', $only_destr->get_data()[0]['ability_name'] );
		$this->assertCount( 1, $only_safe->get_data() );
		$this->assertSame( 'test/safe', $only_safe->get_data()[0]['ability_name'] );
	}

	public function test_invalid_per_page_exceeding_max_rejected_by_schema(): void {
		$response = $this->as_admin(
			fn() => $this->dispatch( 'GET', self::ROUTE, array( 'per_page' => 999999 ) )
		);
		$this->assertSame( 400, $response->get_status() );
	}
}
