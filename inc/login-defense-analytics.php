<?php
/**
 * Login defense analytics: the Analytics-dashboard Login defense view (registered
 * in SN_ANALYTICS_VIEWS, dispatched by snt_analytics_render_dashboard) + its
 * login-specific renderers. Reads the sn_login_guard AE dataset via the query
 * builders in inc/login-defense.php. Reuses the existing .sn-kpi / .sn-spark CSS
 * vocabulary but with login-appropriate labels (the shared analytics renderers
 * hardcode pageview semantics). Read-only; no enforcement.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * KPI cards: Checked / Blocked / Block rate / Networks. Routes through the
 * shared snt_an_kpi_row() primitive (D5 §2, inc/analytics-panels.php) — every
 * card here is the plain-descriptor shape (a flat 'sub' line, no real
 * {pct,dir} delta and no derived sub_class), so the primitive's default
 * 'sn-delta-flat' reproduces the old hand-rolled loop byte-for-byte.
 */
function sn_login_defense_render_kpi_cards( $k ) {
	$cards = array(
		array( 'l' => __( 'Checked (7d)', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['checked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'seen', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Blocked', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['blocked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'denied', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Throttled', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['throttled'] ?? 0 ) ), 'sub' => __( 'rate-limited', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Block rate', 'signal-and-noise-tools' ), 'n' => (int) ( $k['block_rate'] ?? 0 ) . '%', 'sub' => __( 'of checks', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Networks', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['networks'] ?? 0 ) ), 'sub' => __( 'distinct', 'signal-and-noise-tools' ) ),
	);
	snt_an_kpi_row( $cards );
}

/**
 * Daily blocked-trend sparkline. Routes through the shared trend-SVG primitive
 * (inc/analytics-panels.php, D5 §3) — default gradient id (its suite pins
 * `url(#snSparkFill)`, tests/login-defense-analytics.php:58; the two 'snSparkFill'
 * copies are mutually exclusive views behind the analytics-admin.php view switch
 * and never co-render, so the shared id is safe). $series = [{day,views}]
 * ascending; views = blocked count. The <2-point silent guard matches the
 * primitive's own guard exactly — kept here only so the peak/axis reads below
 * never run against a too-short series.
 */
function sn_login_defense_render_trend_chart( $series ) {
	if ( ! is_array( $series ) || count( $series ) < 2 ) {
		return;
	}
	$peak  = 0;
	$views = array();
	foreach ( $series as $r ) {
		$v       = (int) ( $r['views'] ?? 0 );
		$views[] = $v;
		$peak    = max( $peak, $v );
	}

	snt_an_trend_svg(
		$views,
		array(
			'head'       => __( 'Blocked per day', 'signal-and-noise-tools' ),
			'meta'       => sprintf( /* translators: %s peak blocked count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ),
			'axis'       => array( (string) $series[0]['day'], (string) end( $series )['day'] ),
			'aria_label' => __( 'Daily blocked trend', 'signal-and-noise-tools' ),
		)
	);
}

/**
 * Ranked top-N table (attacker networks / countries). $rows = [{k,v}]. Thin
 * wrapper over the shared snt_an_kv_table() primitive (D5 §4,
 * inc/analytics-panels.php) — picks up the standard sn-an-postbox chrome this
 * hand-rolled table never had. Keeps its $col semantics (the primary column
 * header, distinct from $title, e.g. title "Top attacker networks" / col
 * "Network (ASN)"). No empty-fold $why: this fn never had one, still doesn't.
 */
function sn_login_defense_render_top_table( $title, $col, $rows ) {
	$kv_rows = array();
	foreach ( (array) $rows as $r ) {
		$kv_rows[] = array(
			(string) ( $r['k'] ?? '' ),
			number_format_i18n( (int) ( $r['v'] ?? 0 ) ),
		);
	}

	snt_an_kv_table(
		$title,
		$kv_rows,
		array( $col, __( 'Blocked', 'signal-and-noise-tools' ) )
	);
}

/**
 * Resolve the login range (7/30/90, default 7) from the GET param. Shared by the
 * header (range control + decisions/networks/trend queries) and the body (ASN /
 * country / edge-glance queries) so the clamp lives in one place.
 */
function sn_login_defense_resolve_days() {
	$allowed = array( 7, 30, 90 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display range, no state change.
	$days = isset( $_GET['sn_lg_range'] ) ? (int) $_GET['sn_lg_range'] : 7;
	return in_array( $days, $allowed, true ) ? $days : 7;
}

/**
 * Login defense ABOVE-the-tabs chrome: the dormant gate (the ONLY emitter of the
 * "Connect Cloudflare Analytics" notice) + the 1:1 range control + the Overview
 * postbox (KPIs + trend) + the decision breakdown pills. Dispatched into the
 * shared header slot by snt_analytics_render_dashboard() so the tab bar sits in
 * the same position as on the pageview views (frame parity, no jump).
 *
 * Runs the three header queries: decisions (-> KPIs + breakdown), networks
 * (-> the 4th KPI card), and trend. The body owns its own (ASN/country/glance)
 * queries, so nothing is double-fetched.
 */
function sn_login_defense_render_header() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		// D5 §2: routes through the shared snt_an_gate() primitive. The old gate
		// was a bare, titleless postbox (no header at all) — it now carries the
		// view's natural title 'Login defense', matching every other view's gate
		// (Analytics, Visits, Traffic & edge). No CTA: the old gate never had one.
		snt_an_gate(
			__( 'Login defense', 'signal-and-noise-tools' ),
			__( 'Connect Cloudflare Analytics (Account ID + token) in the Analytics tab to see login-defense analytics.', 'signal-and-noise-tools' )
		);
		return;
	}

	$days    = sn_login_defense_resolve_days();
	$allowed = array( 7, 30, 90 );

	// 1:1 range control: the FULL shared range-pill markup (snt_analytics_render_controls,
	// analytics-admin-render.php:70-87) minus the class pills + 365/all/custom options
	// (login AE retains ~90d and is not class-segmented -- those would render empty/false).
	// Active marker is the `active` class (NOT button-primary): the shared CSS targets
	// .button.button-small.active. Base = remove only sn_lg_range (preserves sn_view).
	// D5 §2: with the gate/Overview/KPI-loop/door-knock postbox all adopted onto
	// the shared primitives this task, this pill row WAS the last hand-rolled control
	// clone in the codebase — v9.42.2 extracts it into snt_an_range_pills()
	// (inc/analytics-panels.php), closing that recorded backlog item. The
	// .sn-toolbar wrapper stays here (the primitive renders only the inner
	// .sn-control-group); markup is byte-identical to the pre-extraction version.
	$base = remove_query_arg( array( 'sn_lg_range' ) );
	echo '<div class="sn-toolbar">';
	snt_an_range_pills(
		'sn_lg_range',
		$allowed,
		$days,
		array(
			'base'       => $base,
			'label'      => __( 'Range', 'signal-and-noise-tools' ),
			'aria_label' => __( 'Date range', 'signal-and-noise-tools' ),
		)
	);
	echo '</div>';

	$dec              = sn_analytics_query( sn_login_defense_decisions_sql( $days ) ) ?: array();
	$kpis             = sn_login_defense_kpis_from_rows( $dec );
	$net              = sn_analytics_query( sn_login_defense_networks_sql( $days ) ) ?: array();
	$kpis['networks'] = (int) ( $net[0]['networks'] ?? 0 );

	// Fuse the KPI strip + blocked-trend into ONE "Overview" postbox, identical to the
	// shared dashboard chrome (render_dashboard wraps the other views' cards+trend this way).
	// D5 §2: routes through snt_an_panel_open()/close() — the one deliberate visual
	// change (owner-approved): the Overview now also carries sn-an-postbox, so it
	// picks up the 22/13 KPI scale like every other view's Overview.
	snt_an_panel_open(
		__( 'Overview', 'signal-and-noise-tools' ),
		array(
			'panel_class'  => 'sn-overview',
			'inside_class' => 'inside inside-flush sn-overview-inside',
		)
	);
	sn_login_defense_render_kpi_cards( $kpis );
	sn_login_defense_render_trend_chart( sn_login_defense_trend_series( sn_analytics_query( sn_login_defense_trend_sql( $days ) ) ?: array() ) );
	snt_an_panel_close();

	echo '<p class="sn-an-breakdown">';
	// 'failopen' (worker v1.3.0+): the guard hit an internal error and let the
	// request through rather than lock the owner out — surfaced here so a
	// failing-open guard is visible, not silent. 'throttle' (v1.7.0) is the
	// per-IP POST rate limit; 'degraded' (v1.4.0) is a corrupted denylist
	// enforcing nothing — it was missing from this hand-maintained list for
	// its whole life, the exact silent-under-coverage this list invites, so:
	// every decision the worker can emit MUST appear here.
	foreach ( array( 'block', 'throttle', 'pass', 'bypass', 'killswitch', 'degraded', 'failopen' ) as $d ) {
		echo '<span class="sn-pill">' . esc_html( $d ) . ' '
			. esc_html( number_format_i18n( (int) ( $kpis['breakdown'][ $d ] ?? 0 ) ) ) . '</span> ';
	}
	echo '</p>';
}

/**
 * Login defense BELOW-the-tabs content: the two attacker top-tables + the CF edge
 * door-knock glance. Dispatched by the switch case. Guards config SILENTLY (no
 * output) so the wrapper path never doubles the dormant notice the header emits.
 * In the dashboard it is only reached when configured (the dashboard's outer
 * config gate returns earlier).
 */
function sn_login_defense_render_body() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return;
	}

	$days = sn_login_defense_resolve_days();

	$asn  = sn_analytics_query( sn_login_defense_top_asn_sql( $days, 10 ) ) ?: array();
	$ctry = sn_analytics_query( sn_login_defense_top_country_sql( $days, 10 ) ) ?: array();
	sn_login_defense_render_top_table(
		__( 'Top attacker networks', 'signal-and-noise-tools' ),
		__( 'Network (ASN)', 'signal-and-noise-tools' ),
		array_map(
			function ( $r ) { return array( 'k' => $r['asorg'] ?? '', 'v' => $r['hits'] ?? 0 ); },
			$asn
		)
	);
	sn_login_defense_render_top_table(
		__( 'Top attacker countries', 'signal-and-noise-tools' ),
		__( 'Country', 'signal-and-noise-tools' ),
		array_map(
			function ( $r ) { return array( 'k' => $r['country'] ?? '', 'v' => $r['hits'] ?? 0 ); },
			$ctry
		)
	);

	// CF edge door-knock pressure: the loud /wp-login.php + /xmlrpc.php doors the
	// masked-login worker never sees. Independently edge-gated; reached only when AE
	// config is present (the view precondition above). Glance only — the full
	// breakdown lives in Analytics → Traffic & edge.
	if ( function_exists( 'sn_edge_config' ) && sn_edge_config() && function_exists( 'sn_edge_top_dim' ) ) {
		$to_d   = gmdate( 'Y-m-d' );
		$from_d = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$total  = 0;
		foreach ( sn_edge_top_dim( 'atk_door', $from_d, $to_d, 20 ) as $r ) {
			$total += (int) ( $r['requests'] ?? 0 );
		}
		$ctry_e = sn_edge_top_dim( 'atk_country', $from_d, $to_d, 1 );
		$net_e  = sn_edge_top_dim( 'atk_asn', $from_d, $to_d, 1 );
		// D5 §2: routes through snt_an_panel_open()/close() — picks up sn-an-postbox.
		snt_an_panel_open( __( 'Door-knock pressure (CF edge)', 'signal-and-noise-tools' ) );
		echo '<p>' . esc_html( number_format_i18n( $total ) ) . ' '
			. esc_html__( 'hits on /wp-login.php + /xmlrpc.php', 'signal-and-noise-tools' );
		if ( ! empty( $ctry_e[0]['value'] ) ) {
			echo ' &middot; ' . esc_html__( 'top country', 'signal-and-noise-tools' ) . ' ' . esc_html( (string) $ctry_e[0]['value'] );
		}
		if ( ! empty( $net_e[0]['value'] ) ) {
			echo ' &middot; ' . esc_html__( 'top network', 'signal-and-noise-tools' ) . ' ' . esc_html( (string) $net_e[0]['value'] );
		}
		echo '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'index.php?page=sn-analytics&sn_view=edge' ) ) . '">'
			. esc_html__( 'Full breakdown in Traffic & edge', 'signal-and-noise-tools' ) . ' &rarr;</a></p>';
		snt_an_panel_close();
	}
	snt_an_flush_empty_fold();
}

/**
 * Thin wrapper preserving the single direct entry point (tests/login-defense-analytics.php
 * + any other caller): the header then the body. Standalone output is the header's
 * dormant notice exactly once when unconfigured, or the full view when configured.
 * Registered in SN_ANALYTICS_VIEWS; the dashboard dispatches header + body separately
 * (header above the tabs, body below) so this wrapper is NOT the dashboard path.
 */
function sn_login_defense_view_render() {
	sn_login_defense_render_header();
	sn_login_defense_render_body();
}
