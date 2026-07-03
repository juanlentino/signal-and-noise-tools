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
 * KPI cards: Checked / Blocked / Block rate / Networks. Mirrors the .sn-kpi-row
 * markup of snt_analytics_render_cards() with login labels.
 */
function sn_login_defense_render_kpi_cards( $k ) {
	$cards = array(
		array( 'l' => __( 'Checked (7d)', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['checked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'seen', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Blocked', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['blocked'] ?? 0 ) ), 'promoted' => true, 'sub' => __( 'denied', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Block rate', 'signal-and-noise-tools' ), 'n' => (int) ( $k['block_rate'] ?? 0 ) . '%', 'sub' => __( 'of checks', 'signal-and-noise-tools' ) ),
		array( 'l' => __( 'Networks', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $k['networks'] ?? 0 ) ), 'sub' => __( 'distinct', 'signal-and-noise-tools' ) ),
	);
	echo '<div class="sn-kpi-row">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
		// Flat delta slot — login KPIs have no period-over-period delta; the slot keeps
		// the card structure identical to snt_analytics_render_cards() (a labelled sub-line).
		echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html( $c['sub'] ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Daily blocked-trend sparkline. Mirrors snt_analytics_render_trend()'s SVG band
 * (reusing snt_analytics_smooth_path when available, else a straight polyline)
 * with login labels. $series = [{day,views}] ascending; views = blocked count.
 */
function sn_login_defense_render_trend_chart( $series ) {
	if ( ! is_array( $series ) || count( $series ) < 2 ) {
		return;
	}
	$n    = count( $series );
	$max  = 1;
	$peak = 0;
	foreach ( $series as $r ) {
		$v    = (int) ( $r['views'] ?? 0 );
		$max  = max( $max, $v );
		$peak = max( $peak, $v );
	}
	$w    = 600.0;
	$top  = 8.0;
	$base = 78.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $r ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) ( $r['views'] ?? 0 ) / $max ) * ( $base - $top ), 2 );
	}
	if ( function_exists( 'snt_analytics_smooth_path' ) ) {
		$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	} else {
		$pts    = array();
		foreach ( $px as $i => $x ) { $pts[] = $x . ',' . $py[ $i ]; }
		$line_d = 'M ' . implode( ' L ', $pts );
	}
	// Area = the line dropped to the baseline and closed (parity with snt_analytics_render_trend).
	$last_x = $px[ $n - 1 ];
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	echo '<div class="sn-overview-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Blocked per day', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-trend-meta">' . esc_html( sprintf( /* translators: %s peak blocked count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ) ) . '</span></div>';
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( __( 'Daily blocked trend', 'signal-and-noise-tools' ) ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG gradient def, no dynamic values.
	echo '<defs><linearGradient id="snSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2271b1" stop-opacity="0.16"/><stop offset="55%" stop-color="#2271b1" stop-opacity="0.04"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snSparkFill)" stroke="none"/>';
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $series[0]['day'] ) . '</span><span>' . esc_html( (string) end( $series )['day'] ) . '</span></div>';
	echo '</div>';
}

/**
 * Ranked top-N table (attacker networks / countries). $rows = [{k,v}].
 */
function sn_login_defense_render_top_table( $title, $col, $rows ) {
	if ( ! $rows ) {
		snt_an_note_empty( $title );
		return;
	}
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $col ) . '</th>';
	echo '<th scope="col" class="manage-column num">' . esc_html__( 'Blocked', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td class="column-primary"><strong>' . esc_html( (string) ( $r['k'] ?? '' ) ) . '</strong></td>'
			. '<td class="num">' . esc_html( number_format_i18n( (int) ( $r['v'] ?? 0 ) ) ) . '</td></tr>';
	}
	echo '</tbody></table></div></div>';
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
		echo '<div class="postbox"><div class="inside"><p class="sn-an-empty">'
			. esc_html__( 'Connect Cloudflare Analytics (Account ID + token) in the Analytics tab to see login-defense analytics.', 'signal-and-noise-tools' )
			. '</p></div></div>';
		return;
	}

	$days    = sn_login_defense_resolve_days();
	$allowed = array( 7, 30, 90 );

	// 1:1 range control: the FULL shared range-pill markup (snt_analytics_render_controls,
	// analytics-admin-render.php:70-87) minus the class pills + 365/all/custom options
	// (login AE retains ~90d and is not class-segmented -- those would render empty/false).
	// Active marker is the `active` class (NOT button-primary): the shared CSS targets
	// .button.button-small.active. Base = remove only sn_lg_range (preserves sn_view).
	$base = remove_query_arg( array( 'sn_lg_range' ) );
	echo '<div class="sn-toolbar">';
	echo '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Date range', 'signal-and-noise-tools' ) . '">';
	echo '<span class="sn-control-label">' . esc_html__( 'Range', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="button-group">';
	foreach ( $allowed as $r ) {
		$is_active = ( $r === $days );
		echo '<a class="button button-small' . ( $is_active ? ' active' : '' ) . '"'
			. ( $is_active ? ' aria-pressed="true"' : '' )
			. ' href="' . esc_url( add_query_arg( array( 'sn_lg_range' => $r ), $base ) ) . '">'
			. esc_html( (int) $r . 'd' ) . '</a>';
	}
	echo '</span></div></div>';

	$dec              = sn_analytics_query( sn_login_defense_decisions_sql( $days ) ) ?: array();
	$kpis             = sn_login_defense_kpis_from_rows( $dec );
	$net              = sn_analytics_query( sn_login_defense_networks_sql( $days ) ) ?: array();
	$kpis['networks'] = (int) ( $net[0]['networks'] ?? 0 );

	// Fuse the KPI strip + blocked-trend into ONE "Overview" postbox, identical to the
	// shared dashboard chrome (render_dashboard wraps the other views' cards+trend this way).
	echo '<div class="postbox sn-overview"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Overview', 'signal-and-noise-tools' ) . '</span></h2></div>';
	echo '<div class="inside inside-flush sn-overview-inside">';
	sn_login_defense_render_kpi_cards( $kpis );
	sn_login_defense_render_trend_chart( sn_login_defense_trend_series( sn_analytics_query( sn_login_defense_trend_sql( $days ) ) ?: array() ) );
	echo '</div></div>';

	echo '<p class="sn-an-breakdown">';
	foreach ( array( 'block', 'pass', 'bypass', 'killswitch' ) as $d ) {
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
		echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Door-knock pressure (CF edge)', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside">';
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
		echo '</div></div>';
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
