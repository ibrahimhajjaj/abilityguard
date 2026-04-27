<?php
/**
 * Shared helpers used by AbilityWrapper and InvocationObserver.
 *
 * Pulled out of AbilityWrapper so the WP 6.9 before/after-hook listener can
 * derive the same caller_type / log shape without duplicating the logic.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Support\Cipher;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Json;
use AbilityGuard\Support\PayloadCap;
use AbilityGuard\Support\Redactor;

/**
 * Pure helpers. No state.
 */
final class InvocationHelpers {

	/**
	 * RFC 4122 v4 UUID.
	 */
	public static function uuid4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Classify the invocation source. MCP wins when the McpContext is set.
	 */
	public static function detect_caller_type(): string {
		if ( null !== McpContext::current() ) {
			return 'mcp';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return 'internal';
	}

	/**
	 * Current user id, or 0 outside of an authenticated context.
	 */
	public static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Encode $value as canonical JSON, or null if null.
	 *
	 * @param mixed $value Value.
	 */
	public static function encode_or_null( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return Json::canonical( $value );
	}

	/**
	 * Hash $value or return null.
	 *
	 * @param mixed $value Value.
	 */
	public static function hash_or_null( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return Hash::stable( $value );
	}

	/**
	 * Resolve the effective payload byte limit for a given filter.
	 *
	 * Resolution order:
	 *  1. `safety.max_payload_bytes` (per-ability override) - 0 means unlimited.
	 *  2. `apply_filters( $filter_name, $cap_default )`.
	 *
	 * @param array<string, mixed> $safety      Safety config.
	 * @param string               $filter_name WordPress filter name.
	 * @param int                  $cap_default Default cap in bytes.
	 */
	public static function resolve_payload_limit( array $safety, string $filter_name, int $cap_default ): int {
		if ( array_key_exists( 'max_payload_bytes', $safety ) ) {
			return (int) $safety['max_payload_bytes'];
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass `abilityguard_max_args_bytes` or `abilityguard_max_result_bytes`, both prefixed.
		return (int) apply_filters( $filter_name, $cap_default );
	}

	/**
	 * Fire a `_doing_it_wrong` notice when truncation happens. Only fires under WP_DEBUG.
	 *
	 * @param string $ability_name Ability name.
	 */
	public static function maybe_doing_it_wrong( string $ability_name ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong(
				'AbilityGuard',
				sprintf(
					'Payload truncated for ability "%s". Increase the cap via abilityguard_max_args_bytes / abilityguard_max_result_bytes filters or set safety.max_payload_bytes => 0 to disable.',
					esc_html( $ability_name )
				),
				'0.3.0'
			);
		}
	}

	/**
	 * Apply redaction to a value before logging.
	 *
	 * @param array<string, mixed> $safety Safety config.
	 * @param mixed                $value  Value to redact.
	 * @param string               $kind   'input' or 'result'.
	 *
	 * @return mixed Redacted value.
	 */
	public static function redact_value( array $safety, mixed $value, string $kind ): mixed {
		if ( ! empty( $safety['scrub'] ) && is_callable( $safety['scrub'] ) ) {
			return ( $safety['scrub'] )( $value, $kind );
		}

		$default_keys = Redactor::default_keys();
		$global_keys  = function_exists( 'apply_filters' )
			? (array) apply_filters( 'abilityguard_redact_keys', $default_keys, $kind )
			: $default_keys;

		$ability_paths = array();
		if ( isset( $safety['redact'][ $kind ] ) && is_array( $safety['redact'][ $kind ] ) ) {
			$ability_paths = $safety['redact'][ $kind ];
		}

		$all_paths = array_values( array_unique( array_merge( $global_keys, $ability_paths ) ) );
		if ( array() === $all_paths ) {
			return $value;
		}

		$strategy = function_exists( 'apply_filters' )
			? (string) apply_filters( 'abilityguard_redaction_strategy', 'encrypt' )
			: 'encrypt';

		if ( 'encrypt' === $strategy ) {
			return Redactor::redact( $value, $all_paths, Redactor::SENTINEL, static fn( mixed $v ): array => Cipher::encrypt( $v ) );
		}

		$placeholder = function_exists( 'apply_filters' )
			? (string) apply_filters( 'abilityguard_redaction_placeholder', Redactor::SENTINEL )
			: Redactor::SENTINEL;

		return Redactor::redact( $value, $all_paths, $placeholder );
	}

	/**
	 * Redact + encode + cap a value for storage in args_json/result_json.
	 *
	 * @param array<string, mixed> $safety       Safety config.
	 * @param mixed                $value        Value (input or result).
	 * @param string               $kind         'input' or 'result'.
	 * @param string               $field        'args' or 'result' (used in truncation marker).
	 * @param string               $filter_name  abilityguard_max_args_bytes or abilityguard_max_result_bytes.
	 * @param int                  $cap_default  Default byte cap.
	 *
	 * @return array{json: string|null, truncated: bool}
	 */
	public static function shape_for_log( array $safety, mixed $value, string $kind, string $field, string $filter_name, int $cap_default ): array {
		$redacted = self::redact_value( $safety, $value, $kind );
		$json     = self::encode_or_null( $redacted );
		$limit    = self::resolve_payload_limit( $safety, $filter_name, $cap_default );
		return PayloadCap::cap_json( $field, $json, $limit );
	}
}
