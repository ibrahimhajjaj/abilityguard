<?php
/**
 * Tools → AbilityGuard admin page.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Admin;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Audit\LogRepository;

/**
 * Registers the wp-admin Tools → AbilityGuard page and enqueues the
 * single-page React app that powers it.
 *
 * The page is intentionally minimal on the PHP side. We render an empty
 * mount node and inject a JSON payload (`window.AbilityGuardData`) that the
 * client uses to render the initial list. All mutations happen via the REST
 * controller in src/Admin/RestController.php.
 */
final class AdminMenu {

	private const HOOK_SUFFIX_OPTION = 'abilityguard_admin_hook';

	/**
	 * Capability required to view the admin page.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Page slug used in admin.php?page=...
	 */
	public const PAGE_SLUG = 'abilityguard';

	/**
	 * Hook into wp-admin.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Add the Tools → AbilityGuard submenu.
	 */
	public static function register_menu(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'AbilityGuard', 'abilityguard' ),
			__( 'AbilityGuard', 'abilityguard' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( false === $hook ) {
			return;
		}
		update_option( self::HOOK_SUFFIX_OPTION, $hook, false );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the CSS + JS only on our page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		$ours = (string) get_option( self::HOOK_SUFFIX_OPTION, '' );
		if ( '' === $ours || $hook !== $ours ) {
			return;
		}

		$assets_url = ABILITYGUARD_URL . 'assets/';
		$assets_dir = ABILITYGUARD_DIR . 'assets/';

		$css_path = $assets_dir . 'abilityguard.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ABILITYGUARD_VERSION;
		wp_enqueue_style( 'abilityguard-admin', $assets_url . 'abilityguard.css', array(), $css_ver );

		$js_path = $assets_dir . 'admin.js';
		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_build_missing_notice' ) );
			return;
		}
		$js_ver = (string) filemtime( $js_path );
		wp_enqueue_script( 'abilityguard-admin', $assets_url . 'admin.js', array(), $js_ver, true );

		$payload = self::build_boot_payload();
		wp_add_inline_script(
			'abilityguard-admin',
			'window.AbilityGuardData = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
	}

	/**
	 * Show a notice if the admin bundle hasn't been built yet.
	 */
	public static function render_build_missing_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'AbilityGuard admin bundle not built. Run `npm install && npm run build` from the plugin directory.', 'abilityguard' );
		echo '</p></div>';
	}

	/**
	 * Render the admin page shell. The React app takes it from here.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'abilityguard' ) );
		}
		echo '<div class="wrap abilityguard-wrap"><div id="abilityguard-root"></div></div>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var c=document.getElementById("wpcontent");if(c){c.classList.add("abilityguard-fullbleed")}});</script>';
		echo '<style>
			.abilityguard-wrap{margin:0;padding:0;}
			.abilityguard-wrap > #wpfooter,#wpfooter{display:none;}
			body.toplevel_page_abilityguard #wpcontent,body.tools_page_abilityguard #wpcontent{padding-left:0;}
			.abilityguard-wrap .ag-root{margin-left:-20px;margin-right:-20px;}
			.ag-approval-flash{box-shadow:0 0 0 2px var(--accent, #ff9b06) !important;background:rgba(255,155,6,0.08) !important;}
		</style>';
	}

	/**
	 * Build the initial JSON payload the React app reads on boot.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_boot_payload(): array {
		$repo = new LogRepository();
		$rows = $repo->list( array( 'per_page' => 200 ) );

		$enriched = array_map(
			static function ( array $row ): array {
				$user_display = '';
				if ( ! empty( $row['user_id'] ) ) {
					$user = get_userdata( (int) $row['user_id'] );
					if ( $user ) {
						$user_display = '' !== $user->display_name ? $user->display_name : $user->user_login;
					}
				}
				$row['user_display_name'] = $user_display;
				return $row;
			},
			$rows
		);

		return array(
			'rows' => $enriched,
			'rest' => array(
				'url'   => esc_url_raw( rest_url( 'abilityguard/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
		);
	}
}
