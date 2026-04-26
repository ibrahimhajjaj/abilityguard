<?php
/**
 * MCP client/server identity context.
 *
 * Captures the originating MCP server id when an ability is invoked
 * through the mcp-adapter route, and clears it after the request.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Thread-local (request-scoped) holder for the active MCP server id.
 *
 * Signal used: the `mcp_adapter_pre_tool_call` filter fired by
 * `WP\MCP\Handlers\Tools\ToolsHandler::call_tool()` immediately before
 * the underlying ability executes. It passes the `McpServer` instance as
 * its fourth argument, from which we read `get_server_id()`.
 *
 * The filter runs inside the same PHP call-stack as
 * `ExecuteAbilityAbility::execute()`, which in turn calls
 * `$ability->execute()` - so `McpContext::current()` is non-null
 * exactly while `AbilityWrapper::wrap()` is executing.
 *
 * We reset via `wp_loaded` (priority 999) which fires after all REST
 * callbacks complete, giving us a clean slate between requests in
 * long-running processes (WP-CLI server, unit tests, etc.).
 */
final class McpContext {

	/**
	 * Current MCP server id or null when not inside an MCP invocation.
	 *
	 * @var string|null
	 */
	private static ?string $current_id = null;

	/**
	 * Whether hooks have already been wired.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Return the current MCP server id, or null when not in MCP context.
	 */
	public static function current(): ?string {
		return self::$current_id;
	}

	/**
	 * Wire the hooks that set and clear the context.
	 *
	 * Idempotent - safe to call more than once (e.g. from tests).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		/*
		 * Set the context: fires just before the tool's ability executes.
		 *
		 * Signature: apply_filters(
		 *   'mcp_adapter_pre_tool_call',
		 *   array $args,
		 *   string $tool_name,
		 *   McpTool $mcp_tool,
		 *   McpServer $server
		 * )
		 *
		 * @see WP\MCP\Handlers\Tools\ToolsHandler::call_tool() line 182
		 */
		add_filter(
			'mcp_adapter_pre_tool_call',
			static function ( $args, string $tool_name, $mcp_tool, $server ) {
				if ( method_exists( $server, 'get_server_id' ) ) {
					self::$current_id = $server->get_server_id();
				}
				return $args;
			},
			10,
			4
		);

		/*
		 * Clear the context at the end of the request so that long-running
		 * processes (WP-CLI server, test runner) start clean on the next request.
		 */
		add_action(
			'wp_loaded',
			static function (): void {
				self::$current_id = null;
			},
			999
		);
	}

	/**
	 * Reset internal state. Test-only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$current_id = null;
		self::$registered = false;
	}
}
