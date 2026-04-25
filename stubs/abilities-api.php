<?php
/**
 * Type stubs so static analysis works without loading the real plugin.
 *
 * Signatures mirror WordPress/abilities-api as of 2026-04-24.
 *
 * @package AbilityGuard
 */

// phpcs:disable

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * @param string               $name
	 * @param array<string, mixed> $args
	 * @return \WP_Ability|null
	 */
	function wp_register_ability( string $name, array $args ) {}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * @param string $name
	 * @return \WP_Ability|null
	 */
	function wp_get_ability( string $name ) {}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @param string|int $code
		 *  @param string     $message
		 *  @param mixed      $data */
		public function __construct( $code = '', string $message = '', $data = null ) {}
		public function get_error_code(): string { return ''; }
		public function get_error_message(): string { return ''; }
	}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
		public string $name;
		/** @param mixed $input */
		public function execute( $input = null ) {}
		/** @param mixed $input */
		public function check_permissions( $input = null ) {}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @param string $msg */
		public static function error( string $msg ) {}
		/** @param string $msg */
		public static function success( string $msg ) {}
		/** @param string $msg
		 *  @param array<string, mixed> $assoc_args */
		public static function confirm( string $msg, array $assoc_args = array() ) {}
		/** @param string $name
		 *  @param callable|string|array<int, mixed> $cb
		 *  @param array<string, mixed> $args */
		public static function add_command( string $name, $cb, array $args = array() ) {}
	}
}
