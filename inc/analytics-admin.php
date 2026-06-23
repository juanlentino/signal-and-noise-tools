<?php
/**
 * Signal & Noise — Monitoring → Analytics tab.
 *
 * Native wp-admin surface (no theme vocabulary) for the first-party edge
 * analytics. Reads only the durable rollup accessors (never AE) so it never
 * blocks; shows a config/empty state until the Cloudflare creds + worker land.
 * v5.5.0: a persistent header (controls → separation → delta cards → trend) over
 * a WP-native tab strip (Content · Technology · Geography · Engagement · Quality · Events);
 * each tab lazily fetches only its own panels' data.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_RANGES = array( 7, 30, 90, 365 );

/**
 * Whitelist the ?sn_range GET value to a supported window; default 7.
 *
 * @param mixed $raw
 * @return int|string 7 | 30 | 90 | 365 | 'all'
 */
function snt_analytics_resolve_range( $raw ) {
	if ( 'all' === (string) $raw ) {
		return 'all';
	}
	$n = (int) $raw;
	return in_array( $n, SN_ANALYTICS_RANGES, true ) ? $n : 7;
}

/**
 * Whitelist the ?sn_class GET value to a known class; default human.
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_class( $raw ) {
	$c = (string) $raw;
	return in_array( $c, SN_ANALYTICS_CLASSES, true ) ? $c : 'human';
}

// The tabbed views of the read-only dashboard, in display order (slug → label).
// The detailed dimension/derived panels live under one of these; the headline
// (controls + delta cards + trend) is persistent above the tabs.
const SN_ANALYTICS_VIEWS = array(
	'content'    => 'Content',
	'posts'      => 'Posts',
	'technology' => 'Technology',
	'geography'  => 'Geography',
	'engagement' => 'Engagement',
	'quality'    => 'Quality',
	'events'     => 'Events',
	'edge'       => 'Traffic & edge',
	'login-defense' => 'Login defense',
);

/**
 * Whitelist the ?sn_view GET value to a known tab; default 'content'.
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_view( $raw ) {
	$v = (string) $raw;
	return isset( SN_ANALYTICS_VIEWS[ $v ] ) ? $v : 'content';
}

// Views that render their own complete chrome (own KPI cards, trend, range control)
// and therefore opt OUT of the shared pageview header. login-defense ONLY — 'edge'
// deliberately keeps the shared header it ships today (changing it would be a regression).
const SN_ANALYTICS_OWNS_CHROME = array( 'login-defense' );

/**
 * True iff $view brings its own chrome, so the shared pageview header (controls +
 * Overview postbox + the post-switch empty hint) is suppressed for it.
 *
 * @param string $view
 * @return bool
 */
function snt_analytics_view_owns_chrome( $view ) {
	return in_array( (string) $view, SN_ANALYTICS_OWNS_CHROME, true );
}

/**
 * Render the WP-native tab strip for the dashboard views. Each tab link is the
 * current page with sn_view set + the active sn_range/sn_class preserved, so
 * switching tabs keeps the window + class filter. Mirrors the SN top-tab nav
 * (`.nav-tab-wrapper`/`.nav-tab`) for native styling.
 *
 * @param string     $active Active view slug.
 * @param int|string $range  Active range in days or 'all'.
 * @param string     $class  Active class (preserved across tabs).
 */
function snt_analytics_render_view_tabs( $active, $range, $class, $from = '', $to = '' ) {
	// sn_drill is stripped too: a drill is scoped to the view that owns the dim, so
	// switching tabs clears it rather than carrying a stale "Country = US" onto a tab
	// with no Country table (the panel render is also dim/view-gated as a backstop).
	$base = remove_query_arg( array( 'sn_view', 'sn_range', 'sn_class', 'sn_from', 'sn_to', 'sn_drill', 'sn_lg_range' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'index.php?page=sn-analytics' );
	}
	echo '<nav class="nav-tab-wrapper sn-an-view-tabs" aria-label="Analytics views">';
	foreach ( SN_ANALYTICS_VIEWS as $slug => $label ) {
		$url   = add_query_arg( array( 'sn_view' => $slug ) + snt_analytics_window_args( $range, $class, $from, $to ), $base );
		$is_on = ( $slug === $active );
		// aria-current inlined (not a pre-built $aria var) so the escaping stays
		// at the point of output and EscapeOutput can verify it.
		if ( $is_on ) {
			echo '<a class="nav-tab nav-tab-active" href="' . esc_url( $url ) . '" aria-current="page">' . esc_html( $label ) . '</a>';
		} else {
			echo '<a class="nav-tab" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	echo '</nav>';
}

/**
 * Inclusive [$from,$to] YYYY-MM-DD window ending on the anchor day.
 * UTC (gmdate) to align with AE's toStartOfDay() buckets. $now is injectable
 * for deterministic tests. When $range is 'all', $from is the earliest day in
 * the rollup table (via sn_analytics_min_day()).
 *
 * @param int|string $range Days as int (7|30|90|365) or 'all'.
 * @param int|null   $now   Unix timestamp anchor (defaults to now).
 * @return array{0:string,1:string} [$from, $to]
 */
function snt_analytics_range_dates( $range, $now = null ) {
	$now = ( null === $now ) ? time() : (int) $now;
	$to  = gmdate( 'Y-m-d', $now );
	if ( 'all' === $range ) {
		$from = function_exists( 'sn_analytics_min_day' ) ? sn_analytics_min_day() : $to;
		return array( $from, $to );
	}
	$days = max( 1, (int) $range );
	$from = gmdate( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
	return array( $from, $to );
}

/** True iff $s is a real YYYY-MM-DD date (format + checkdate). */
function snt_analytics_is_ymd( $s ) {
	if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $s, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Concrete [$from,$to] (inclusive, YYYY-MM-DD, UTC) for a named preset. $now
 * injectable for deterministic tests.
 *
 * @param string   $preset 'ytd' | 'last-month' | 'last-quarter' | 'prev-year'.
 * @param int|null $now    Unix anchor.
 * @return array{0:string,1:string}
 */
function snt_analytics_preset_dates( $preset, $now = null ) {
	$now   = ( null === $now ) ? time() : (int) $now;
	$today = gmdate( 'Y-m-d', $now );
	$y     = (int) gmdate( 'Y', $now );
	$mo    = (int) gmdate( 'n', $now );
	switch ( (string) $preset ) {
		case 'ytd':
			return array( sprintf( '%04d-01-01', $y ), $today );
		case 'prev-year':
			return array( sprintf( '%04d-01-01', $y - 1 ), sprintf( '%04d-12-31', $y - 1 ) );
		case 'last-month':
			$end = gmmktime( 0, 0, 0, $mo, 1, $y ) - DAY_IN_SECONDS; // last day of the prior month
			return array( gmdate( 'Y-m-01', $end ), gmdate( 'Y-m-d', $end ) );
		case 'last-quarter':
			$cur_q_first = ( (int) ceil( $mo / 3 ) - 1 ) * 3 + 1;                          // 1|4|7|10
			$end         = gmmktime( 0, 0, 0, $cur_q_first, 1, $y ) - DAY_IN_SECONDS;       // last day of the prior quarter
			$pe_y        = (int) gmdate( 'Y', $end );
			$pe_q_first  = ( (int) ceil( (int) gmdate( 'n', $end ) / 3 ) - 1 ) * 3 + 1;
			return array( sprintf( '%04d-%02d-01', $pe_y, $pe_q_first ), gmdate( 'Y-m-d', $end ) );
		default:
			return array( $today, $today );
	}
}

/**
 * Validate + clamp a user custom window. Rejects malformed dates (→ null), swaps a
 * reversed pair, clamps `to`/`from` to today, and `from` to sn_analytics_min_day()
 * when available. Returns [$from,$to] or null (caller falls back to the default).
 *
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now Unix anchor.
 * @return array{0:string,1:string}|null
 */
function snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now = null ) {
	$now   = ( null === $now ) ? time() : (int) $now;
	$today = gmdate( 'Y-m-d', $now );
	$from  = trim( (string) $from_raw );
	$to    = trim( (string) $to_raw );
	if ( ! snt_analytics_is_ymd( $from ) || ! snt_analytics_is_ymd( $to ) ) {
		return null;
	}
	if ( $from > $to ) { // ISO YYYY-MM-DD sorts lexically
		$tmp = $from; $from = $to; $to = $tmp;
	}
	if ( $to > $today ) {
		$to = $today;
	}
	if ( $from > $today ) {
		$from = $today;
	}
	if ( function_exists( 'sn_analytics_min_day' ) ) {
		$min = sn_analytics_min_day();
		if ( snt_analytics_is_ymd( $min ) && $from < $min ) {
			$from = $min;
		}
	}
	if ( $from > $to ) {
		return null;
	}
	return array( $from, $to );
}

/**
 * Single resolver for the dashboard/export window. Returns [$range_token,$from,$to]
 * — $range_token is the scalar used for URL/display (7|30|90|365|'all'|preset|'custom'),
 * $from/$to the concrete inclusive window. Presets + custom resolve here; int/'all'
 * delegate to the unchanged resolve_range + range_dates.
 *
 * @param mixed    $range_raw
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now
 * @return array{0:int|string,1:string,2:string}
 */
function snt_analytics_resolve_window( $range_raw, $from_raw = '', $to_raw = '', $now = null ) {
	$range_raw = (string) $range_raw;
	$presets   = array( 'ytd', 'last-month', 'last-quarter', 'prev-year' );
	if ( in_array( $range_raw, $presets, true ) ) {
		list( $from, $to ) = snt_analytics_preset_dates( $range_raw, $now );
		return array( $range_raw, $from, $to );
	}
	if ( 'custom' === $range_raw ) {
		$win = snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now );
		if ( null !== $win ) {
			return array( 'custom', $win[0], $win[1] );
		}
		$range = 7;
	} else {
		$range = snt_analytics_resolve_range( $range_raw );
	}
	list( $from, $to ) = snt_analytics_range_dates( $range, $now );
	return array( $range, $from, $to );
}

/**
 * The settings page the dashboard's "Configure →" link points at (and where the
 * creds form lives): Monitoring → Analytics. Built on the page=sn-theme-options
 * route so the form POST hits the allow-listed admin-post handler.
 */
function snt_analytics_settings_url() {
	return admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' );
}

/**
 * Render the comprehensive READ-ONLY analytics dashboard. Lives on the native WP
 * Dashboard → Analytics page (inc/analytics-dashboard-page.php); the credential
 * settings are split out to Monitoring → Analytics
 * (snt_analytics_render_settings_section). No <h1> heading or settings form here
 * — the page chrome owns the title, and the read view carries no form.
 *
 * v5.5.0 layout: a persistent header (controls + separation + delta cards +
 * trend) above a WP-native tab strip (Content · Technology · Geography ·
 * Engagement · Quality · Events). The active tab (?sn_view=, whitelisted) lazily fetches
 * ONLY its own panels' data. Every dimension/derived panel renders its own empty
 * state until the edge data accrues (worker v1.1.0 — no backfill).
 *
 * Note: period-over-period deltas are suppressed for the 'all' range. Trend
 * granularity is daily for windows ≤90 days, weekly beyond.
 */
function snt_analytics_render_dashboard() {
	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range_raw = isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7';
	$from_raw  = isset( $_GET['sn_from'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_from'] ) ) : '';
	$to_raw    = isset( $_GET['sn_to'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_to'] ) ) : '';
	$class     = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );
	$view      = snt_analytics_resolve_view( isset( $_GET['sn_view'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_view'] ) ) : 'content' );

	// Config gate: empty notice + a link to the settings page (the form lives there now).
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		snt_analytics_render_empty( 'unconfigured' );
		echo '<p><a class="button button-primary" href="' . esc_url( snt_analytics_settings_url() ) . '">Configure analytics &rarr;</a></p>';
		return;
	}

	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	// Granularity from the resolved window day-count — works for every range incl.
	// presets/custom, and is behaviour-identical to the old (int)$range for fixed ranges.
	$gran_days   = (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
	$granularity = sn_analytics_granularity( $gran_days );

	// Views that own their chrome (login-defense) skip the shared pageview header
	// entirely — they bring their own KPI cards, trend, and range control and would
	// otherwise stack pageview stats above their own. Computed once; gates the three
	// pageview-only regions below (fetches, header render, post-switch empty hint).
	$owns_chrome = snt_analytics_view_owns_chrome( $view );

	// ── Persistent header (shared-chrome tabs only): the at-a-glance headline.
	if ( ! $owns_chrome ) {
		$totals       = sn_analytics_range_totals( $from, $to, $class );
		$class_totals = sn_analytics_class_totals( $from, $to );
		$now          = sn_analytics_realtime( $class );
		$series       = sn_analytics_daily_series( $from, $to, $class, $granularity );
		$deltas       = ( 'all' === $range ) ? array() : sn_analytics_period_deltas( $from, $to, $class );
		$engaged      = ( 'all' === $range )
			? array( 'current' => sn_analytics_engaged_rate( $from, $to, $class ) )
			: sn_analytics_engaged_rate_delta( $from, $to, $class );
	}

	snt_analytics_render_error(); // AE diagnostic (admins only) — always, every view.

	if ( ! $owns_chrome ) {
		snt_analytics_render_controls( $range, $class, $from, $to );
		snt_analytics_render_separation( $class_totals, $class );

		// v6.5.2: the KPI strip + daily-views chart are fused into ONE "Overview" panel
		// (was two half-empty postboxes). render_cards / render_trend now emit body-only
		// markup; the postbox chrome lives here so the chart reads as the panel's footer.
		echo '<div class="postbox sn-overview"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Overview', 'signal-and-noise-tools' ) . '</span></h2></div>';
		echo '<div class="inside inside-flush sn-overview-inside">';
		snt_analytics_render_cards( $now, $totals, $deltas, $engaged );
		snt_analytics_render_trend( $series, $granularity );
		echo '</div></div>';
	} elseif ( 'login-defense' === $view && function_exists( 'sn_login_defense_render_header' ) ) {
		// The chrome-owning view renders its OWN header (range + Overview + breakdown)
		// here, ABOVE the tabs, so the frame matches the pageview views (no tab-bar jump).
		sn_login_defense_render_header();
	}

	// ── Tabs + the active view's panels. Each view fetches ONLY its own data,
	// so a tab switch is a lighter query set, not just CSS show/hide.
	snt_analytics_render_view_tabs( $view, $range, $class, $from, $to );

	echo '<div class="sn-an-view">';

	// Cross-tab drill-down: ?sn_drill=<dim>:<value> → "Top pages where <dim>=<value>"
	// (on-demand AE, whitelisted + cached). The panel renders ONLY on the view that
	// owns the drilled dim — so a stale drill carried onto another tab shows nothing
	// (no orphan panel above a view with no such table).
	$sn_drill_dims = array(
		'technology' => array( 'browser', 'os', 'device', 'protocol', 'tls' ),
		'geography'  => array( 'country', 'city', 'region', 'network', 'colo', 'timezone' ),
		'content'    => array( 'referrer' ),
	);
	$sn_drill = isset( $_GET['sn_drill'] ) ? sn_analytics_drilldown_parse( sanitize_text_field( wp_unslash( $_GET['sn_drill'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter on an admin report, no state change.
	if ( null !== $sn_drill && in_array( $sn_drill[0], $sn_drill_dims[ $view ] ?? array(), true ) ) {
		$drill_note = ( strtotime( (string) $to ) - strtotime( (string) $from ) > 90 * DAY_IN_SECONDS )
			? '(reflects the last ~90 days — Analytics Engine raw retention)'
			: '';
		snt_analytics_render_drilldown_panel( $sn_drill[0], $sn_drill[1], sn_analytics_drilldown( $sn_drill[0], $sn_drill[1], $from, $to, $class ), $drill_note );
	}

	switch ( $view ) {
		case 'posts':
			// Post-lifecycle view: hero + trajectory + catalog + velocity/decay.
			// Manages its own layout (hero/trajectory full-width, then a grid).
			snt_analytics_render_posts_view( sn_analytics_posts_bundle() );
			break;

		case 'technology':
			echo '<div class="sn-an-grid">';
			$brow_rows = sn_analytics_top_dimension( 'browser', $from, $to, $class, 10 );
			$brow_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $brow_rows );
			$brow_ser  = sn_analytics_dimension_series( 'browser', $brow_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Browsers', $brow_rows, 'No browser data in this range yet.', $brow_ser, 'browser' );
			$os_rows = sn_analytics_top_dimension( 'os', $from, $to, $class, 10 );
			$os_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $os_rows );
			$os_ser  = sn_analytics_dimension_series( 'os', $os_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Operating systems', $os_rows, 'No OS data in this range yet.', $os_ser, 'os' );
			$dev_rows = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );
			$dev_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $dev_rows );
			$dev_ser  = sn_analytics_dimension_series( 'device', $dev_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Devices', $dev_rows, 'No device data in this range.', $dev_ser, 'device' );
			$pro_rows = sn_analytics_top_dimension( 'protocol', $from, $to, $class, 10 );
			$pro_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $pro_rows );
			$pro_ser  = sn_analytics_dimension_series( 'protocol', $pro_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Protocols', $pro_rows, 'No protocol data in this range yet.', $pro_ser, 'protocol' );
			$tls_rows = sn_analytics_top_dimension( 'tls', $from, $to, $class, 10 );
			$tls_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $tls_rows );
			$tls_ser  = sn_analytics_dimension_series( 'tls', $tls_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'TLS versions', $tls_rows, 'No TLS data in this range yet.', $tls_ser, 'tls' );
			echo '</div>';
			break;

		case 'geography':
			echo '<div class="sn-geo">';
			echo '<div class="sn-geo-split">';
			snt_analytics_render_choropleth( 'World map', sn_analytics_top_dimension( 'country', $from, $to, $class, 250 ), 'No country data in this range yet.' );
			snt_analytics_render_dim_table( 'Countries', sn_analytics_top_dimension( 'country', $from, $to, $class, 10 ), 'No country data in this range.', array(), 'country' );
			echo '</div>';
			echo '<div class="sn-geo-tiles" style="margin-top:20px">';
			snt_analytics_render_dim_table( 'Cities', sn_analytics_top_dimension( 'city', $from, $to, $class, 10 ), 'No city data in this range yet.', array(), 'city' );
			snt_analytics_render_dim_table( 'Regions', sn_analytics_top_dimension( 'region', $from, $to, $class, 10 ), 'No region data in this range yet.', array(), 'region' );
			snt_analytics_render_dim_table( 'Networks', sn_analytics_top_dimension( 'network', $from, $to, $class, 10 ), 'No network data in this range yet.', array(), 'network' );
			snt_analytics_render_dim_table( 'Edge locations', sn_analytics_top_dimension( 'colo', $from, $to, $class, 10 ), 'No edge-location data in this range yet.', array(), 'colo' );
			// v6.27.0: visitor IANA timezone (worker v1.7.0, blob19) — the "when/where
			// my audience reads" signal, finer than country. Empty until the worker ships.
			snt_analytics_render_dim_table( 'Time zones', sn_analytics_top_dimension( 'timezone', $from, $to, $class, 10 ), 'No timezone data yet (needs worker v1.7.0 + traffic).', array(), 'timezone' );
			echo '</div></div>';
			break;

		case 'edge':
			// Server-side Cloudflare edge analytics (GraphQL) — not class-segmented
			// and not drillable (no per-page AE join); its own dormant gate.
			snt_edge_render_view( $from, $to );
			break;

		case 'engagement':
			snt_analytics_render_heatmap( sn_analytics_hour_dow_grid( $from, $to, $class ) );
			echo '<div class="sn-an-grid">';
			snt_analytics_render_distribution( 'Scroll depth', sn_analytics_distribution( 'scroll', $from, $to, $class ) );
			snt_analytics_render_distribution( 'Time on page', sn_analytics_distribution( 'time', $from, $to, $class ) );
			// v6.27.0: connection RTT (worker v1.7.0, double4 = clientTcpRtt). TCP-only —
			// HTTP/3 / QUIC requests carry no RTT, so the distribution measures HTTP/1–2.
			snt_analytics_render_distribution( 'Connection RTT', sn_analytics_distribution( 'rtt', $from, $to, $class ), 'No TCP round-trips in this range — HTTP/3 connections carry no RTT, so only HTTP/1–2 visitors are measured (needs worker v1.7.0 + traffic).' );
			echo '</div>';
			$pctl_note  = ( strtotime( (string) $to ) - strtotime( (string) $from ) > 90 * DAY_IN_SECONDS )
				? '(reflects the last ~90 days — Analytics Engine raw retention)'
				: '';
			$pctl_empty = 'Percentiles need live Analytics Engine data for this window.';
			echo '<div class="sn-an-grid">';
			snt_analytics_render_percentiles( 'Scroll depth — percentiles', sn_analytics_percentiles( 'scroll', $from, $to, $class ), 'pct', $pctl_empty, $pctl_note );
			snt_analytics_render_percentiles( 'Time on page — percentiles', sn_analytics_percentiles( 'time', $from, $to, $class ), 'time', $pctl_empty, $pctl_note );
			echo '</div>';
			// v6.28.0: field Core Web Vitals — real-user LCP/INP/CLS in Google's
			// good/needs-work/poor bands (worker v1.8.0 / theme beacon v10.14.0).
			// Empty until those ship + traffic flows.
			$cwv_empty = 'No field Core Web Vitals yet — needs the web-vitals beacon (theme v10.14.0) + worker v1.8.0 + traffic.';
			echo '<p class="sn-an-sep sn-an-sep--full">Field Core Web Vitals — what real visitors experienced (vs the synthetic Lighthouse lab score).</p>';
			echo '<div class="sn-an-grid">';
			snt_analytics_render_distribution( 'LCP (field)', sn_analytics_distribution( 'lcp', $from, $to, $class ), $cwv_empty );
			snt_analytics_render_distribution( 'INP (field)', sn_analytics_distribution( 'inp', $from, $to, $class ), $cwv_empty );
			snt_analytics_render_distribution( 'CLS (field)', sn_analytics_distribution( 'cls', $from, $to, $class ), $cwv_empty );
			echo '</div>';
			break;

		case 'quality':
			snt_analytics_render_bot_trend( sn_analytics_class_series( $from, $to, $granularity ) );
			snt_analytics_render_bot_breakdown( sn_analytics_bot_breakdown( $from, $to ) );
			snt_analytics_render_distribution(
				'Bot confidence',
				sn_analytics_distribution( 'botscore', $from, $to, $class ),
				'No bot-confidence scores in this range — needs traffic recorded with Cloudflare Bot Management enabled (scores arrive as 1–99).'
			);
			break;

		case 'events':
			// Custom events carry no traffic-class dimension (from/to only), so the
			// global Human/Suspect/Bot control is inert here — say so explicitly.
			echo '<p class="sn-an-sep">Custom events are <strong>not segmented by traffic class</strong> — the class filter above does not apply to this view.</p>';
			$ev_prop = isset( $_GET['sn_event_prop'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_event_prop'] ) ) : '';
			echo '<div class="sn-an-grid">';
			snt_analytics_render_events_table( sn_analytics_top_events( $from, $to, 25 ) );
			snt_analytics_render_event_props_table( sn_analytics_top_event_props( $from, $to, $ev_prop, 50 ), $ev_prop );
			echo '</div>';
			break;

		case 'login-defense':
			sn_login_defense_render_body();
			break;

		case 'content':
		default:
			echo '<div class="sn-an-grid">';
			snt_analytics_render_paths_table( sn_analytics_top_paths( $from, $to, $class, 25 ) );
			// Brand-folded sources (self-referrals + www + multi-host providers
			// collapsed); the sparkline series is summed across each label's member
			// hosts, and the drill token carries the canonical label (resolved back
			// to its member hosts by the brand-aware referrer drill-down).
			$ref_rows = sn_analytics_top_sources( $from, $to, $class, 10 );
			$ref_ser  = sn_analytics_top_sources_series( $ref_rows, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Top sources', $ref_rows, 'No referrers in this range.', $ref_ser, 'referrer' );
			snt_analytics_render_referrer_categories( sn_analytics_referrer_categories( $from, $to, $class ) );
			snt_analytics_render_lowengage( sn_analytics_low_engagement_paths( $from, $to, $class ) );
			// v6.10.0: entry (landing) + exit pages. Both are HUMAN-ONLY — the
			// global Human/Suspect/Bot control does not apply (no class column),
			// consistent with the human-only Plausible history. Entry = live AE
			// rollup + historical import merged; exit = historical only.
			if ( function_exists( 'snt_analytics_render_pageroles_table' ) && function_exists( 'sn_analytics_top_entry_pages' ) ) {
				echo '<p class="sn-an-sep sn-an-sep--full">Entry &amp; exit pages are <strong>not segmented by traffic class</strong> (human only).</p>';
				snt_analytics_render_pageroles_table( sn_analytics_top_entry_pages( $from, $to, 25 ), 'entry' );
				snt_analytics_render_pageroles_table( sn_analytics_top_exit_pages( $from, $to, 25 ), 'exit' );
			}
			echo '</div>';
			break;
	}
	echo '</div>';

	// Empty hint when configured but the tables are still dormant — keyed on the
	// always-fetched totals, so it shows on whichever tab you land on first. Gated on
	// ! $owns_chrome: $totals is unset for chrome-owning views (login-defense brings
	// its own empty states), so reading it there would warn + show a false notice.
	if ( ! $owns_chrome && (int) ( $totals['views'] ?? 0 ) === 0 ) {
		echo '<p class="sn-an-empty">No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.</p>';
	}
}

/**
 * The Monitoring → Analytics settings section: the credential form + Worker
 * setup console (snt_analytics_render_settings), prefixed with a backlink to the
 * read-only dashboard. The form posts on the page=sn-theme-options route (the
 * Monitoring sub-tab nav guarantees that slug), so the existing admin-post
 * handler processes analytics_save/analytics_test unchanged.
 */
function snt_analytics_render_settings_section() {
	echo '<p class="sn-an-settings-help">First-party analytics credentials. The comprehensive read-only dashboard lives under <strong>Dashboard &rarr; Analytics</strong>.</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">View dashboard &rarr;</a></p>';
	snt_analytics_render_settings();
	if ( function_exists( 'snt_analytics_render_import' ) ) {
		snt_analytics_render_import();
	}
}

/**
 * Unconfigured notice shown above the settings form when creds are missing.
 * Points users to the form rendered immediately after this notice, and mentions
 * the wp-config-constant alternative for those who prefer it.
 *
 * @param string $reason 'unconfigured'.
 */
function snt_analytics_render_empty( $reason ) {
	echo '<div class="notice notice-info notice-alt inline"><p><strong>Analytics isn\'t receiving data yet.</strong> ';
	echo 'Add your Cloudflare read credentials below to connect the dashboard. You can also set ';
	echo '<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code> in <code>wp-config.php</code> ';
	echo '(see <em>Cloudflare Worker setup</em> below).</p></div>';
}

/**
 * Surface the last AE read error (admins only) so a blank dashboard is debuggable.
 */
function snt_analytics_render_error() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'sn_analytics_last_error' ) ) {
		return;
	}
	$err = sn_analytics_last_error();
	if ( ! $err || ! is_array( $err ) ) {
		return;
	}
	$code = isset( $err['code'] ) && (int) $err['code'] > 0 ? ( 'HTTP ' . (int) $err['code'] ) : 'Network error';
	echo '<div class="notice notice-error notice-alt inline"><p><strong>Analytics read failed.</strong> ' . esc_html( $code );
	if ( ! empty( $err['url'] ) ) {
		echo ' from <code>' . esc_html( (string) $err['url'] ) . '</code>';
	}
	if ( ! empty( $err['message'] ) ) {
		echo '<br>' . esc_html( (string) $err['message'] );
	}
	echo '</p></div>';
}
