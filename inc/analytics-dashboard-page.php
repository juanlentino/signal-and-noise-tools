<?php
/**
 * Signal & Noise — the native WP Dashboard → Analytics page (v5.4.0).
 *
 * Registers a read-only analytics page under the WordPress Dashboard menu
 * (sidebar: Home · Updates · Analytics) via add_dashboard_page(), which is a
 * thin wrapper for add_submenu_page('index.php', …). The credential settings
 * stay in the plugin menu (Measurement → Analytics) — this page has no forms, so
 * it never touches the page-slug-gated admin-post handler.
 *
 * add_dashboard_page()'s capability only gates MENU VISIBILITY; the callback URL
 * (index.php?page=sn-analytics) is directly reachable, so the render callback
 * re-checks current_user_can() itself (verified against WP core source:
 * add_submenu_page registers the page regardless, the cap is enforced by the
 * menu walker only).
 *
 * The returned hook suffix is appended to sn_admin_page_hooks() so the SN
 * admin.css/js enqueue guard (inc/admin-menu.php) loads assets on this page too.
 *
 * @package SignalNoiseTools
 * @since 5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Dashboard → Analytics submenu page and record its hook suffix.
 *
 * Hooked at admin_menu priority 11 — AFTER the main SN menu (default priority
 * 10) has populated sn_admin_page_hooks() — so this APPENDS its hook rather than
 * clobbering the collected list (sn_admin_page_hooks() replaces on set).
 */
function snt_analytics_register_dashboard_page() {
	$hook = add_dashboard_page(
		__( 'Analytics', 'signal-and-noise-tools' ),
		__( 'Analytics', 'signal-and-noise-tools' ),
		'manage_options',
		'sn-analytics',
		'snt_analytics_dashboard_page'
	);

	// add_submenu_page() returns false when the user lacks the capability; only
	// append a real (string) hook suffix.
	if ( $hook && function_exists( 'sn_admin_page_hooks' ) ) {
		sn_admin_page_hooks( array_merge( sn_admin_page_hooks(), array( $hook ) ) );
	}
}
add_action( 'admin_menu', 'snt_analytics_register_dashboard_page', 11 );

/**
 * Render callback for Dashboard → Analytics: the read-only dashboard wrapped in
 * the standard admin page chrome. Re-checks the capability (menu visibility
 * isn't access control), wraps in .wrap + an <h1>, resolves any ?sn_flash notice
 * (so a save on the settings page can redirect here with feedback), then
 * delegates the body to snt_analytics_render_dashboard().
 */
function snt_analytics_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'signal-and-noise-tools' ) );
	}

	echo '<div class="wrap">';
	echo '<h1>Analytics</h1>';

	if ( isset( $_GET['sn_flash'] ) && function_exists( 'sn_admin_flash_to_notice' ) ) {
		$notice = sn_admin_flash_to_notice( sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) ) );
		if ( is_array( $notice ) && isset( $notice[0], $notice[1] ) ) {
			// nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- the request value is sanitize_text_field'd, mapped through sn_admin_flash_to_notice()'s fixed table, and both halves are escaped at the sink (esc_attr / wp_kses_post). The rule cannot see the sink-side escaping.
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . wp_kses_post( $notice[1] ) . '</p></div>';
		}
	}

	if ( function_exists( 'snt_analytics_render_dashboard' ) ) {
		snt_analytics_render_dashboard();
	}

	// v8.4.2: the Better Stack uptime monitor moved OFF this tail append —
	// it renders as a postbox on the snt_analytics_after_overview seam
	// inside the dashboard body (inc/uptime-status-widget.php), so it sits
	// under Overview instead of below the fold.

	echo '</div>';
}
