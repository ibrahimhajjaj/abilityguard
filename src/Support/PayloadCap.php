<?php
/**
 * Payload size-cap helper.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Support;

/**
 * Pure helper that applies byte-size caps to JSON payloads before persistence.
 *
 * All measurements are on the uncompressed canonical JSON byte-length.
 * When a payload exceeds its limit, the original JSON is replaced with a
 * structured truncation marker so downstream consumers can detect the event.
 */
final class PayloadCap {

	/**
	 * Truncation marker key present in replacement JSON objects.
	 */
	public const MARKER_KEY = '_abilityguard_truncated';

	/**
	 * Apply a byte cap to a single `args_json` or `result_json` value.
	 *
	 * A $limit of 0 means "no cap" - the original JSON is returned unchanged.
	 *
	 * @param string  $kind  Human-readable label stored in the marker ('args' or 'result').
	 * @param ?string $json  JSON string to evaluate. Null passes through as-is.
	 * @param int     $limit Maximum allowed byte length (0 = unlimited).
	 *
	 * @return array{ json: string|null, truncated: bool, original_bytes: int }
	 */
	public static function cap_json( string $kind, ?string $json, int $limit ): array {
		if ( null === $json ) {
			return array(
				'json'           => null,
				'truncated'      => false,
				'original_bytes' => 0,
			);
		}

		$bytes = strlen( $json );

		if ( 0 === $limit || $bytes <= $limit ) {
			return array(
				'json'           => $json,
				'truncated'      => false,
				'original_bytes' => $bytes,
			);
		}

		$marker = array(
			self::MARKER_KEY => true,
			'original_bytes' => $bytes,
			'kind'           => $kind,
		);

		return array(
			'json'           => Json::canonical( $marker ),
			'truncated'      => true,
			'original_bytes' => $bytes,
		);
	}

	/**
	 * Apply a byte cap per-surface to a surfaces map.
	 *
	 * A $limit of 0 means "no cap" for all surfaces. Each surface is evaluated
	 * independently: surfaces within budget survive intact; over-budget surfaces
	 * are replaced with a structured marker.
	 *
	 * Measurement is on `Json::canonical($surface_value)` - uncompressed.
	 *
	 * @param array<string, mixed> $surfaces Map of surface-name => collected data.
	 * @param int                  $limit    Per-surface byte limit (0 = unlimited).
	 *
	 * @return array{ surfaces: array<string, mixed>, truncated: array<string, int> }
	 */
	public static function cap_surfaces( array $surfaces, int $limit ): array {
		if ( 0 === $limit ) {
			return array(
				'surfaces'  => $surfaces,
				'truncated' => array(),
			);
		}

		$result    = array();
		$truncated = array();

		foreach ( $surfaces as $surface => $data ) {
			$json  = Json::canonical( $data );
			$bytes = strlen( $json );

			if ( $bytes <= $limit ) {
				$result[ $surface ] = $data;
			} else {
				$truncated[ $surface ] = $bytes;
				$result[ $surface ]    = array(
					self::MARKER_KEY => true,
					'original_bytes' => $bytes,
					'surface'        => $surface,
				);
			}
		}

		return array(
			'surfaces'  => $result,
			'truncated' => $truncated,
		);
	}
}
