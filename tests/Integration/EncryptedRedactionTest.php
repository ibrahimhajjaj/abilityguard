<?php
/**
 * Integration tests for v0.4 encrypted-at-rest redaction.
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
use AbilityGuard\Support\Cipher;
use AbilityGuard\Support\Hash;
use AbilityGuard\Support\Redactor;
use WP_UnitTestCase;

/**
 * Encrypted redaction end-to-end tests.
 */
final class EncryptedRedactionTest extends WP_UnitTestCase {

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
		$ability_name = 'encrypted-redact-test/ability-' . self::$counter;

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

	// ------------------------------------------------------------------ //
	// 1. args_json contains envelope, not the secret                      //
	// ------------------------------------------------------------------ //

	/**
	 * Default strategy 'encrypt': password key in args_json must be a Cipher
	 * envelope, not the plaintext and not '[redacted]'.
	 */
	public function test_password_in_input_stored_as_cipher_envelope(): void {
		$input = array(
			'username' => 'alice',
			'password' => 'super-secret-password',
		);

		$row = $this->run_ability(
			array( 'destructive' => false ),
			$input,
			static function ( mixed $ignored ): array {
				unset( $ignored );
				return array( 'ok' => true );
			}
		);

		$this->assertNotNull( $row['args_json'] );
		$logged_args = json_decode( (string) $row['args_json'], true );
		$this->assertIsArray( $logged_args );

		// Username is untouched.
		$this->assertSame( 'alice', $logged_args['username'] );

		// Password is an envelope, not the plaintext.
		$this->assertStringNotContainsString( 'super-secret-password', (string) $row['args_json'] );
		$this->assertNotSame( Redactor::SENTINEL, $logged_args['password'] );

		// The stored value must be a Cipher envelope.
		$this->assertTrue(
			Cipher::is_envelope( $logged_args['password'] ),
			'password must be stored as a Cipher envelope'
		);
	}

	// ------------------------------------------------------------------ //
	// 2. Rollback decrypts envelope and restores real value               //
	// ------------------------------------------------------------------ //

	/**
	 * Rollback of a snapshot with an encrypted option restores the real value.
	 */
	public function test_rollback_decrypts_envelope_and_restores_value(): void {
		$option_name = 'enc_redact_api_key_' . uniqid();
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
		$snap    = $service->capture( 'inv-enc-redact-1', $safety, null );

		// Stored surface must contain a Cipher envelope, not the plaintext.
		$stored = $store->find_by_invocation_id( 'inv-enc-redact-1' );
		$this->assertNotNull( $stored );
		$stored_entry = $stored['surfaces']['options'][ $option_name ];
		$this->assertTrue(
			Cipher::is_envelope( $stored_entry ),
			'Snapshot must store a Cipher envelope for the redacted option'
		);

		// pre_hash reflects the REAL (un-redacted) value.
		$expected_hash = Hash::stable( array( 'options' => array( $option_name => 'real-api-key-value' ) ) );
		$this->assertSame( $expected_hash, $snap['pre_hash'], 'pre_hash must hash the un-redacted surfaces' );

		// Write audit log row so rollback can find it.
		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-enc-redact-1',
				'ability_name'  => 'enc-redact-test/snap-restore',
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

		// Mutate the option so there's something to roll back.
		update_option( $option_name, 'mutated-value' );
		$this->assertSame( 'mutated-value', get_option( $option_name ) );

		// Rollback must succeed and restore the real value.
		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result   = $rollback->rollback( 'inv-enc-redact-1' );

		$this->assertTrue( $result, 'Rollback with encrypted snapshot must succeed' );
		$this->assertSame( 'real-api-key-value', get_option( $option_name ), 'Real value must be restored after decrypt' );
	}

	// ------------------------------------------------------------------ //
	// 3. 'placeholder' strategy falls back to v0.3 behavior              //
	// ------------------------------------------------------------------ //

	/**
	 * When abilityguard_redaction_strategy returns 'placeholder', args_json
	 * must contain '[redacted]' and rollback must return partial error.
	 */
	public function test_placeholder_strategy_filter_falls_back_to_v03_behavior(): void {
		$filter_fn = static fn(): string => 'placeholder';
		add_filter( 'abilityguard_redaction_strategy', $filter_fn );

		try {
			$input = array(
				'username' => 'bob',
				'password' => 'should-be-sentinel',
			);

			$row = $this->run_ability(
				array( 'destructive' => false ),
				$input,
				static function ( mixed $ignored ): bool {
					unset( $ignored );
					return true;
				}
			);

			$this->assertNotNull( $row['args_json'] );
			$logged_args = json_decode( (string) $row['args_json'], true );
			$this->assertIsArray( $logged_args );

			// In placeholder mode the sentinel string must be used.
			$this->assertSame( Redactor::SENTINEL, $logged_args['password'] );
			$this->assertStringNotContainsString( 'should-be-sentinel', (string) $row['args_json'] );
		} finally {
			remove_filter( 'abilityguard_redaction_strategy', $filter_fn );
		}
	}

	/**
	 * Rollback of a v0.3-style placeholder snapshot returns partial WP_Error.
	 */
	public function test_placeholder_snapshot_causes_partial_rollback_error(): void {
		$filter_fn = static fn(): string => 'placeholder';
		add_filter( 'abilityguard_redaction_strategy', $filter_fn );

		$option_name = 'enc_placeholder_key_' . uniqid();
		update_option( $option_name, 'real-value' );

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
		$snap    = $service->capture( 'inv-enc-placeholder-1', $safety, null );

		remove_filter( 'abilityguard_redaction_strategy', $filter_fn );

		$stored = $store->find_by_invocation_id( 'inv-enc-placeholder-1' );
		$this->assertNotNull( $stored );
		$this->assertSame(
			Redactor::SENTINEL,
			$stored['surfaces']['options'][ $option_name ],
			'Placeholder strategy must store sentinel string'
		);

		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'inv-enc-placeholder-1',
				'ability_name'  => 'enc-redact-test/placeholder-partial',
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
		$result   = $rollback->rollback( 'inv-enc-placeholder-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'abilityguard_rollback_partial', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'skipped_keys', $data );
		$this->assertContains( "options.{$option_name}", $data['skipped_keys'] );

		// Option must NOT have been restored.
		$this->assertSame( 'mutated-value', get_option( $option_name ) );
	}
}
