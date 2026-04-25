<?php
/**
 * Execute-callback wrapper that adds snapshot + audit + rollback-eligibility
 * around any ability whose registration carries a `safety` config.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

use AbilityGuard\Approval\ApprovalService;
use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Concurrency\Lock;
use AbilityGuard\Contracts\AuditLoggerInterface;
use AbilityGuard\Contracts\SnapshotServiceInterface;
use AbilityGuard\Support\Cipher;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Json;
use AbilityGuard\Support\PayloadCap;
use AbilityGuard\Support\Redactor;
use Throwable;
use WP_Error;

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
			$invocation_id        = self::uuid4();
			$parent_invocation_id = InvocationStack::current();
			$destructive          = (bool) ( $this->safety['destructive'] ?? false );
			$requires_approval    = ! empty( $this->safety['requires_approval'] );

			// ---------------------------------------------------------------
			// Advisory lock: serialise all invocations that share the same
			// snapshot surfaces so capture+execute is atomic per surface set.
			// ---------------------------------------------------------------
			$lock_key     = null;
			$lock_timeout = $this->resolve_lock_timeout();
			$has_spec     = ! empty( $this->safety['snapshot'] );

			if ( $has_spec && $lock_timeout >= 0 ) {
				$resolved_spec = $this->resolve_spec_for_lock( $input );

				if ( array() !== $resolved_spec ) {
					$lock_key = Lock::key_for_spec( $resolved_spec );

					if ( ! Lock::acquire( $lock_key, $lock_timeout ) ) {
						// Another invocation holds the lock - reject immediately.
						// No log row, no snapshot.
						return new WP_Error(
							'abilityguard_lock_timeout',
							'Another invocation is in progress for the same surfaces. Please retry.',
							array( 'status' => 429 )
						);
					}
				}
			}

			// ---------------------------------------------------------------
			// Approval gate: block execution, log as pending, return WP_Error(202).
			// Lock is held here so pending-row writes are also serialised; we
			// release immediately after audit->log() since no execution follows.
			// ---------------------------------------------------------------
			if ( $requires_approval && ! ApprovalService::is_approving() ) {
				$snapshot = $this->snapshots->capture( $invocation_id, $this->safety, $input );

				$log_id = $this->audit->log(
					array(
						'invocation_id'        => $invocation_id,
						'parent_invocation_id' => $parent_invocation_id,
						'ability_name'         => $this->ability_name,
						'caller_type'          => self::detect_caller_type(),
						'user_id'              => self::current_user_id(),
						'args_json'            => self::encode_or_null( $this->redact_value( $input, 'input' ) ),
						'result_json'          => null,
						'status'               => 'pending',
						'destructive'          => $destructive,
						'duration_ms'          => 0,
						'pre_hash'             => $snapshot['pre_hash'],
						'post_hash'            => null,
						'snapshot_id'          => $snapshot['snapshot_id'],
					)
				);

				// Release lock before returning - no execution will follow.
				if ( null !== $lock_key ) {
					Lock::release( $lock_key );
					$lock_key = null;
				}

				$approval_service = new ApprovalService();
				$approval_id      = $approval_service->request( $this->ability_name, $input, $invocation_id, $log_id );

				return new WP_Error(
					'abilityguard_pending_approval',
					sprintf( 'Ability "%s" requires approval before execution.', $this->ability_name ),
					array(
						'status'      => 202,
						'approval_id' => $approval_id,
						'log_id'      => $log_id,
					)
				);
			}

			// ---------------------------------------------------------------
			// Execute under the lock (released in the finally block).
			// ---------------------------------------------------------------
			try {
				$snapshot = $this->snapshots->capture( $invocation_id, $this->safety, $input );

				/**
				 * Fires immediately before the wrapped callback runs.
				 *
				 * Hook this to start a Sentry/Datadog span, increment a counter,
				 * or write a structured-log line for observability.
				 *
				 * @since 0.5.0
				 *
				 * @param string $invocation_id UUID for this invocation.
				 * @param string $ability_name  Registered ability name.
				 * @param mixed  $input         Ability input (un-redacted).
				 * @param array<string, mixed> $context {
				 *   @type bool   $destructive Whether the ability is destructive.
				 *   @type int    $snapshot_id Snapshot row id (0 if none).
				 *   @type string $caller_type 'rest', 'mcp', 'cli', or 'internal'.
				 * }
				 */
				do_action(
					'abilityguard_invocation_started',
					$invocation_id,
					$this->ability_name,
					$input,
					array(
						'destructive' => $destructive,
						'snapshot_id' => (int) ( $snapshot['snapshot_id'] ?? 0 ),
						'caller_type' => self::detect_caller_type(),
					)
				);

				$start  = hrtime( true );
				$result = null;
				$status = 'ok';
				$thrown = null;
				InvocationStack::push( $invocation_id );
				try {
					$result = $original_callback( $input );
					if ( is_wp_error( $result ) ) {
						$status = 'error';
					}
				} catch ( Throwable $e ) {
					$status = 'error';
					$thrown = $e;
				} finally {
					InvocationStack::pop();
				}
				$duration_ms = (int) ( ( hrtime( true ) - $start ) / 1_000_000 );

				if ( 'error' === $status ) {
					/**
					 * Fires when the wrapped callback throws or returns WP_Error.
					 *
					 * @since 0.5.0
					 *
					 * @param string          $invocation_id UUID.
					 * @param string          $ability_name  Ability name.
					 * @param Throwable|null  $thrown        Exception instance (null if WP_Error).
					 * @param mixed           $result        WP_Error returned (null if exception).
					 * @param int             $duration_ms   Time spent in the callback.
					 */
					do_action( 'abilityguard_invocation_error', $invocation_id, $this->ability_name, $thrown, $result, $duration_ms );
				}

				if ( 'ok' === $status && null !== $snapshot['snapshot_id'] ) {
					$this->snapshots->capture_post( $snapshot['snapshot_id'], $this->safety, $input );
				}

				$mcp_id      = McpContext::current();
				$caller_type = null !== $mcp_id ? 'mcp' : self::detect_caller_type();

				// Hash BEFORE redaction so hashes reflect real values.
				$post_hash     = self::hash_or_null( $result );
				$logged_input  = $this->redact_value( $input, 'input' );
				$logged_result = $thrown ? null : $this->redact_value( $result, 'result' );

				// Encode → cap. Caps run on the JSON-encoded (redacted) form so
				// truncation marker reflects what's actually being stored.
				$args_json   = self::encode_or_null( $logged_input );
				$result_json = $thrown ? null : self::encode_or_null( $logged_result );

				$args_limit   = self::resolve_payload_limit( $this->safety, 'abilityguard_max_args_bytes', 65536 );
				$result_limit = self::resolve_payload_limit( $this->safety, 'abilityguard_max_result_bytes', 131072 );

				$capped_args   = PayloadCap::cap_json( 'args', $args_json, $args_limit );
				$capped_result = PayloadCap::cap_json( 'result', $result_json, $result_limit );

				if ( $capped_args['truncated'] || $capped_result['truncated'] ) {
					self::maybe_doing_it_wrong( $this->ability_name );
				}

				$log_id = $this->audit->log(
					array(
						'invocation_id'        => $invocation_id,
						'parent_invocation_id' => $parent_invocation_id,
						'ability_name'         => $this->ability_name,
						'caller_type'          => $caller_type,
						'caller_id'            => $mcp_id,
						'user_id'              => self::current_user_id(),
						'args_json'            => $capped_args['json'],
						'result_json'          => $capped_result['json'],
						'status'               => $status,
						'destructive'          => $destructive,
						'duration_ms'          => $duration_ms,
						'pre_hash'             => $snapshot['pre_hash'],
						'post_hash'            => $post_hash,
						'snapshot_id'          => $snapshot['snapshot_id'],
					)
				);

				if ( $log_id > 0 && ! empty( $this->safety['skip_drift_check'] ) ) {
					LogMeta::set( $log_id, 'skip_drift_check', '1' );
				}

				/**
				 * Fires after every invocation, success or error, after the audit row is written.
				 *
				 * Hook this to close a Sentry/Datadog span, record duration histograms,
				 * or emit a structured-log line.
				 *
				 * @since 0.5.0
				 *
				 * @param string $invocation_id UUID.
				 * @param string $ability_name  Ability name.
				 * @param string $status        'ok' or 'error'.
				 * @param int    $duration_ms   Time spent in the original callback.
				 * @param array<string, mixed> $context {
				 *   @type bool   $destructive
				 *   @type string $caller_type
				 *   @type string|null $caller_id
				 *   @type int    $snapshot_id
				 *   @type bool   $args_truncated
				 *   @type bool   $result_truncated
				 * }
				 */
				do_action(
					'abilityguard_invocation_completed',
					$invocation_id,
					$this->ability_name,
					$status,
					$duration_ms,
					array(
						'destructive'      => $destructive,
						'caller_type'      => $caller_type,
						'caller_id'        => $mcp_id,
						'snapshot_id'      => (int) ( $snapshot['snapshot_id'] ?? 0 ),
						'args_truncated'   => (bool) $capped_args['truncated'],
						'result_truncated' => (bool) $capped_result['truncated'],
					)
				);

				if ( null !== $thrown ) {
					throw $thrown;
				}
				return $result;
			} finally {
				if ( null !== $lock_key ) {
					Lock::release( $lock_key );
				}
			}
		};
	}

	/**
	 * Resolve the effective lock timeout from safety config and filter.
	 *
	 * Resolution order:
	 *  1. `safety.lock_timeout` (per-ability override). Negative = lock disabled.
	 *  2. `apply_filters( 'abilityguard_lock_timeout', 5 )`.
	 *
	 * @return int Effective timeout in seconds. Negative = lock disabled.
	 */
	private function resolve_lock_timeout(): int {
		if ( array_key_exists( 'lock_timeout', $this->safety ) ) {
			return (int) $this->safety['lock_timeout'];
		}
		return (int) apply_filters( 'abilityguard_lock_timeout', 5 );
	}

	/**
	 * Resolve the snapshot spec for computing the lock key.
	 *
	 * Mirrors SnapshotService::resolve_spec() but kept here to avoid a
	 * cross-service dependency just for key derivation.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed> Resolved spec; empty array if no snapshot configured.
	 */
	private function resolve_spec_for_lock( mixed $input ): array {
		if ( empty( $this->safety['snapshot'] ) ) {
			return array();
		}
		$snapshot = $this->safety['snapshot'];
		if ( is_callable( $snapshot ) ) {
			$resolved = $snapshot( $input );
			return is_array( $resolved ) ? $resolved : array();
		}
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}
		return array();
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
	 * Resolve the effective payload byte limit for a given filter.
	 *
	 * Resolution order:
	 *  1. `safety.max_payload_bytes` (per-ability override) - 0 means unlimited.
	 *  2. `apply_filters( $filter_name, $cap_default )`.
	 *
	 * @param array<string, mixed> $safety      Safety config.
	 * @param string               $filter_name WordPress filter name.
	 * @param int                  $cap_default Default cap in bytes.
	 *
	 * @return int Effective limit; 0 = unlimited.
	 */
	private static function resolve_payload_limit( array $safety, string $filter_name, int $cap_default ): int {
		if ( array_key_exists( 'max_payload_bytes', $safety ) ) {
			return (int) $safety['max_payload_bytes'];
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- callers pass `abilityguard_max_args_bytes` or `abilityguard_max_result_bytes`, both prefixed.
		return (int) apply_filters( $filter_name, $cap_default );
	}

	/**
	 * Fire a `_doing_it_wrong` notice once per request when truncation occurs.
	 *
	 * Only fires on non-production sites (WP_DEBUG must be true).
	 *
	 * @param string $ability_name Ability that triggered truncation.
	 */
	private static function maybe_doing_it_wrong( string $ability_name ): void {
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
	 * Safety.scrub callable wins. Otherwise: global default keys + filter +
	 * per-ability `safety.redact[$kind]` paths feed Redactor::redact().
	 *
	 * @param mixed  $value Value to redact (input or result).
	 * @param string $kind  'input' or 'result'.
	 * @return mixed Redacted value.
	 */
	private function redact_value( mixed $value, string $kind ): mixed {
		if ( ! empty( $this->safety['scrub'] ) && is_callable( $this->safety['scrub'] ) ) {
			return ( $this->safety['scrub'] )( $value, $kind );
		}

		$default_keys = Redactor::default_keys();
		$global_keys  = function_exists( 'apply_filters' )
			? (array) apply_filters( 'abilityguard_redact_keys', $default_keys, $kind )
			: $default_keys;

		$ability_paths = array();
		if ( isset( $this->safety['redact'][ $kind ] ) && is_array( $this->safety['redact'][ $kind ] ) ) {
			$ability_paths = $this->safety['redact'][ $kind ];
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

		// Placeholder (legacy v0.3) path.
		$placeholder = function_exists( 'apply_filters' )
			? (string) apply_filters( 'abilityguard_redaction_placeholder', Redactor::SENTINEL )
			: Redactor::SENTINEL;

		return Redactor::redact( $value, $all_paths, $placeholder );
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
