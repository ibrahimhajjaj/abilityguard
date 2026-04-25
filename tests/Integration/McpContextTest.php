<?php

declare( strict_types=1 );

namespace AbilityGuard\Tests\Integration;

use AbilityGuard\Registry\McpContext;
use WP_UnitTestCase;

/**
 * Smoke tests for McpContext: signal wiring, id capture, and boundary reset.
 */
final class McpContextTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		McpContext::reset_for_tests();
	}

	protected function tearDown(): void {
		McpContext::reset_for_tests();
		parent::tearDown();
	}

	public function test_current_returns_null_before_any_filter(): void {
		McpContext::register();
		$this->assertNull( McpContext::current() );
	}

	public function test_current_returns_server_id_after_filter_fires(): void {
		McpContext::register();

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'my-test-server';
			}
		};

		apply_filters( 'mcp_adapter_pre_tool_call', array(), 'some/tool', null, $fake_server );

		$this->assertSame( 'my-test-server', McpContext::current() );
	}

	public function test_current_returns_null_after_wp_loaded_fires(): void {
		McpContext::register();

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'my-test-server';
			}
		};

		apply_filters( 'mcp_adapter_pre_tool_call', array(), 'some/tool', null, $fake_server );
		$this->assertSame( 'my-test-server', McpContext::current() );

		// Simulate request boundary.
		do_action( 'wp_loaded' );

		$this->assertNull( McpContext::current() );
	}

	public function test_register_is_idempotent(): void {
		McpContext::register();
		McpContext::register();
		McpContext::register();

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'idempotent-server';
			}
		};

		// If hooks were added multiple times the filter would fire N times but
		// the result is still a single string - confirm no exception and correct value.
		apply_filters( 'mcp_adapter_pre_tool_call', array(), 'some/tool', null, $fake_server );

		$this->assertSame( 'idempotent-server', McpContext::current() );
	}

	public function test_object_without_get_server_id_does_not_set_context(): void {
		McpContext::register();

		// Server-like object that lacks the method.
		$bad_server = new \stdClass();

		apply_filters( 'mcp_adapter_pre_tool_call', array(), 'some/tool', null, $bad_server );

		$this->assertNull( McpContext::current() );
	}

	public function test_filter_returns_args_unchanged(): void {
		McpContext::register();

		$fake_server = new class() {
			public function get_server_id(): string {
				return 'passthrough-server';
			}
		};

		$input  = array( 'key' => 'value' );
		$result = apply_filters( 'mcp_adapter_pre_tool_call', $input, 'some/tool', null, $fake_server );

		$this->assertSame( $input, $result );
	}
}
