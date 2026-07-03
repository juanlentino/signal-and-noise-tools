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
		. '<p class="sn-uw-head">' . esc_html__( 'Uptime', 'signal-noise-tools' ) . '</p>'
		. sn_uptime_status_mount_html()
		. '</div>';
}

/**
 * Dashboard-home assets. Gated on a configured token since v8.3.0 — with
 * no token there is no mount, so shipping the JS/CSS would be two wasted
 * requests on every admin login. The SN admin pages (Webhooks-tab rail)
 * get the same handles from the shared enqueue in inc/admin-menu.php.
 *
 * @param string $hook Admin page hook suffix.
 */
function sn_uptime_status_widget_enqueue( $hook ) {
	if ( 'index.php' !== $hook || ! sn_uptime_status_configured() ) {
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
