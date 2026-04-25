<?php
/**
 * Stubs for WP_CLI\Utils helpers used by AbilityGuard\Cli\Command.
 *
 * @package AbilityGuard
 */

// phpcs:disable

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
	/**
	 * @param string                                 $format
	 * @param array<int, array<string, mixed>>       $items
	 * @param array<int, string>|string              $fields
	 */
	function format_items( string $format, array $items, $fields ): void {}
}
