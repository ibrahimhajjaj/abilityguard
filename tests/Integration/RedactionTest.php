<?php
/**
 * Integration tests for the v0.3 secret-redaction feature.
 *
 * All assertions run against the real $wpdb + real services.
 * No mocks of project services.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Registry\AbilityWrapper;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Redactor;
use WP_UnitTestCase;

/**
 * Redaction end-to-end tests - runs inside wp-env against real $wpdb.
 */
final class RedactionTest extends WP_UnitTestCase {

	/**
	 * Monotonic counter so every test gets a unique ability name.
	 *
	 * @var int
	 */
	private static int $counter = 0;

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	/**
	 * Build a fresh AbilityWrapper, invoke it, and return the logged row.
	 *
	 * @param array<string, mixed> $safety   Safety config.
	 * @param mixed                $input    Input passed to the ability.
	 * @param callable             $callback Inner ability callback.
	 *
	 * @return array<string, mixed>
	 */
	private function run_ability( array $safety, mixed $input, callable $callback ): array {
		++self::$counter;
		$ability_name = 'redaction-test/ability-' . self::$counter;

		$wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$ability_name,
			$safety
		);

		$wrapped = $wrapper->wrap( $callback );
		$wrapped( $input );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );
		$this->assertNotEmpty( $rows, "Log row not found for {$ability_name}" );

		return $rows[0];
	}

	/**
	 * Default redaction list must scrub 'password' from logged args.
	 */
	public function test_password_in_input_is_redacted_in_args_json(): void {
		$input = array(
			'username' => 'alice',
			'password' => 'super-secret-password',
		);

		$row = $this->run_ability(
			array( 'destructive' => false ),
			$input,
			static function ( mixed $ignored ) {
				return array( 'ok' => true );
			}
		);

		$this->assertNotNull( $row['args_json'] );

		$logged_args = json_decode( (string) $row['args_json'], true );
		$this->assertIsArray( $logged_args );

		$this->assertSame( 'alice', $logged_args['username'] );
		$this->assertSame( Redactor::SENTINEL, $logged_args['password'], 'password must be redacted in args_json' );
		$this->assertStringNotContainsString( 'super-secret-password', (string) $row['args_json'] );
	}

	/**
	 * Per-ability dot-path redaction for input fields.
	 */
	public function test_ability_redact_input_paths_are_applied(): void {
		$input = array(
			'actor'   => array(
				'principal' => 'admin-token',
				'name'      => 'alice',
			),
			'payload' => 'hello',
		);

		$row = $this->run_ability(
			array(
				'destructive' => false,
				'redact'      => array(
					'input' => array( 'actor.principal' ),
				),
			),
			$input,
			static function ( mixed $ignored ) {
				return true;
			}
		);

		$logged_args = json_decode( (string) $row['args_json'], true );
		$this->assertIsArray( $logged_args );

		$this->assertSame( Redactor::SENTINEL, $logged_args['actor']['principal'] );
		$this->assertSame( 'alice', $logged_args['actor']['name'] );
		$this->assertSame( 'hello', $logged_args['payload'] );
	}

	/**
	 * Per-ability redact.result paths suppress sensitive return values.
	 */
	public function test_ability_redact_result_paths_are_applied(): void {
		$row = $this->run_ability(
			array(
				'destructive' => false,
				'redact'      => array(
					'result' => array( 'access_token' ),
				),
			),
			array( 'user' => 'bob' ),
			static function ( mixed $ignored ) {
				return array(
					'access_token' => 'eyJhbGciOiJIUzI1NiJ9',
					'expires_in'   => 3600,
				);
			}
		);

		$logged_result = json_decode( (string) $row['result_json'], true );
		$this->assertIsArray( $logged_result );

		$this->assertSame( Redactor::SENTINEL, $logged_result['access_token'] );
		$this->assertSame( 3600, $logged_result['expires_in'] );
	}

	/**
	 * When safety.scrub is set it owns full redaction; its return lands in storage.
	 */
	public function test_scrub_callable_receives_kind_and_return_lands_in_storage(): void {
		$received_kinds = array();

		$scrub = static function ( array $payload, string $kind ) use ( &$received_kinds ): array {
			$received_kinds[]      = $kind;
			$payload['__scrubbed'] = true;
			return $payload;
		};

		$row = $this->run_ability(
			array(
				'destructive' => false,
				'scrub'       => $scrub,
			),
			array(
				'username' => 'alice',
				'password' => 'plain',
			),
			static function ( mixed $ignored ) {
				return array( 'token' => 'raw-token' );
			}
		);

		$this->assertContains( 'input', $received_kinds );
		$this->assertContains( 'result', $received_kinds );

		$logged_args = json_decode( (string) $row['args_json'], true );
		$this->assertIsArray( $logged_args );
		$this->assertTrue( $logged_args['__scrubbed'], 'scrub return value must land in args_json' );

		$logged_result = json_decode( (string) $row['result_json'], true );
		$this->assertIsArray( $logged_result );
		$this->assertTrue( $logged_result['__scrubbed'], 'scrub return value must land in result_json' );
	}

	/**
	 * A custom abilityguard_redact_keys filter adds 'special_token' to the list.
	 */
	public function test_global_filter_redacts_custom_key_in_result(): void {
		$filter_fn = static function ( array $keys, string $unused_kind ): array {
			return array_merge( $keys, array( 'special_token' ) );
		};
		add_filter( 'abilityguard_redact_keys', $filter_fn, 10, 2 );

		try {
			$row = $this->run_ability(
				array( 'destructive' => false ),
				array( 'user' => 'bob' ),
				static function ( mixed $ignored ) {
					return array(
						'special_token' => 'ultra-secret',
						'status'        => 'ok',
					);
				}
			);
		} finally {
			remove_filter( 'abilityguard_redact_keys', $filter_fn, 10 );
		}

		$logged_result = json_decode( (string) $row['result_json'], true );
		$this->assertIsArray( $logged_result );

		$this->assertSame( Redactor::SENTINEL, $logged_result['special_token'] );
		$this->assertSame( 'ok', $logged_result['status'] );
		$this->assertStringNotContainsString( 'ultra-secret', (string) $row['result_json'] );
	}

	/**
	 * When an option is listed in safety.redact.surfaces the stored value is the
	 * sentinel. Rollback without force returns 'abilityguard_rollback_partial';
	 * with force=true it succeeds.
	 *
	 * Trade-off (option 1, v0.3 spec): redaction wins over rollback fidelity.
	 * Callers opt in by listing keys in safety.redact.surfaces[<surface>].
	 * Those keys are non-restorable; the audit record still shows they existed.
	 */
	public function test_redacted_snapshot_causes_partial_rollback_error(): void {
		$option_name = 'site_api_key_' . uniqid();
		update_option( $option_name, 'real-api-key-value' );

		$safety = array(
			'destructive' => true,
			'snapshot'    => array(
				'options' => array( $option_name ),
			),
			'redact'      => array(
				'surfaces' => array(
					'options' => array( $option_name ),
				),
			),
		);

		$store   = new SnapshotStore();
		$service = new SnapshotService( $store );
		$snap    = $service->capture( 'inv-redact-snap-1', $safety, null );

		// Stored surfaces must contain the sentinel for the redacted option.
		$stored = $store->find_by_invocation_id( 'inv-redact-snap-1' );
		$this->assertNotNull( $stored );
		$this->assertSame(
			Redactor::SENTINEL,
			$stored['surfaces']['options'][ $option_name ],
			'Snapshot must store sentinel for redacted option'
		);

		// pre_hash reflects the REAL (un-redacted) value.
		$expected_hash = Hash::stable( array( 'options' => array( $option_name => 'real-api-key-value' ) ) );
		$this->assertSame( $expected_hash, $snap['pre_hash'], 'pre_hash must hash the un-redacted surfaces' );

		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-redact-snap-1',
				'ability_name'  => 'redaction-test/snap-partial',
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => true,
				'duration_ms'   => 1,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => $snap['snapshot_id'],
			)
		);

		update_option( $option_name, 'mutated-value' );

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );

		// Without force: partial error, option NOT restored.
		$result = $rollback->rollback( 'inv-redact-snap-1' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_rollback_partial', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'skipped_keys', $data );
		$this->assertContains( "options.{$option_name}", $data['skipped_keys'] );
		$this->assertSame( 'mutated-value', get_option( $option_name ) );

		// With force=true: succeeds and marks the invocation rolled-back.
		$result_forced = $rollback->rollback( 'inv-redact-snap-1', true );
		$this->assertTrue( $result_forced, 'force=true must mark the invocation rolled-back' );
	}

	/**
	 * Options that are not in the redact list roll back normally.
	 */
	public function test_non_redacted_snapshot_rolls_back_cleanly(): void {
		$option_name = 'rollback_safe_option_' . uniqid();
		update_option( $option_name, 'original-value' );

		$safety = array(
			'destructive' => true,
			'snapshot'    => array(
				'options' => array( $option_name ),
			),
		);

		$store   = new SnapshotStore();
		$service = new SnapshotService( $store );
		$snap    = $service->capture( 'inv-redact-snap-2', $safety, null );

		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-redact-snap-2',
				'ability_name'  => 'redaction-test/snap-clean',
				'caller_type'   => 'cli',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => true,
				'duration_ms'   => 1,
				'pre_hash'      => $snap['pre_hash'],
				'post_hash'     => null,
				'snapshot_id'   => $snap['snapshot_id'],
			)
		);

		update_option( $option_name, 'changed-value' );

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result   = $rollback->rollback( 'inv-redact-snap-2' );

		$this->assertTrue( $result, 'Non-redacted snapshot must roll back cleanly' );
		$this->assertSame( 'original-value', get_option( $option_name ) );
	}
}
