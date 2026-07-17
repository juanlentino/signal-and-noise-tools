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

// Scripts at :5, the window at :6 — the window names this handle, so it must
// exist first.
//
// REGISTER ONLY, never enqueue. Verified in the desktop-mode v0.9.5 source:
// desktop_mode_enqueue_native_window_scripts() (includes/registries/
// native-windows.php:685) runs on admin_enqueue_scripts:20 and does
// wp_enqueue_script( $entry['script'] ) for us (:709), then
// wp_add_inline_script( …, 'before' ) to ship `config` as
// window.desktopModeWindowConfig[ id ] (:745-752) — which REQUIRES the handle to
// be registered, hence :5. Enqueueing it ourselves would load the HUD's JS on
// every admin page.
//
// NO deps, deliberately. The sibling widget scripts declare
// array( 'wp-api-fetch', 'snt-ability-run' ) because they CALL ABILITIES. This
// one doesn't: it uses window.fetch and reads its config from
// window.desktopModeWindowConfig, which desktop-mode injects onto THIS handle —
// so it is printed before our file with no dependency needed. It does not read
// window.snDesktopData either. Adding deps here would only load dead weight.
add_action( 'init', function () {
	wp_register_script(
		'sn-desktop-window-analytics',
		plugins_url( 'assets/desktop-mode-window-analytics.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION,
		true
	);
}, 5 );

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
		$totals = sn_analytics_range_totals( $from, $to, 'human' );

		// null is PRESERVED, never cast. sn_analytics_engaged_rate() returns
		// int|null, and null means "no timed pageviews to divide by" — a state
		// distinct from a measured 0%. (int) would fabricate a confident zero
		// that no downstream consumer could tell apart from real disengagement,
		// and once cast the distinction is unrecoverable.
		$engaged = sn_analytics_engaged_rate( $from, $to, 'human' );

		$seven_day = array(
			// scroll_avg / time_avg are the accessor's OWN key names
			// (inc/analytics-read.php:82) — NOT avg_scroll/avg_time, which exist
			// nowhere in the codebase and would leave `?? 0` rendering a
			// fabricated 0% scroll / 0s time forever. `deltas` below keys the
			// same two metrics the same way; one concept, one spelling.
			'views'        => (int) ( $totals['views'] ?? 0 ),
			'visits'       => (int) ( $totals['visits'] ?? 0 ),
			'scroll_avg'   => (float) ( $totals['scroll_avg'] ?? 0 ),
			'time_avg'     => (float) ( $totals['time_avg'] ?? 0 ),
			'engaged_rate' => null === $engaged ? null : (int) $engaged,
			'deltas'       => (array) sn_analytics_period_deltas( $from, $to, 'human' ),
		);
		set_transient( $cache_key, $seven_day, 5 * MINUTE_IN_SECONDS );
	}

	// Same null discipline, and NEVER from the day-stamped cache: realtime is a
	// 5-minute window, so a daily key would make it a stale number wearing a
	// fresh label. null = the realtime transient was never warmed ("never
	// measured"), which the template already renders as —. A warmed but quiet
	// site returns a real 0, and the two must stay distinguishable.
	$realtime = sn_analytics_realtime( 'human' );

	return new WP_REST_Response( array(
		'realtime'    => null === $realtime ? null : (int) $realtime,
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
