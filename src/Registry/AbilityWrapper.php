<?php
/**
 * Execute-callback wrapper that adds snapshot + audit + rollback-eligibility
 * around any ability whose registration carries a `safety` config.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Json;
use Throwable;

/**
 * AbilityWrapper.
 *
 * Given the original execute_callback and the declared safety config,
 * returns a new callable that:
 *
 *   1. Mints an invocation id (uuid v4).
 *   2. Snapshots declared surfaces via SnapshotService.
 *   3. Invokes the original callback.
 *   4. Emits an audit row via AuditLogger.
 *
 * Errors from the inner callback are captured as status=error rows and
 * re-thrown so the core Abilities API can surface them as WP_Error.
 */
final class AbilityWrapper {

	/**
	 * Constructor.
	 *
	 * @param SnapshotServiceInterface $snapshots    Snapshot collaborator.
	 * @param AuditLoggerInterface     $audit        Audit collaborator.
	 * @param string                   $ability_name Registered ability name.
	 * @param array<string, mixed>     $safety       The safety config sub-array.
	 */
	public function __construct(
		private SnapshotServiceInterface $snapshots,
		private AuditLoggerInterface $audit,
		private string $ability_name,
		private array $safety
	) {}

	/**
	 * Build the wrapped callable to replace execute_callback.
	 *
	 * @param callable $original_callback Original execute_callback from the ability args.
	 *
	 * @return callable
	 */
	public function wrap( callable $original_callback ): callable {
		return function ( $input = null ) use ( $original_callback ) {
			$invocation_id = self::uuid4();
			$destructive   = (bool) ( $this->safety['destructive'] ?? false );

			$snapshot = $this->snapshots->capture( $invocation_id, $this->safety, $input );

			$start  = hrtime( true );
			$result = null;
			$status = 'ok';
			$thrown = null;
			try {
				$result = $original_callback( $input );
				if ( is_wp_error( $result ) ) {
					$status = 'error';
				}
			} catch ( Throwable $e ) {
				$status = 'error';
				$thrown = $e;
			}
			$duration_ms = (int) ( ( hrtime( true ) - $start ) / 1_000_000 );

			if ( 'ok' === $status && null !== $snapshot['snapshot_id'] ) {
				$this->snapshots->capture_post( $snapshot['snapshot_id'], $this->safety, $input );
			}

			$mcp_id      = McpContext::current();
			$caller_type = null !== $mcp_id ? 'mcp' : self::detect_caller_type();

			$this->audit->log(
				array(
					'invocation_id' => $invocation_id,
					'ability_name'  => $this->ability_name,
					'caller_type'   => $caller_type,
					'caller_id'     => $mcp_id,
					'user_id'       => self::current_user_id(),
					'args_json'     => self::encode_or_null( $input ),
					'result_json'   => $thrown ? null : self::encode_or_null( $result ),
					'status'        => $status,
					'destructive'   => $destructive,
					'duration_ms'   => $duration_ms,
					'pre_hash'      => $snapshot['pre_hash'],
					'post_hash'     => self::hash_or_null( $result ),
					'snapshot_id'   => $snapshot['snapshot_id'],
				)
			);

			if ( null !== $thrown ) {
				throw $thrown;
			}
			return $result;
		};
	}

	/**
	 * Classify the invocation source.
	 */
	private static function detect_caller_type(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return 'internal';
	}

	/**
	 * Current user id or 0.
	 */
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Encode $value as canonical JSON, or null if empty/scalar-empty.
	 *
	 * @param mixed $value Value.
	 */
	private static function encode_or_null( $value ): ?string {
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
	private static function hash_or_null( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return Hash::stable( $value );
	}

	/**
	 * RFC 4122 v4 UUID.
	 */
	private static function uuid4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
