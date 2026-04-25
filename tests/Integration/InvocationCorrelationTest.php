<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Registry\AbilityWrapper;
use AbilityGuard\Registry\InvocationStack;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Tests for v0.6 correlation features:
 *   - safety.skip_drift_check shorthand persists to log_meta automatically.
 *   - parent_invocation_id chains nested ability calls.
 *   - files-changed-on-rollback surfaces into log_meta during rollback.
 */
final class InvocationCorrelationTest extends WP_UnitTestCase {

	/**
	 * Monotonic counter so each test gets a unique ability name.
	 */
	private static int $counter = 0;

	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		InvocationStack::reset();
	}

	protected function tearDown(): void {
		InvocationStack::reset();
		parent::tearDown();
	}

	/**
	 * Build a fresh wrapper, invoke it, return [log_id, invocation_id].
	 *
	 * @param array<string, mixed> $safety Safety config.
	 * @param callable             $cb     Inner callback.
	 *
	 * @return array{log_id: int, invocation_id: string, row: array<string, mixed>}
	 */
	private function invoke( array $safety, callable $cb ): array {
		++self::$counter;
		$ability = 'corr-test/ability-' . self::$counter;
		$wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$ability,
			$safety
		);
		$wrapped = $wrapper->wrap( $cb );
		$wrapped( null );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability ) );
		$this->assertNotEmpty( $rows, "Log row missing for {$ability}" );
		return array(
			'log_id'        => (int) $rows[0]['id'],
			'invocation_id' => (string) $rows[0]['invocation_id'],
			'row'           => $rows[0],
		);
	}

	public function test_skip_drift_check_shorthand_writes_log_meta(): void {
		$info = $this->invoke(
			array(
				'destructive'      => false,
				'skip_drift_check' => true,
			),
			static fn() => array( 'ok' => true )
		);

		$values = LogMeta::get_all( $info['log_id'], 'skip_drift_check' );
		$this->assertSame( array( '1' ), $values );
	}

	public function test_skip_drift_check_absent_writes_no_meta(): void {
		$info = $this->invoke(
			array( 'destructive' => false ),
			static fn() => array( 'ok' => true )
		);
		$this->assertSame( array(), LogMeta::get_all( $info['log_id'], 'skip_drift_check' ) );
	}

	public function test_parent_invocation_id_links_nested_calls(): void {
		++self::$counter;
		$inner_ability = 'corr-test/inner-' . self::$counter;
		$inner_wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$inner_ability,
			array( 'destructive' => false )
		);
		$inner         = $inner_wrapper->wrap( static fn() => 'inner-ok' );

		++self::$counter;
		$outer_ability = 'corr-test/outer-' . self::$counter;
		$outer_wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$outer_ability,
			array( 'destructive' => false )
		);
		$outer         = $outer_wrapper->wrap(
			static function () use ( $inner ) {
				$inner( null );
				return 'outer-ok';
			}
		);

		$outer( null );

		$repo       = new LogRepository();
		$outer_rows = $repo->list( array( 'ability_name' => $outer_ability ) );
		$inner_rows = $repo->list( array( 'ability_name' => $inner_ability ) );

		$this->assertNotEmpty( $outer_rows );
		$this->assertNotEmpty( $inner_rows );

		$outer_row = $outer_rows[0];
		$inner_row = $inner_rows[0];

		$this->assertNull( $outer_row['parent_invocation_id'], 'top-level invocation has no parent' );
		$this->assertSame(
			$outer_row['invocation_id'],
			$inner_row['parent_invocation_id'],
			'nested invocation must link to its parent'
		);
	}

	public function test_nested_call_inherits_parent_lock_instead_of_blocking(): void {
		// Same surface set on outer + inner → lock keys collide. With
		// the inner call must detect parent_invocation_id and inherit the
		// already-held lock instead of failing with abilityguard_lock_timeout.
		$option_name = 'ag_lock_reentry_test_' . self::$counter;
		update_option( $option_name, 'before' );

		++self::$counter;
		$inner_ability = 'corr-test/lock-inner-' . self::$counter;
		$inner_wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$inner_ability,
			array(
				'destructive' => true,
				'snapshot'    => array( 'options' => array( $option_name ) ),
			)
		);
		$inner = $inner_wrapper->wrap( static fn() => 'inner-ok' );

		++self::$counter;
		$outer_ability = 'corr-test/lock-outer-' . self::$counter;
		$outer_wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$outer_ability,
			array(
				'destructive' => true,
				'snapshot'    => array( 'options' => array( $option_name ) ),
			)
		);
		$outer = $outer_wrapper->wrap(
			static function () use ( $inner ) {
				$result = $inner( null );
				return is_wp_error( $result ) ? $result : 'outer-ok';
			}
		);

		$result = $outer( null );

		$this->assertSame( 'outer-ok', $result, 'outer must return its own value, meaning inner did not WP_Error on the lock' );

		delete_option( $option_name );
	}

	public function test_invocation_stack_pops_after_throw(): void {
		++self::$counter;
		$ability = 'corr-test/throw-' . self::$counter;
		$wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$ability,
			array( 'destructive' => false )
		);
		$wrapped = $wrapper->wrap(
			static function (): never {
				throw new \RuntimeException( 'boom' );
			}
		);

		try {
			$wrapped( null );
			$this->fail( 'expected throw' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertNull( InvocationStack::current(), 'stack must be empty after a throw' );
	}

	public function test_files_changed_on_rollback_surfaces_to_log_meta(): void {
		// Stage a real tmp file we can mutate to create file drift.
		$path = tempnam( sys_get_temp_dir(), 'ag_corr_files_' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'original' );

		++self::$counter;
		$ability = 'corr-test/files-' . self::$counter;
		$wrapper = new AbilityWrapper(
			new SnapshotService( new SnapshotStore() ),
			new AuditLogger(),
			$ability,
			array(
				'destructive' => true,
				'snapshot'    => array(
					'files' => array( $path ),
				),
			)
		);
		$wrapped = $wrapper->wrap( static fn() => 'ok' );
		$wrapped( null );

		// Mutate file AFTER the invocation - this is third-party drift the
		// rollback should surface.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'drifted-content' );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability ) );
		$this->assertNotEmpty( $rows );
		$log_id = (int) $rows[0]['id'];

		$rollback = new RollbackService( new LogRepository(), new SnapshotStore() );
		$result   = $rollback->rollback( $log_id, true );
		$this->assertTrue( true === $result || is_wp_error( $result ) );

		$values = LogMeta::get_all( $log_id, 'files_changed_on_rollback' );
		$this->assertNotEmpty( $values, 'files_changed_on_rollback meta must be written' );
		$decoded = json_decode( (string) $values[0], true );
		$this->assertIsArray( $decoded );
		$this->assertContains( $path, $decoded );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $path );
	}
}
