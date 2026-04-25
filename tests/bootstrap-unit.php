<?php
/**
 * Bootstrap for the unit suite. Uses Brain Monkey for WP function mocks.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

// Load abilities-api + wp-core stubs for classes referenced by src/.
require_once dirname( __DIR__ ) . '/stubs/abilities-api.php';

// Constants that src/ expects.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ABILITYGUARD_VERSION' ) ) {
	define( 'ABILITYGUARD_VERSION', '0.1.0-dev' );
}
