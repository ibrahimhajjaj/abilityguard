<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\LogMeta;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Registry\InvocationStack;
use AbilityGuard\Rollback\RollbackService;
use AbilityGuard\Snapshot\SnapshotStore;
use WP_UnitTestCase;

/**
 * Tests for v0.6 correlation features:
 *   - safety.skip_drift_check shorthand persists to log_meta automatically.
 *   - parent_invocation_id chains nested ability calls.
 *   - files-changed-on-rollback surfaces into log_meta during rollback.
 */
final class InvocationCorrelationTest extends WP_UnitTestCase {

	use AbilityRegistrationTrait;

	/**
	 * Monotonic counter so each test gets a unique ability name.
	 */
	private static int $counter = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}
		Installer::install();
		InvocationStack::reset();
	}

	protected function tearDown(): void {
		InvocationStack::reset();
		parent::tearDown();
	}

	/**
	 * Register a single ability through the abilities-api flow, execute, and
	 * return the resulting log row + ids.
	 *
	 * @return array{log_id:int,invocation_id:string,row:array<string,mixed>}
	 */
	private function invoke( array $safety, callable $cb ): array {
		++self::$counter;
		$name = 'abilityguard-tests/corr-' . self::$counter;
		$row  = $this->execute_and_get_log_row( $name, $safety, $cb );
		return array(
			'log_id'        => (int) $row['id'],
			'invocation_id' => (string) $row['invocation_id'],
			'row'           => $row,
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
		$inner_name = 'abilityguard-tests/corr-inner-' . self::$counter;
		$this->ensure_test_category();
		$this->register_via_init(
			$inner_name,
			static fn() => array(
				'label'               => 'inner',
				'description'         => 'inner ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'inner-ok',
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array( 'destructive' => false ),
			)
		);

		++self::$counter;
		$outer_name = 'abilityguard-tests/corr-outer-' . self::$counter;
		$this->register_via_init(
			$outer_name,
			static fn() => array(
				'label'               => 'outer',
				'description'         => 'outer ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( $inner_name ) {
					wp_get_ability( $inner_name )->execute( null );
					return 'outer-ok';
				},
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array( 'destructive' => false ),
			)
		);

		wp_get_ability( $outer_name )->execute( null );

		$repo       = new LogRepository();
		$outer_rows = $repo->list( array( 'ability_name' => $outer_name ) );
		$inner_rows = $repo->list( array( 'ability_name' => $inner_name ) );

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
		// Same surface set on outer + inner, lock keys collide. The inner
		// call must detect parent_invocation_id and inherit the already-held
		// lock instead of failing with abilityguard_lock_timeout.
		$option_name = 'ag_lock_reentry_test_' . self::$counter;
		update_option( $option_name, 'before' );

		++self::$counter;
		$inner_name = 'abilityguard-tests/corr-lock-inner-' . self::$counter;
		$this->ensure_test_category();
		$this->register_via_init(
			$inner_name,
			static fn() => array(
				'label'               => 'inner-lock',
				'description'         => 'inner lock ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'inner-ok',
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => array( 'options' => array( $option_name ) ),
				),
			)
		);

		++self::$counter;
		$outer_name = 'abilityguard-tests/corr-lock-outer-' . self::$counter;
		$this->register_via_init(
			$outer_name,
			static fn() => array(
				'label'               => 'outer-lock',
				'description'         => 'outer lock ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( $inner_name ) {
					$result = wp_get_ability( $inner_name )->execute( null );
					return is_wp_error( $result ) ? $result : 'outer-ok';
				},
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array(
					'destructive' => true,
					'snapshot'    => array( 'options' => array( $option_name ) ),
				),
			)
		);

		$result = wp_get_ability( $outer_name )->execute( null );

		$this->assertSame( 'outer-ok', $result, 'outer must return its own value, meaning inner did not WP_Error on the lock' );

		delete_option( $option_name );
	}

	public function test_invocation_stack_pops_after_throw(): void {
		++self::$counter;
		$name = 'abilityguard-tests/corr-throw-' . self::$counter;
		$this->ensure_test_category();
		$this->register_via_init(
			$name,
			static fn() => array(
				'label'               => 'throws',
				'description'         => 'throws ability',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function (): never {
					throw new \RuntimeException( 'boom' );
				},
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array( 'destructive' => false ),
			)
		);

		try {
			wp_get_ability( $name )->execute( null );
			$this->fail( 'expected throw' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertNull( InvocationStack::current(), 'stack must be empty after a throw' );
	}

	public function test_mcp_invoked_ability_has_no_parent_but_nested_call_does(): void {
		// MCP-invoked top-level ability: caller_type=mcp, caller_id=server,
		// parent_invocation_id=null. Its inner call to another ability should
		// inherit parent_invocation_id from the MCP-invoked ability's
		// invocation_id and continue to record caller_type=mcp.
		\AbilityGuard\Registry\McpContext::reset_for_tests();
		\AbilityGuard\Registry\McpContext::register();

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'trial-mcp-server';
			}
		};
		apply_filters( 'mcp_adapter_pre_tool_call', array(), 'whatever', null, $fake_server );

		++self::$counter;
		$inner_name = 'abilityguard-tests/corr-mcp-inner-' . self::$counter;
		$this->ensure_test_category();
		$this->register_via_init(
			$inner_name,
			static fn() => array(
				'label'               => 'mcp-inner',
				'description'         => 'mcp inner',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => 'inner-ok',
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array( 'destructive' => false ),
			)
		);

		++self::$counter;
		$outer_name = 'abilityguard-tests/corr-mcp-outer-' . self::$counter;
		$this->register_via_init(
			$outer_name,
			static fn() => array(
				'label'               => 'mcp-outer',
				'description'         => 'mcp outer',
				'category'            => 'abilityguard-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( $inner_name ) {
					wp_get_ability( $inner_name )->execute( null );
					return 'outer-ok';
				},
				'input_schema'        => array( 'type' => array( 'object', 'null' ) ),
				'safety'              => array( 'destructive' => false ),
			)
		);

		wp_get_ability( $outer_name )->execute( null );

		$repo       = new LogRepository();
		$outer_rows = $repo->list( array( 'ability_name' => $outer_name ) );
		$inner_rows = $repo->list( array( 'ability_name' => $inner_name ) );

		$this->assertSame( 'mcp', $outer_rows[0]['caller_type'], 'outer call should record MCP caller_type' );
		$this->assertSame( 'trial-mcp-server', $outer_rows[0]['caller_id'], 'outer call should record server id' );
		$this->assertNull( $outer_rows[0]['parent_invocation_id'], 'top-level MCP call has no parent ability' );

		$this->assertSame( 'mcp', $inner_rows[0]['caller_type'], 'nested call inherits MCP caller_type - same request' );
		$this->assertSame(
			$outer_rows[0]['invocation_id'],
			$inner_rows[0]['parent_invocation_id'],
			'nested call must point to its MCP-invoked parent'
		);

		\AbilityGuard\Registry\McpContext::reset_for_tests();
	}

	public function test_files_changed_on_rollback_surfaces_to_log_meta(): void {
		// Stage a real tmp file we can mutate to create file drift.
		$path = tempnam( sys_get_temp_dir(), 'ag_corr_files_' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'original' );

		++self::$counter;
		$name = 'abilityguard-tests/corr-files-' . self::$counter;
		$this->execute_and_get_log_row(
			$name,
			array(
				'destructive' => true,
				'snapshot'    => array( 'files' => array( $path ) ),
			),
			static fn() => 'ok'
		);

		// Mutate file AFTER the invocation - third-party drift the rollback
		// should surface.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'drifted-content' );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $name ) );
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
