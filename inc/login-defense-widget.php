<?php
/**
 * Login defense dashboard widget: at-a-glance attack stats on the WP home
 * dashboard + a link to the full Analytics-dashboard Login defense view. Owner-requested
 * (the sanctioned exception to the no-new-widgets line). Mirrors the grandfathered
 * inc/analytics-widget.php registration. Read-only.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'sn_login_defense_widget_register' );

/**
 * Register the widget (same capability gate as inc/analytics-widget.php).
 */
function sn_login_defense_widget_register() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'sn_login_defense', __( 'Login defense', 'signal-and-noise-tools' ), 'sn_login_defense_widget_render' );
}

/**
 * Render the glance: blocked (7d), block rate, top attacker network + a link to
 * the full view. Dormant-gates when Cloudflare Analytics is not connected.
 */
function sn_login_defense_widget_render() {
	$h = function_exists( 'sn_login_defense_headline' ) ? sn_login_defense_headline() : array( 'configured' => false );
	if ( empty( $h['configured'] ) ) {
		echo '<p>' . esc_html__( 'Connect Cloudflare Analytics to see login defense stats.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<ul class="sn-lg-widget">';
	echo '<li><strong>' . esc_html( number_format_i18n( (int) $h['blocked'] ) ) . '</strong> '
		. esc_html__( 'blocked (7d)', 'signal-and-noise-tools' ) . '</li>';
	echo '<li><strong>' . esc_html( (int) $h['block_rate'] ) . '%</strong> '
		. esc_html__( 'block rate', 'signal-and-noise-tools' ) . '</li>';
	if ( '' !== (string) $h['top_network'] ) {
		echo '<li>' . esc_html__( 'Top network:', 'signal-and-noise-tools' )
			. ' <strong>' . esc_html( $h['top_network'] ) . '</strong></li>';
	}
	echo '</ul>';
	echo '<p><a href="' . esc_url( admin_url( 'index.php?page=sn-analytics&sn_view=login-defense' ) ) . '">'
		. esc_html__( 'View login defense', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
}
