<?php
/**
 * Integration tests for payload size caps end-to-end through real services.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\PayloadCap;
use WP_UnitTestCase;

/**
 * End-to-end payload cap tests through real services + real $wpdb.
 *
 * Tests that run through AbilityWrapper are skipped if the abilities-api
 * plugin is not loaded. Snapshot-only tests run regardless.
 */
final class PayloadCapTest extends WP_UnitTestCase {

	use AbilityRegistrationTrait;

	private static int $counter = 0;

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	/**
	 * Ability returns ~200 KB result; default 128 KB cap must truncate it.
	 */
	public function test_large_result_truncated_in_audit_log(): void {
		$this->setExpectedIncorrectUsage( 'AbilityGuard' );
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$large_result = array( 'data' => str_repeat( 'x', 200_000 ) );
		++self::$counter;
		$name = 'abilityguard-tests/payload-large-' . self::$counter;
		$row  = $this->execute_and_get_log_row(
			$name,
			array( 'destructive' => false ),
			static fn() => $large_result
		);

		$result_json = $row['result_json'];
		$this->assertNotNull( $result_json );

		$decoded = json_decode( $result_json, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded[ PayloadCap::MARKER_KEY ], 'result_json must contain truncation marker' );
		$this->assertSame( 'result', $decoded['kind'] );
		$this->assertGreaterThan( 190_000, $decoded['original_bytes'], 'original_bytes should be close to 200K' );
	}

	/**
	 * Ability returns small result; no truncation occurs.
	 */
	public function test_small_result_not_truncated(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		++self::$counter;
		$name = 'abilityguard-tests/payload-small-' . self::$counter;
		$row  = $this->execute_and_get_log_row(
			$name,
			array( 'destructive' => false ),
			static fn() => array( 'ok' => true )
		);

		$result_json = $row['result_json'];
		$this->assertNotNull( $result_json );
		$decoded = json_decode( $result_json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayNotHasKey( PayloadCap::MARKER_KEY, $decoded );
	}

	/**
	 * Setting safety.max_payload_bytes = 0 disables truncation even for 200 KB result.
	 */
	public function test_per_ability_zero_disables_truncation(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$large_result = array( 'data' => str_repeat( 'y', 200_000 ) );
		++self::$counter;
		$name = 'abilityguard-tests/payload-nocap-' . self::$counter;
		$row  = $this->execute_and_get_log_row(
			$name,
			array(
				'destructive'       => false,
				'max_payload_bytes' => 0,
			),
			static fn() => $large_result
		);

		$result_json = $row['result_json'];
		$this->assertNotNull( $result_json );
		$decoded = json_decode( $result_json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayNotHasKey( PayloadCap::MARKER_KEY, $decoded, 'No truncation when max_payload_bytes = 0' );
	}

	/**
	 * Setting safety.max_payload_bytes = 1024 overrides the global 128 KB default.
	 */
	public function test_per_ability_tight_cap_honored(): void {
		$this->setExpectedIncorrectUsage( 'AbilityGuard' );
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		$medium_result = array( 'data' => str_repeat( 'z', 2000 ) );
		++self::$counter;
		$name = 'abilityguard-tests/payload-tight-' . self::$counter;
		$row  = $this->execute_and_get_log_row(
			$name,
			array(
				'destructive'       => false,
				'max_payload_bytes' => 1024,
			),
			static fn() => $medium_result
		);

		$result_json = $row['result_json'];
		$decoded     = json_decode( (string) $result_json, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded[ PayloadCap::MARKER_KEY ], 'Per-ability 1 KB cap should trigger truncation' );
	}

	/**
	 * Snapshot of an options surface > 1 MB is truncated; pre_hash is computed
	 * from the raw un-truncated surfaces so hash integrity is preserved.
	 */
	public function test_large_snapshot_surface_truncated_hash_preserved(): void {
		$this->setExpectedIncorrectUsage( 'AbilityGuard' );
		$large_value = str_repeat( 'a', 1_100_000 );
		$small_value = 'tiny';
		update_option( 'ag_large_option', $large_value );
		update_option( 'ag_small_option', $small_value );

		$service = new SnapshotService( new SnapshotStore() );
		$safety  = array(
			'snapshot' => array(
				'options' => array( 'ag_large_option', 'ag_small_option' ),
			),
		);

		$result = $service->capture( 'inv-cap-snap-1', $safety, null );

		$this->assertNotNull( $result['snapshot_id'] );

		$surfaces = $result['surfaces'];
		$this->assertArrayHasKey( 'options', $surfaces );

		// The combined options surface (1.1 MB + tiny) exceeds the 1 MB cap so
		// the whole surface entry is replaced with the truncation marker.
		$options = $surfaces['options'];
		$this->assertIsArray( $options );
		$this->assertTrue( $options[ PayloadCap::MARKER_KEY ], 'options surface must have truncation marker' );
		$this->assertSame( 'options', $options['surface'] );

		// pre_hash must equal Hash::stable of the raw un-truncated surfaces.
		$raw_surfaces  = array(
			'options' => array(
				'ag_large_option' => $large_value,
				'ag_small_option' => $small_value,
			),
		);
		$expected_hash = Hash::stable( $raw_surfaces );
		$this->assertSame( $expected_hash, $result['pre_hash'], 'pre_hash must reflect un-truncated surfaces' );
	}

	/**
	 * Setting safety.max_payload_bytes = 0 on snapshot config disables per-surface cap.
	 */
	public function test_snapshot_per_ability_zero_disables_cap(): void {
		$large_value = str_repeat( 'b', 1_100_000 );
		update_option( 'ag_large_option2', $large_value );

		$service = new SnapshotService( new SnapshotStore() );
		$safety  = array(
			'max_payload_bytes' => 0,
			'snapshot'          => array(
				'options' => array( 'ag_large_option2' ),
			),
		);

		$result = $service->capture( 'inv-cap-snap-2', $safety, null );

		$surfaces = $result['surfaces'];
		$this->assertArrayHasKey( 'options', $surfaces );

		$this->assertArrayNotHasKey( PayloadCap::MARKER_KEY, $surfaces['options'] );
		$this->assertSame( $large_value, $surfaces['options']['ag_large_option2'] );
	}
}
