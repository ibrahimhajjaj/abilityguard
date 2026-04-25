<?php
/**
 * AES-256-GCM encryption helper for at-rest redaction.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Support;

use RuntimeException;

/**
 * Cipher.
 *
 * Encrypts arbitrary values with AES-256-GCM so redacted snapshot entries
 * remain restorable on rollback without exposing the secret in the audit log.
 *
 * Key derivation: HKDF-SHA-256 over `wp_salt('auth') . 'abilityguard:redact:v1'`
 * producing a 32-byte key.  The derived key is cached in a static property so
 * it is derived at most once per PHP process.
 *
 * Envelope shape:
 * ```
 * [
 *   '_abilityguard_redacted' => true,
 *   'ct'  => '<base64>',
 *   'iv'  => '<base64>',
 *   'tag' => '<base64>',
 * ]
 * ```
 */
final class Cipher {

	private const CIPHER = 'aes-256-gcm';

	/**
	 * Cached derived key (binary, 32 bytes).
	 *
	 * @var string|null
	 */
	private static ?string $key = null;

	/**
	 * Encrypt a value and return an envelope array.
	 *
	 * The value is JSON-encoded before encryption so any PHP type is round-trippable.
	 *
	 * @param mixed $value Value to encrypt.
	 *
	 * @return array{ _abilityguard_redacted: true, ct: string, iv: string, tag: string }
	 *
	 * @throws RuntimeException When encryption fails.
	 */
	public static function encrypt( mixed $value ): array {
		$plaintext = wp_json_encode( $value );
		if ( false === $plaintext ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: wp_json_encode failed.' );
		}

		$iv  = random_bytes( 12 ); // 96-bit IV recommended for GCM.
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::derived_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16 // 128-bit auth tag.
		);

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: openssl_encrypt failed.' );
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array(
			'_abilityguard_redacted' => true,
			'ct'                     => base64_encode( $ciphertext ),
			'iv'                     => base64_encode( $iv ),
			'tag'                    => base64_encode( $tag ),
		);
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt an envelope produced by encrypt().
	 *
	 * @param array<string, mixed> $envelope Envelope array.
	 *
	 * @return mixed Decrypted, JSON-decoded original value.
	 *
	 * @throws RuntimeException On bad shape, tampered ciphertext, or wrong key.
	 */
	public static function decrypt( array $envelope ): mixed {
		if ( ! self::is_envelope( $envelope ) ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: invalid envelope shape.' );
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ct  = base64_decode( (string) $envelope['ct'], true );
		$iv  = base64_decode( (string) $envelope['iv'], true );
		$tag = base64_decode( (string) $envelope['tag'], true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $ct || false === $iv || false === $tag ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: base64_decode failed.' );
		}

		$plaintext = openssl_decrypt(
			$ct,
			self::CIPHER,
			self::derived_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: decryption failed (tampered or wrong key).' );
		}

		$decoded = json_decode( $plaintext, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'AbilityGuard\\Support\\Cipher: JSON decode failed after decryption.' );
		}

		return $decoded;
	}

	/**
	 * Duck-type check: is $value a Cipher envelope?
	 *
	 * @param mixed $value Any value.
	 *
	 * @return bool
	 */
	public static function is_envelope( mixed $value ): bool {
		return is_array( $value )
			&& isset( $value['_abilityguard_redacted'] )
			&& true === $value['_abilityguard_redacted']
			&& isset( $value['ct'], $value['iv'], $value['tag'] );
	}

	/**
	 * Return the 32-byte HKDF-derived key, computing it once per process.
	 *
	 * @return string Binary key.
	 */
	private static function derived_key(): string {
		if ( null === self::$key ) {
			$ikm       = wp_salt( 'auth' ) . 'abilityguard:redact:v1';
			self::$key = hash_hkdf( 'sha256', $ikm, 32 );
		}
		return self::$key;
	}

	/**
	 * Reset the cached key. Used in tests that stub wp_salt().
	 *
	 * @internal
	 */
	public static function reset_key(): void {
		self::$key = null;
	}
}
