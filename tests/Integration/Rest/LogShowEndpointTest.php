<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration\Rest;

use AbilityGuard\Installer;
use WP_UnitTestCase;

/**
 * Integration tests for GET /abilityguard/v1/log/<id>.
 */
final class LogShowEndpointTest extends WP_UnitTestCase {

	use RestHelpers;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_rest_helpers();
	}

	private function route( int $id ): string {
		return '/abilityguard/v1/log/' . $id;
	}

	public function test_admin_valid_id_returns_200_with_log_and_snapshot_keys(): void {
		$inv_id  = wp_generate_uuid4();
		$log_id  = $this->seed_log_row(
			array(
				'invocation_id' => $inv_id,
				'snapshot_id'   => null,
			)
		);
		$snap_id = $this->seed_snapshot_row( $inv_id );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Installer::table( 'log' ),
			array( 'snapshot_id' => $snap_id ),
			array( 'id' => $log_id ),
			array( '%d' ),
			array( '%d' )
		);

		$response = $this->as_admin( fn() => $this->dispatch( 'GET', $this->route( $log_id ) ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'log', $data );
		$this->assertArrayHasKey( 'snapshot', $data );
		$this->assertIsArray( $data['log'] );
		$this->assertSame( (string) $log_id, (string) $data['log']['id'] );
	}

	public function test_valid_id_with_no_snapshot_returns_snapshot_null(): void {
		$log_id = $this->seed_log_row();

		$response = $this->as_admin( fn() => $this->dispatch( 'GET', $this->route( $log_id ) ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'snapshot', $data );
		$this->assertNull( $data['snapshot'] );
	}

	public function test_bogus_id_returns_404_with_not_found_error_code(): void {
		$response = $this->as_admin( fn() => $this->dispatch( 'GET', $this->route( 4242 ) ) );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'abilityguard_not_found', $data['code'] );
	}

	public function test_non_admin_receives_403(): void {
		$log_id = $this->seed_log_row();
		wp_set_current_user( $this->subscriber_user_id );
		$response = $this->dispatch( 'GET', $this->route( $log_id ) );
		$this->assertSame( 403, $response->get_status() );
	}
}
