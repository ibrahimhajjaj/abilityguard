<?php
/**
 * Unit tests for AbilityGuard\Support\Hash.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Unit\Support;

use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Json;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// wp_json_encode falls back to json_encode in the unit env.
		Monkey\Functions\when( 'wp_json_encode' )->alias(
			static fn( $v, int $f = 0 ) => json_encode( $v, $f )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_stable_hash_is_order_independent(): void {
		$a = Hash::stable(
			array(
				'b' => 2,
				'a' => 1,
			)
		);
		$b = Hash::stable(
			array(
				'a' => 1,
				'b' => 2,
			)
		);
		$this->assertSame( $a, $b );
	}

	public function test_stable_hash_is_deterministic_for_nested(): void {
		$value = array(
			'post_meta' => array(
				'z' => 'last',
				'a' => 'first',
			),
			'options'   => array( 'foo', 'bar' ),
		);
		$this->assertSame( Hash::stable( $value ), Hash::stable( $value ) );
	}

	public function test_stable_hash_differs_for_different_values(): void {
		$this->assertNotSame(
			Hash::stable( array( 'x' => 1 ) ),
			Hash::stable( array( 'x' => 2 ) )
		);
	}

	public function test_lists_preserve_order(): void {
		$this->assertNotSame(
			Hash::stable( array( 1, 2, 3 ) ),
			Hash::stable( array( 3, 2, 1 ) )
		);
	}

	public function test_canonical_json_sorts_keys(): void {
		$this->assertSame(
			'{"a":1,"b":2}',
			Json::canonical(
				array(
					'b' => 2,
					'a' => 1,
				)
			)
		);
	}

	public function test_compressed_round_trip(): void {
		$value  = array( 'post_meta' => array( '_price' => '9.99' ) );
		$packed = Json::encode_compressed( $value );
		$this->assertSame( $value, Json::decode_compressed( $packed ) );
	}
}
