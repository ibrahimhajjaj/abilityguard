<?php
/**
 * Unit tests for AbilityGuard\Support\PayloadCap.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Unit\Support;

use AbilityGuard\Support\PayloadCap;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class PayloadCapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wp_json_encode' )->alias(
			static fn( $v, int $f = 0 ) => json_encode( $v, $f )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cap_json_under_cap_passes_through(): void {
		$json   = '{"hello":"world"}';
		$result = PayloadCap::cap_json( 'args', $json, 1000 );

		$this->assertSame( $json, $result['json'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( strlen( $json ), $result['original_bytes'] );
	}

	public function test_cap_json_exactly_at_cap_passes_through(): void {
		$json   = '{"hello":"world"}';
		$limit  = strlen( $json );
		$result = PayloadCap::cap_json( 'args', $json, $limit );

		$this->assertSame( $json, $result['json'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_cap_json_over_cap_returns_truncation_marker(): void {
		$json   = '{"hello":"world"}';
		$bytes  = strlen( $json );
		$limit  = $bytes - 1;
		$result = PayloadCap::cap_json( 'result', $json, $limit );

		$this->assertTrue( $result['truncated'] );
		$this->assertSame( $bytes, $result['original_bytes'] );

		$decoded = json_decode( (string) $result['json'], true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded[ PayloadCap::MARKER_KEY ] );
		$this->assertSame( $bytes, $decoded['original_bytes'] );
		$this->assertSame( 'result', $decoded['kind'] );
	}

	public function test_cap_json_limit_zero_means_no_cap(): void {
		$json   = str_repeat( 'x', 100000 );
		$result = PayloadCap::cap_json( 'args', '"' . $json . '"', 0 );

		$this->assertFalse( $result['truncated'] );
	}

	public function test_cap_json_null_passes_through(): void {
		$result = PayloadCap::cap_json( 'args', null, 100 );

		$this->assertNull( $result['json'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 0, $result['original_bytes'] );
	}

	public function test_cap_surfaces_only_truncates_over_budget_surface(): void {
		// Build two surfaces: one small, one large.
		$small = array( 'key' => 'value' );
		$large = array( 'data' => str_repeat( 'a', 2000 ) );

		$surfaces = array(
			'options'   => $small,
			'post_meta' => $large,
		);

		// Set limit so small passes and large does not.
		$small_bytes = strlen( json_encode( $small ) );
		$limit       = $small_bytes + 100; // comfortably above small, below large.

		$result = PayloadCap::cap_surfaces( $surfaces, $limit );

		// Small surface must be intact.
		$this->assertSame( $small, $result['surfaces']['options'] );

		// Large surface must have the truncation marker.
		$this->assertArrayHasKey( 'post_meta', $result['surfaces'] );
		$marker = $result['surfaces']['post_meta'];
		$this->assertTrue( $marker[ PayloadCap::MARKER_KEY ] );
		$this->assertSame( 'post_meta', $marker['surface'] );
		$this->assertGreaterThan( $limit, $marker['original_bytes'] );

		// Truncated map must record the large surface only.
		$this->assertArrayHasKey( 'post_meta', $result['truncated'] );
		$this->assertArrayNotHasKey( 'options', $result['truncated'] );
	}

	public function test_cap_surfaces_limit_zero_means_no_cap(): void {
		$surfaces = array(
			'options' => array( 'data' => str_repeat( 'z', 5000 ) ),
		);

		$result = PayloadCap::cap_surfaces( $surfaces, 0 );

		$this->assertSame( $surfaces, $result['surfaces'] );
		$this->assertEmpty( $result['truncated'] );
	}

	public function test_cap_surfaces_all_under_cap_returns_intact(): void {
		$surfaces = array(
			'options'   => array( 'foo' => 'bar' ),
			'post_meta' => array( 'baz' => 'qux' ),
		);

		$result = PayloadCap::cap_surfaces( $surfaces, 1048576 );

		$this->assertSame( $surfaces, $result['surfaces'] );
		$this->assertEmpty( $result['truncated'] );
	}

	public function test_cap_surfaces_original_bytes_recorded_for_truncated(): void {
		$data     = array( 'x' => str_repeat( 'y', 500 ) );
		$json     = json_encode( $data );
		$surfaces = array( 'options' => $data );

		$result = PayloadCap::cap_surfaces( $surfaces, 10 );

		$this->assertSame( strlen( $json ), $result['truncated']['options'] );
	}
}
