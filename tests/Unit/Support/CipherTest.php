<?php
/**
 * Unit tests for AbilityGuard\Support\Cipher.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Unit\Support;

use AbilityGuard\Support\Cipher;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure unit tests - no WordPress, no database.
 * wp_salt() and wp_json_encode() are stubbed via Brain Monkey.
 */
final class CipherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the cached derived key so each test gets a fresh derivation.
		Cipher::reset_key();

		Functions\stubs(
			array(
				'wp_salt'        => 'test-salt-value-for-unit-tests',
				'wp_json_encode' => static function ( mixed $data ): string|false {
					return json_encode( $data );
				},
			)
		);
	}

	protected function tearDown(): void {
		Cipher::reset_key();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_encrypt_returns_envelope_shape(): void {
		$envelope = Cipher::encrypt( 'secret' );

		$this->assertIsArray( $envelope );
		$this->assertTrue( $envelope['_abilityguard_redacted'] );
		$this->assertArrayHasKey( 'ct', $envelope );
		$this->assertArrayHasKey( 'iv', $envelope );
		$this->assertArrayHasKey( 'tag', $envelope );
		$this->assertIsString( $envelope['ct'] );
		$this->assertIsString( $envelope['iv'] );
		$this->assertIsString( $envelope['tag'] );
	}

	public function test_roundtrip_string(): void {
		$envelope = Cipher::encrypt( 'hello world' );
		$this->assertSame( 'hello world', Cipher::decrypt( $envelope ) );
	}

	public function test_roundtrip_array(): void {
		$value    = array(
			'password' => 'secret',
			'user'     => 'alice',
		);
		$envelope = Cipher::encrypt( $value );
		$this->assertSame( $value, Cipher::decrypt( $envelope ) );
	}

	public function test_roundtrip_int(): void {
		$envelope = Cipher::encrypt( 42 );
		$this->assertSame( 42, Cipher::decrypt( $envelope ) );
	}

	public function test_roundtrip_null(): void {
		$envelope = Cipher::encrypt( null );
		$this->assertNull( Cipher::decrypt( $envelope ) );
	}

	public function test_roundtrip_bool_false(): void {
		$envelope = Cipher::encrypt( false );
		$this->assertFalse( Cipher::decrypt( $envelope ) );
	}

	public function test_tampered_ct_throws(): void {
		$envelope       = Cipher::encrypt( 'secret' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$envelope['ct'] = base64_encode( 'GARBAGE' . random_bytes( 16 ) );

		$this->expectException( RuntimeException::class );
		Cipher::decrypt( $envelope );
	}

	public function test_tampered_tag_throws(): void {
		$envelope = Cipher::encrypt( 'secret' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$envelope['tag'] = base64_encode( random_bytes( 16 ) );

		$this->expectException( RuntimeException::class );
		Cipher::decrypt( $envelope );
	}

	public function test_tampered_iv_throws(): void {
		$envelope = Cipher::encrypt( 'secret' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$envelope['iv'] = base64_encode( random_bytes( 12 ) );

		$this->expectException( RuntimeException::class );
		Cipher::decrypt( $envelope );
	}

	public function test_is_envelope_true_for_valid_envelope(): void {
		$envelope = Cipher::encrypt( 'x' );
		$this->assertTrue( Cipher::is_envelope( $envelope ) );
	}

	public function test_is_envelope_false_for_string(): void {
		$this->assertFalse( Cipher::is_envelope( '[redacted]' ) );
	}

	public function test_is_envelope_false_for_plain_array(): void {
		$this->assertFalse( Cipher::is_envelope( array( 'foo' => 'bar' ) ) );
	}

	public function test_is_envelope_false_for_incomplete_array(): void {
		$this->assertFalse(
			Cipher::is_envelope(
				array(
					'_abilityguard_redacted' => true,
					'ct'                     => 'abc',
				)
			)
		);
	}

	public function test_is_envelope_false_when_flag_not_true(): void {
		$this->assertFalse(
			Cipher::is_envelope(
				array(
					'_abilityguard_redacted' => false,
					'ct'                     => 'abc',
					'iv'                     => 'def',
					'tag'                    => 'ghi',
				)
			)
		);
	}

	public function test_key_derivation_is_deterministic(): void {
		$envelope1 = Cipher::encrypt( 'same value' );
		Cipher::reset_key();
		$envelope2 = Cipher::encrypt( 'same value' );

		// Both must decrypt successfully (same key derived each time).
		$this->assertSame( 'same value', Cipher::decrypt( $envelope1 ) );
		$this->assertSame( 'same value', Cipher::decrypt( $envelope2 ) );
	}

	public function test_different_salt_produces_incompatible_envelope(): void {
		$envelope = Cipher::encrypt( 'secret' );

		Cipher::reset_key();
		Functions\stubs(
			array(
				'wp_salt' => 'completely-different-salt',
			)
		);

		$this->expectException( RuntimeException::class );
		Cipher::decrypt( $envelope );
	}

	public function test_decrypt_throws_on_invalid_envelope(): void {
		$this->expectException( RuntimeException::class );
		Cipher::decrypt( array( 'not' => 'an envelope' ) );
	}
}
