<?php
/**
 * Plugin Name:       AbilityGuard
 * Plugin URI:        https://github.com/ibrahimhajjaj/abilityguard
 * Description:       Snapshot, audit, and rollback middleware for the WordPress Abilities API.
 * Version:           1.0.0-dev
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  abilities-api
 * Network:           true
 * Author:            Ibrahim Hajjaj
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abilityguard
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'ABILITYGUARD_VERSION', '1.0.0-dev' );
define( 'ABILITYGUARD_FILE', __FILE__ );
define( 'ABILITYGUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABILITYGUARD_URL', plugin_dir_url( __FILE__ ) );

$abilityguard_autoload = ABILITYGUARD_DIR . 'vendor/autoload.php';
if ( is_readable( $abilityguard_autoload ) ) {
	require_once $abilityguard_autoload;
}

register_activation_hook( __FILE__, array( \AbilityGuard\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AbilityGuard\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \AbilityGuard\Plugin::class, 'boot' ) );
