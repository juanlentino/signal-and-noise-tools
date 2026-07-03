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
 * The Uptime monitor panel for the Dashboard → Analytics page (v8.4.0,
 * owner call: the stats live where the numbers are reviewed; v8.4.2:
 * rendered as a proper postbox on the snt_analytics_after_overview seam,
 * directly under the Overview panel — it shipped as a bare section below
 * the fold and read as an afterthought). The mount carries BOTH hook
 * attributes — data-sn-uptime-status marks it as a paintable mount,
 * data-sn-uptime-detail upgrades the shared ability call to the detail
 * tier (availability windows + response times + incidents), so one call
 * still feeds every mount on the page. '' unconfigured; zero remote cost
 * on render, same as every other surface.
 *
 * @return string Panel HTML, or '' when no token is configured.
 */
function sn_uptime_status_analytics_section() {
	if ( ! sn_uptime_status_configured() ) {
		return '';
	}
	return '<div class="postbox sn-uptime-monitor">'
		. '<div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Uptime', 'signal-noise-tools' ) . '</span></h2></div>'
		. '<div class="inside">'
		. '<div class="sn-uptime-status" data-sn-uptime-status data-sn-uptime-detail>'
		. '<p class="sn-uw-loading">' . esc_html__( 'Checking Better Stack…', 'signal-noise-tools' ) . '</p>'
		. '</div>'
		. '</div>'
		. '</div>';
}

/**
 * Echo wrapper hooked on the Analytics dashboard's after-Overview seam.
 */
function sn_uptime_status_render_analytics_section() {
	echo sn_uptime_status_analytics_section(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
}
add_action( 'snt_analytics_after_overview', 'sn_uptime_status_render_analytics_section' );

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
