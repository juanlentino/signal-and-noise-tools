<?php
/**
 * "S&N Uptime" dashboard widget — Better Stack monitor + heartbeat states
 * at a glance on the WP home dashboard, beside the Analytics, Login-defense,
 * and S&N Health widgets (v8.2.0, owner-requested in-admin status panel).
 *
 * Zero-cost on render, same discipline as inc/site-health-widget.php:
 * index.php renders on every admin login, so this render NEVER performs a
 * remote call. It prints an instant shell; assets/uptime-status.js loads
 * the data async through the readonly signal-noise/uptime-status ability
 * (sntAbilityRun → 90s server-side transient in inc/uptime-status.php).
 *
 * Unconfigured state renders a settings prompt instead of the mount — the
 * configured check is one option read, and shipping JS that would only
 * learn "configured:false" is a wasted round trip.
 *
 * @package SignalNoiseTools
 * @since 8.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'sn_uptime_status_widget_register' );

/**
 * Register the widget. manage_options only — uptime posture and the token
 * settings link are admin-actionable, same gate as the Health widget.
 */
function sn_uptime_status_widget_register() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'sn_uptime_status', __( 'S&N Uptime', 'signal-noise-tools' ), 'sn_uptime_status_widget_render' );
}

/**
 * Render the shell. Two states: unconfigured (settings prompt) or the
 * async mount that uptime-status.js populates. No HTTP either way.
 */
function sn_uptime_status_widget_render() {
	$settings_url = admin_url( 'admin.php?page=sn-connections&sub=webhooks' );

	if ( ! sn_uptime_status_configured() ) {
		echo '<p class="sn-uw-loading">' . esc_html__( 'Not connected yet.', 'signal-noise-tools' ) . '</p>';
		echo '<p class="sn-aw-foot"><a href="' . esc_url( $settings_url ) . '">'
			. esc_html__( 'Add your Better Stack API token under Uptime monitoring', 'signal-noise-tools' )
			. '</a></p>';
		return;
	}

	echo sn_uptime_status_mount_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static helper markup, escaped at build.
}

/**
 * Assets for every mount surface. Dashboard home gets them here; the SN
 * admin pages (Webhooks-tab rail) get the same handles from the shared
 * enqueue in inc/admin-menu.php. Dep on snt-ability-run keeps the ONE
 * ability transport rule.
 *
 * @param string $hook Admin page hook suffix.
 */
function sn_uptime_status_widget_enqueue( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}
	sn_uptime_status_enqueue_assets();
}
add_action( 'admin_enqueue_scripts', 'sn_uptime_status_widget_enqueue' );

/**
 * Shared handle registration for the panel JS + CSS (called from the
 * dashboard gate above and from the SN-pages enqueue in admin-menu.php).
 */
function sn_uptime_status_enqueue_assets() {
	wp_enqueue_style(
		'sn-uptime-status',
		SNT_URL . 'assets/uptime-status.css',
		array(),
		SNT_VERSION
	);
	wp_enqueue_script(
		'sn-uptime-status',
		SNT_URL . 'assets/uptime-status.js',
		array( 'snt-ability-run' ),
		SNT_VERSION,
		true
	);
}
