<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Audit\AuditLogger;
use AbilityGuard\Audit\LogRepository;
use AbilityGuard\Installer;
use AbilityGuard\Registry\McpContext;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_UnitTestCase;

/**
 * End-to-end identity attribution tests.
 *
 * Verifies that:
 *  1. Abilities invoked under a simulated MCP context record caller_type='mcp'
 *     and caller_id equal to the server id.
 *  2. Abilities invoked directly record the standard caller_type and NULL caller_id.
 *  3. Schema migration (double install) keeps existing rows intact and the
 *     caller_id column exists.
 *  4. caller_id filter in LogRepository::list() works correctly.
 */
final class McpIdentityTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'abilities-api plugin not loaded' );
		}

		Installer::install();
		McpContext::reset_for_tests();
		McpContext::register();
		$this->ensure_test_category();
	}

	protected function tearDown(): void {
		McpContext::reset_for_tests();
		parent::tearDown();
	}

	private function ensure_test_category(): void {
		if ( wp_has_ability_category( 'abilityguard-mcp-tests' ) ) {
			return;
		}
		$cb = static function ( $registry ): void {
			$registry->register(
				'abilityguard-mcp-tests',
				array(
					'label'       => 'AbilityGuard MCP tests',
					'description' => 'Category for AbilityGuard MCP integration tests.',
				)
			);
		};
		add_action( 'wp_abilities_api_categories_init', $cb );
		WP_Ability_Categories_Registry::get_instance();
		do_action( 'wp_abilities_api_categories_init', WP_Ability_Categories_Registry::get_instance() );
		remove_action( 'wp_abilities_api_categories_init', $cb );
	}

	private function register_ability( string $name ): \WP_Ability {
		$registry = WP_Abilities_Registry::get_instance();
		$result   = $registry->register(
			$name,
			array(
				'label'               => 'Test ability',
				'description'         => 'Used by McpIdentityTest.',
				'category'            => 'abilityguard-mcp-tests',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn() => array( 'done' => true ),
				'meta'                => array(
					'annotations' => array( 'destructive' => false ),
				),
				'safety'              => array( 'snapshot' => array() ),
			)
		);

		if ( null === $result ) {
			$this->fail( "register() returned null for {$name}" );
		}

		return $result;
	}

	public function test_mcp_invocation_records_mcp_caller_type_and_caller_id(): void {
		$ability_name = 'abilityguard-mcp-tests/mcp-e2e-' . uniqid();
		$ability      = $this->register_ability( $ability_name );

		// Simulate the mcp_adapter_pre_tool_call filter firing before execute().
		$fake_server = new class() {
			public function get_server_id(): string {
				return 'test-mcp-server';
			}
		};
		apply_filters( 'mcp_adapter_pre_tool_call', array(), $ability_name, null, $fake_server );

		$ability->execute( null );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'mcp', $rows[0]['caller_type'] );
		$this->assertSame( 'test-mcp-server', $rows[0]['caller_id'] );
	}

	public function test_direct_invocation_records_standard_caller_type_and_null_caller_id(): void {
		$ability_name = 'abilityguard-mcp-tests/direct-e2e-' . uniqid();
		$ability      = $this->register_ability( $ability_name );

		// No MCP filter fires - context remains null.
		$this->assertNull( McpContext::current() );

		$ability->execute( null );

		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => $ability_name ) );

		$this->assertCount( 1, $rows );
		// In CLI/test context this will be 'internal' (no REST_REQUEST, no WP_CLI in test).
		$this->assertNotSame( 'mcp', $rows[0]['caller_type'] );
		$this->assertNull( $rows[0]['caller_id'] );
	}

	public function test_caller_id_filter_in_list(): void {
		$ability_name = 'abilityguard-mcp-tests/filter-e2e-' . uniqid();
		$ability      = $this->register_ability( $ability_name );

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'filterable-server';
			}
		};
		apply_filters( 'mcp_adapter_pre_tool_call', array(), $ability_name, null, $fake_server );
		$ability->execute( null );
		McpContext::reset_for_tests();

		$repo = new LogRepository();

		$found = $repo->list( array( 'caller_id' => 'filterable-server' ) );
		$this->assertGreaterThanOrEqual( 1, count( $found ) );

		$not_found = $repo->list( array( 'caller_id' => 'nonexistent-server' ) );
		$this->assertCount( 0, $not_found );
	}

	public function test_schema_migration_is_idempotent_and_caller_id_column_exists(): void {
		// Insert a row before the second install call.
		( new AuditLogger() )->log(
			array(
				'invocation_id' => 'mcp-migration-test-' . uniqid(),
				'ability_name'  => 'abilityguard-mcp-tests/migration',
				'caller_type'   => 'mcp',
				'caller_id'     => 'pre-migration-server',
				'user_id'       => 0,
				'args_json'     => null,
				'result_json'   => null,
				'status'        => 'ok',
				'destructive'   => false,
				'duration_ms'   => 1,
				'pre_hash'      => null,
				'post_hash'     => null,
				'snapshot_id'   => null,
			)
		);

		// Run install() a second time - must be idempotent.
		Installer::install();

		// Column must exist.
		global $wpdb;
		$table = Installer::table( 'log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$columns = array_column( $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ), 'Field' );
		$this->assertContains( 'caller_id', $columns );

		// Pre-migration row still intact.
		$repo = new LogRepository();
		$rows = $repo->list( array( 'ability_name' => 'abilityguard-mcp-tests/migration' ) );
		$this->assertGreaterThanOrEqual( 1, count( $rows ) );
		$this->assertSame( 'pre-migration-server', $rows[0]['caller_id'] );
	}
}
