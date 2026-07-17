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

/**
 * The HUD's data payload.
 *
 * Composes existing sn_analytics_* accessors — no new SQL. The window is the
 * trailing 7 LOCAL days inclusive of today: the rollups and the live filter
 * agree on the local day, and a UTC "today" is the documented source of the
 * off-by-one flicker.
 *
 * @since 9.56.0
 * @return WP_REST_Response
 */
function snt_desktop_analytics_hud_payload() {
	$to   = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from = gmdate( 'Y-m-d', strtotime( $to . ' -6 days' ) );

	// Date-stamped key: a flat key warmed at 23:58 would keep serving the
	// previous day's window past local midnight. Stamping the local day makes
	// the rollover exact and self-expiring. TTL 5 min — below the 30s poll it
	// would be pointless, above it the HUD visibly lags the full page.
	$cache_key = 'sn_desktop_analytics_hud_' . $to;
	$seven_day = get_transient( $cache_key );

	if ( ! is_array( $seven_day ) ) {
		$totals    = sn_analytics_range_totals( $from, $to, 'human' );
		$seven_day = array(
			'views'        => (int) ( $totals['views'] ?? 0 ),
			'visits'       => (int) ( $totals['visits'] ?? 0 ),
			'avg_scroll'   => (float) ( $totals['avg_scroll'] ?? 0 ),
			'avg_time'     => (float) ( $totals['avg_time'] ?? 0 ),
			'engaged_rate' => (float) sn_analytics_engaged_rate( $from, $to, 'human' ),
			'deltas'       => (array) sn_analytics_period_deltas( $from, $to, 'human' ),
		);
		set_transient( $cache_key, $seven_day, 5 * MINUTE_IN_SECONDS );
	}

	return new WP_REST_Response( array(
		// NEVER from the day-stamped cache: a 5-minute window behind a daily key
		// is a stale number wearing a fresh label. Computed every request.
		'realtime'    => (int) sn_analytics_realtime( 'human' ),
		'seven_day'   => $seven_day,
		'top_content' => array_values( (array) sn_analytics_top_paths( $from, $to, 'human', 5 ) ),
		'top_sources' => array_values( (array) sn_analytics_top_sources( $from, $to, 'human', 5 ) ),
		// No full_url here on purpose: it rides `config`, which the window already
		// has at mount time. Shipping it again on every 30s poll is dead weight.
	), 200 );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'signal-noise/v1', '/desktop/analytics-hud', array(
		'methods'             => 'GET',
		'callback'            => 'snt_desktop_analytics_hud_payload',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
} );
