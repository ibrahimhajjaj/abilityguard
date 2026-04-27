<?php
/**
 * Dashboard widget showing AbilityGuard stats in wp-admin.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Admin;

defined( 'ABSPATH' ) || exit;

use AbilityGuard\Audit\LogRepository;

/**
 * Tiny "At a glance" style widget rendered at /wp-admin/index.php.
 *
 * The full admin app lives at Tools → AbilityGuard. This widget exists so
 * site owners see the ability-execution surface (counts, p50/p95, hot
 * abilities) the moment they hit the dashboard, without an extra click.
 *
 * Data is fetched server-side via LogRepository::stats() to avoid an extra
 * REST round-trip on every dashboard render. The same numbers are also
 * available at GET /abilityguard/v1/stats for external consumers (CI,
 * monitoring, or the WordPress/ai PR #437 reviewers who asked for a
 * stats artefact at the ability layer).
 */
final class DashboardWidget {

	private const WIDGET_ID = 'abilityguard_stats';

	/**
	 * Hook into wp-admin.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the widget with WordPress.
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'AbilityGuard', 'abilityguard' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		$stats   = ( new LogRepository() )->stats();
		$counts  = isset( $stats['counts'] ) && is_array( $stats['counts'] ) ? $stats['counts'] : array();
		$timings = isset( $stats['timings_ms'] ) && is_array( $stats['timings_ms'] ) ? $stats['timings_ms'] : array();
		$top     = isset( $stats['top_abilities'] ) && is_array( $stats['top_abilities'] ) ? $stats['top_abilities'] : array();

		$tile_keys = array(
			'pending'     => __( 'Pending', 'abilityguard' ),
			'approved'    => __( 'Approved', 'abilityguard' ),
			'rejected'    => __( 'Rejected', 'abilityguard' ),
			'ok'          => __( 'OK', 'abilityguard' ),
			'error'       => __( 'Error', 'abilityguard' ),
			'rolled_back' => __( 'Rolled back', 'abilityguard' ),
		);

		$page_url = admin_url( 'tools.php?page=' . AdminMenu::PAGE_SLUG );

		echo '<div class="abilityguard-dashboard-widget">';

		echo '<table class="widefat striped" style="margin-bottom:8px;"><tbody><tr>';
		foreach ( $tile_keys as $key => $label ) {
			$value = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			echo '<td style="text-align:center;padding:6px 4px;">';
			echo '<div style="font-size:18px;font-weight:600;">' . esc_html( (string) $value ) . '</div>';
			echo '<div style="font-size:11px;color:#646970;">' . esc_html( $label ) . '</div>';
			echo '</td>';
		}
		echo '</tr></tbody></table>';

		$p50 = isset( $timings['p50'] ) && null !== $timings['p50'] ? (int) $timings['p50'] : null;
		$p95 = isset( $timings['p95'] ) && null !== $timings['p95'] ? (int) $timings['p95'] : null;
		echo '<p style="margin:4px 0;">';
		echo '<strong>' . esc_html__( 'Duration', 'abilityguard' ) . ':</strong> ';
		echo 'p50 ' . esc_html( null === $p50 ? '-' : $p50 . 'ms' ) . ' · ';
		echo 'p95 ' . esc_html( null === $p95 ? '-' : $p95 . 'ms' );
		echo '</p>';

		if ( array() !== $top ) {
			echo '<p style="margin:8px 0 4px;"><strong>' . esc_html__( 'Top abilities', 'abilityguard' ) . '</strong></p>';
			echo '<ol style="margin:0 0 0 18px;padding:0;">';
			foreach ( $top as $row ) {
				$name  = isset( $row['name'] ) ? (string) $row['name'] : '';
				$count = isset( $row['count'] ) ? (int) $row['count'] : 0;
				if ( '' === $name ) {
					continue;
				}
				echo '<li style="margin:0;"><code>' . esc_html( $name ) . '</code> · ' . esc_html( (string) $count ) . '</li>';
			}
			echo '</ol>';
		} else {
			echo '<p style="color:#646970;"><em>' . esc_html__( 'No invocations yet.', 'abilityguard' ) . '</em></p>';
		}

		echo '<p style="margin-top:10px;"><a href="' . esc_url( $page_url ) . '">' . esc_html__( 'Open AbilityGuard →', 'abilityguard' ) . '</a></p>';
		echo '</div>';
	}
}
