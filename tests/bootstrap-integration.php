<?php
/**
 * Integration bootstrap: loads WP core's test suite via wp-phpunit.
 *
 * Runs inside wp-env's tests-cli container where WP + MySQL are live.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Could not find WP test suite at {$_tests_dir}. Start wp-env first.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$abilities_api = WP_PLUGIN_DIR . '/abilities-api/abilities-api.php';
		if ( is_readable( $abilities_api ) ) {
			require_once $abilities_api;
		}
		require dirname( __DIR__ ) . '/abilityguard.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
