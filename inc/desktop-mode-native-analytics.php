<?php
/**
 * Signal & Noise Tools — the `sn-analytics-hud` native desktop-mode window.
 *
 * desktop-mode has two window types. Every SN admin destination opens as a
 * chromeless IFRAME of a wp-admin page. This is the other one: a NATIVE window,
 * rendering into the parent DOM. Contract read from the v0.9.5 tag
 * (includes/registries/native-windows.php) — NOT from
 * docs/native-windows-proposal.md, a historical RFC whose arg surface is wrong.
 *
 * TIMING: desktop-mode builds its `nativeWindows` payload EAGERLY
 * (includes/core/payload.php:1137) from inside admin_enqueue_scripts:10.
 * active_plugins sorts alphabetically, so desktop-mode < signal-and-noise-tools
 * and its :10 callback ALWAYS runs before ours. Registering from our own
 * admin_enqueue_scripts:10 is unwinnable — the payload is already built. Hence
 * init. Scripts at :5, the window at :6, matching the widgets next door.
 *
 * @package signal-and-noise-tools
 * @since   9.56.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Echo the window's template body.
 *
 * SKELETON ONLY — no numbers. desktop-mode prints this template into the DOM
 * ONCE at shell render and clones it per window open, so any server-rendered
 * value would freeze at page load. Realtime especially must not.
 *
 * @since 9.56.0
 */
function snt_desktop_analytics_hud_template() {
	?>
	<wpd-stack data-sn-hud="root">
		<wpd-section>
			<wpd-row><strong data-sn-hud="realtime">—</strong> <span>visitors now</span></wpd-row>
		</wpd-section>
		<wpd-section data-sn-hud="kpis"></wpd-section>
		<wpd-section data-sn-hud="top-content"></wpd-section>
		<wpd-section data-sn-hud="top-sources"></wpd-section>
		<wpd-row><a data-sn-hud="full-link" href="#">Open full analytics</a></wpd-row>
	</wpd-stack>
	<?php
}

add_action( 'init', function () {
	if ( ! function_exists( 'desktop_mode_register_window' ) ) {
		return;
	}

	desktop_mode_register_window( 'sn-analytics-hud', array(
		'title'        => __( 'Analytics', 'signal-and-noise-tools' ),
		'icon'         => 'dashicons-chart-area',
		'template'     => 'snt_desktop_analytics_hud_template',
		'script'       => 'sn-desktop-window-analytics',
		'placement'    => 'dock',
		'capabilities' => array( 'manage_options' ),
		'config'       => array(
			'endpoint' => rest_url( 'signal-noise/v1/desktop/analytics-hud' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			// NEVER a literal. Analytics is an add_dashboard_page(), so its home
			// is index.php?page=sn-analytics — the one slug the tab resolver alone
			// gets wrong (it falls through to tab=dashboard: a link that loads
			// perfectly and goes to the wrong place). snt_desktop_admin_url()
			// special-cases it.
			'fullUrl'  => snt_desktop_admin_url( 'sn-analytics' ),
		),
	) );
}, 6 );
