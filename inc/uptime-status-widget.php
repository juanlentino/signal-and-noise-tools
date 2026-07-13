<?php
/**
 * Uptime section of the "S&N Health" dashboard widget + the status-panel
 * asset enqueues (v8.3.0).
 *
 * v8.2.0 shipped this as a standalone "S&N Uptime" widget; v8.3.0 folds it
 * into the S&N Health widget (owner call, 2026-07-02: one "is everything
 * okay" surface instead of a fifth dashboard box). The standalone
 * wp_add_dashboard_widget registration is REMOVED — removal guards in
 * tests/uptime-status-widget.php keep it removed. The Connections →
 * Webhooks rail panel is unchanged.
 *
 * Discipline unchanged from v8.2.0: renders are ZERO-COST (index.php
 * renders on every admin login, so nothing here ever performs a remote
 * call). The section prints an instant shell; assets/uptime-status.js
 * loads the data async through the readonly signal-noise/uptime-status
 * ability (sntAbilityRun → 90s snapshot + 1h availability transients in
 * inc/uptime-status.php).
 *
 * Unconfigured installs render NOTHING (empty string): the section simply
 * doesn't exist rather than prompting — the token field on Connections →
 * Webhooks documents the feature, and the Health widget shouldn't carry
 * an ad for it.
 *
 * @package SignalNoiseTools
 * @since 8.2.0 (standalone widget), 8.3.0 (folded into S&N Health)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Uptime section appended to the S&N Health widget render — see
 * sn_site_health_widget_render_full() in inc/site-health-widget.php (the
 * inner health render keeps its early-return states, so the section is
 * appended by the registered wrapper, never inline).
 *
 * @return string Section HTML, or '' when no token is configured.
 */
function sn_uptime_status_health_section() {
	if ( ! sn_uptime_status_configured() ) {
		return '';
	}
	return '<div class="sn-uw-section">'
		. '<p class="sn-uw-head">' . esc_html__( 'Uptime', 'signal-and-noise-tools' ) . '</p>'
		. sn_uptime_status_mount_html()
		. '</div>';
}

/**
 * The rail Uptime card (v9.37.0 D1 §5c: the ONE uptime surface). A native
 * <details>: summary = the status tier (monitor names + UP/DOWN pills, painted
 * async into [data-sn-uptime-status]); expansion = the detail table + incident
 * log, fetched lazily on FIRST open into [data-sn-uptime-lazy-detail] (see
 * assets/uptime-status.js). Replaces the old strip + full-width detail postbox
 * pair. Empty string when unconfigured — the rail degrades to movers-only.
 *
 * @return string Panel HTML ('' unconfigured).
 */
function sn_uptime_status_rail_strip() {
	if ( ! sn_uptime_status_configured() ) {
		return '';
	}
	ob_start();
	snt_an_panel_open( __( 'Uptime', 'signal-and-noise-tools' ), array(
		'panel_class' => 'sn-an-rail-tile sn-uptime-strip',
	) );
	echo '<details class="sn-an-uptime">';
	echo '<summary><span class="sn-uptime-status" data-sn-uptime-status>'
		. '<span class="sn-uw-loading">' . esc_html__( 'Checking Better Stack…', 'signal-and-noise-tools' ) . '</span>'
		. '</span></summary>';
	echo '<div class="sn-uptime-status" data-sn-uptime-lazy-detail>'
		. '<p class="sn-uw-loading">' . esc_html__( 'Loading monitor detail…', 'signal-and-noise-tools' ) . '</p>'
		. '</div>';
	echo '</details>';
	snt_an_panel_close();
	return (string) ob_get_clean();
}

/**
 * Panel assets for the two Dashboard-menu surfaces: the home dashboard
 * (S&N Health widget section) and the Analytics page (monitor section).
 * Gated on a configured token — with no token there is no mount, so
 * shipping the JS/CSS would be wasted requests. The SN admin pages
 * (Webhooks-tab rail) get the same handles from the shared enqueue in
 * inc/admin-menu.php.
 *
 * @param string $hook Admin page hook suffix.
 */
function sn_uptime_status_widget_enqueue( $hook ) {
	$surfaces = array( 'index.php', 'dashboard_page_sn-analytics' );
	if ( ! in_array( $hook, $surfaces, true ) || ! sn_uptime_status_configured() ) {
		return;
	}
	sn_uptime_status_enqueue_assets();
}
add_action( 'admin_enqueue_scripts', 'sn_uptime_status_widget_enqueue' );

/**
 * Shared handle registration for the panel JS + CSS. Dep on
 * snt-ability-run keeps the ONE ability transport rule.
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
