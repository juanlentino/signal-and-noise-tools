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
	'technology' => 'Technology',
	'geography'  => 'Geography',
	'engagement' => 'Engagement',
	'quality'    => 'Quality',
	'events'     => 'Events',
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
function snt_analytics_render_view_tabs( $active, $range, $class ) {
	$base = remove_query_arg( array( 'sn_view', 'sn_range', 'sn_class' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'index.php?page=sn-analytics' );
	}
	echo '<nav class="nav-tab-wrapper sn-an-view-tabs" aria-label="Analytics views">';
	foreach ( SN_ANALYTICS_VIEWS as $slug => $label ) {
		$url   = add_query_arg( array( 'sn_view' => $slug, 'sn_range' => $range, 'sn_class' => $class ), $base );
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
	snt_analytics_styles();

	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range = snt_analytics_resolve_range( isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7' );
	$class = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );
	$view  = snt_analytics_resolve_view( isset( $_GET['sn_view'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_view'] ) ) : 'content' );

	// Config gate: empty notice + a link to the settings page (the form lives there now).
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		snt_analytics_render_empty( 'unconfigured' );
		echo '<p><a class="button button-primary" href="' . esc_url( snt_analytics_settings_url() ) . '">Configure analytics &rarr;</a></p>';
		return;
	}

	list( $from, $to ) = snt_analytics_range_dates( $range );

	$gran_days   = ( 'all' === $range )
		? ( (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1 )
		: (int) $range;
	$granularity = sn_analytics_granularity( $gran_days );

	// ── Persistent header (every tab): the at-a-glance headline. Always fetched.
	$totals       = sn_analytics_range_totals( $from, $to, $class );
	$class_totals = sn_analytics_class_totals( $from, $to );
	$now          = sn_analytics_realtime( $class );
	$series       = sn_analytics_daily_series( $from, $to, $class, $granularity );
	$deltas       = ( 'all' === $range ) ? array() : sn_analytics_period_deltas( $from, $to, $class );
	$engaged      = ( 'all' === $range )
		? array( 'current' => sn_analytics_engaged_rate( $from, $to, $class ) )
		: sn_analytics_engaged_rate_delta( $from, $to, $class );

	snt_analytics_render_error(); // AE diagnostic (admins only), above the data.
	snt_analytics_render_controls( $range, $class );
	snt_analytics_render_separation( $class_totals, $class );
	snt_analytics_render_cards( $now, $totals, $deltas, $engaged );
	snt_analytics_render_trend( $series, $granularity );

	// ── Tabs + the active view's panels. Each view fetches ONLY its own data,
	// so a tab switch is a lighter query set, not just CSS show/hide.
	snt_analytics_render_view_tabs( $view, $range, $class );

	echo '<div class="sn-an-view">';
	switch ( $view ) {
		case 'technology':
			echo '<div class="sn-an-grid">';
			$brow_rows = sn_analytics_top_dimension( 'browser', $from, $to, $class, 10 );
			$brow_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $brow_rows );
			$brow_ser  = sn_analytics_dimension_series( 'browser', $brow_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Browsers', $brow_rows, 'No browser data in this range yet.', $brow_ser );
			$os_rows = sn_analytics_top_dimension( 'os', $from, $to, $class, 10 );
			$os_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $os_rows );
			$os_ser  = sn_analytics_dimension_series( 'os', $os_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Operating systems', $os_rows, 'No OS data in this range yet.', $os_ser );
			$dev_rows = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );
			$dev_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $dev_rows );
			$dev_ser  = sn_analytics_dimension_series( 'device', $dev_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Devices', $dev_rows, 'No device data in this range.', $dev_ser );
			$pro_rows = sn_analytics_top_dimension( 'protocol', $from, $to, $class, 10 );
			$pro_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $pro_rows );
			$pro_ser  = sn_analytics_dimension_series( 'protocol', $pro_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Protocols', $pro_rows, 'No protocol data in this range yet.', $pro_ser );
			$tls_rows = sn_analytics_top_dimension( 'tls', $from, $to, $class, 10 );
			$tls_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $tls_rows );
			$tls_ser  = sn_analytics_dimension_series( 'tls', $tls_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'TLS versions', $tls_rows, 'No TLS data in this range yet.', $tls_ser );
			echo '</div>';
			break;

		case 'geography':
			snt_analytics_render_choropleth( 'Countries map', sn_analytics_top_dimension( 'country', $from, $to, $class, 250 ), 'No country data in this range yet.' );
			echo '<div class="sn-an-grid">';
			$cou_rows = sn_analytics_top_dimension( 'country', $from, $to, $class, 10 );
			$cou_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $cou_rows );
			$cou_ser  = sn_analytics_dimension_series( 'country', $cou_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Countries', $cou_rows, 'No country data in this range.', $cou_ser );
			$cit_rows = sn_analytics_top_dimension( 'city', $from, $to, $class, 10 );
			$cit_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $cit_rows );
			$cit_ser  = sn_analytics_dimension_series( 'city', $cit_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Cities', $cit_rows, 'No city data in this range yet.', $cit_ser );
			$reg_rows = sn_analytics_top_dimension( 'region', $from, $to, $class, 10 );
			$reg_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $reg_rows );
			$reg_ser  = sn_analytics_dimension_series( 'region', $reg_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Regions', $reg_rows, 'No region data in this range yet.', $reg_ser );
			$net_rows = sn_analytics_top_dimension( 'network', $from, $to, $class, 10 );
			$net_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $net_rows );
			$net_ser  = sn_analytics_dimension_series( 'network', $net_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Networks', $net_rows, 'No network data in this range yet.', $net_ser );
			$colo_rows = sn_analytics_top_dimension( 'colo', $from, $to, $class, 10 );
			$colo_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $colo_rows );
			$colo_ser  = sn_analytics_dimension_series( 'colo', $colo_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Edge locations', $colo_rows, 'No edge-location data in this range yet.', $colo_ser );
			echo '</div>';
			break;

		case 'engagement':
			snt_analytics_render_heatmap( sn_analytics_hour_dow_grid( $from, $to, $class ) );
			echo '<div class="sn-an-grid">';
			snt_analytics_render_distribution( 'Scroll depth', sn_analytics_distribution( 'scroll', $from, $to, $class ) );
			snt_analytics_render_distribution( 'Time on page', sn_analytics_distribution( 'time', $from, $to, $class ) );
			echo '</div>';
			break;

		case 'quality':
			snt_analytics_render_bot_trend( sn_analytics_class_series( $from, $to, $granularity ) );
			snt_analytics_render_bot_breakdown( sn_analytics_bot_breakdown( $from, $to ) );
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

		case 'content':
		default:
			echo '<div class="sn-an-grid">';
			snt_analytics_render_paths_table( sn_analytics_top_paths( $from, $to, $class, 25 ) );
			$ref_rows = sn_analytics_top_dimension( 'referrer', $from, $to, $class, 10 );
			$ref_vals = array_map( static function ( $r ) { return (string) $r['value']; }, $ref_rows );
			$ref_ser  = sn_analytics_dimension_series( 'referrer', $ref_vals, $from, $to, $class, $granularity );
			snt_analytics_render_dim_table( 'Top sources', $ref_rows, 'No referrers in this range.', $ref_ser );
			snt_analytics_render_referrer_categories( sn_analytics_referrer_categories( $from, $to, $class ) );
			snt_analytics_render_lowengage( sn_analytics_low_engagement_paths( $from, $to, $class ) );
			echo '</div>';
			break;
	}
	echo '</div>';

	// Empty hint when configured but the tables are still dormant — keyed on the
	// always-fetched totals, so it shows on whichever tab you land on first.
	if ( (int) ( $totals['views'] ?? 0 ) === 0 ) {
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
	snt_analytics_styles();
	echo '<p class="sn-an-settings-help">First-party analytics credentials. The comprehensive read-only dashboard lives under <strong>Dashboard &rarr; Analytics</strong>.</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">View dashboard &rarr;</a></p>';
	snt_analytics_render_settings();
	if ( function_exists( 'snt_analytics_render_import' ) ) {
		snt_analytics_render_import();
	}
}

/**
 * Inline CSS — native wp-admin palette only (no theme fonts/colors).
 */
function snt_analytics_styles() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<style>
	/* WP admin native: #1d2327 text · #646970 muted · #2271b1 link · #dcdcde hairline · #d63638 error.
	   wp-admin already ships .postbox, .widefat, .nav-tab, .button, .notice core CSS — this block
	   emits ONLY the custom dense-layout additions those core classes don't provide. */

	/* ── postbox / widefat tweaks ───────────────────────────────────────────── */
	.postbox .inside.inside-flush { padding: 0; }
	.widefat .num { text-align: right; }

	/* ── toolbar row: title-inline controls ─────────────────────────────────── */
	.sn-toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 16px;
		margin: 6px 0 4px;
	}
	.sn-toolbar .sn-control-group {
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.sn-control-label {
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.02em;
		color: #646970;
		font-weight: 600;
	}
	.sn-toolbar-spacer { flex: 1 1 auto; }

	/* ── dense fused KPI strip ──────────────────────────────────────────────── */
	.sn-kpi-strip { padding: 0; }
	.sn-kpi-row {
		display: grid;
		grid-template-columns: 1.25fr 1.25fr 1fr 1fr 1fr 1fr;
		border-top: 1px solid #dcdcde;
	}
	.sn-kpi {
		padding: 12px 14px;
		border-right: 1px solid #dcdcde;
		border-bottom: 1px solid #dcdcde;
		min-width: 0;
	}
	.sn-kpi:last-child { border-right: none; }
	.sn-kpi-promoted { background: #fbfbfc; }
	.sn-kpi-label {
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		color: #646970;
		font-weight: 600;
		margin: 0 0 4px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.sn-kpi-value {
		font-size: 24px;
		font-weight: 400;
		color: #1d2327;
		line-height: 1.1;
		margin: 0;
	}
	.sn-kpi-promoted .sn-kpi-value { font-size: 28px; }
	.sn-kpi-delta {
		display: inline-block;
		margin-top: 5px;
		font-size: 12px;
		font-weight: 600;
		line-height: 1.3;
	}
	.sn-delta-down { color: #d63638; }
	.sn-delta-up   { color: #00a32a; }
	.sn-delta-flat { color: #646970; font-weight: 400; }
	.sn-delta-arrow { font-size: 11px; vertical-align: middle; }

	/* ── slim sparkline trend ───────────────────────────────────────────────── */
	.sn-trend-inside { padding: 0; }
	.sn-trend-head {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		padding: 10px 12px 6px;
	}
	.sn-trend-head .sn-trend-title { font-size: 12px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
	.sn-trend-head .sn-trend-meta  { font-size: 12px; color: #646970; }
	.sn-spark-wrap { padding: 0 6px 8px; }
	.sn-spark { display: block; width: 100%; height: 84px; }
	.sn-spark-axis {
		display: flex;
		justify-content: space-between;
		padding: 2px 12px 8px;
		font-size: 11px;
		color: #787c82;
	}

	/* ── geography: map + countries side-by-side ────────────────────────────── */
	.sn-geo-split {
		display: grid;
		grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
		gap: 20px;
		align-items: stretch;
	}
	.sn-map-inside  { padding: 0; }
	.sn-map-figure  { margin: 0; padding: 10px; }
	.sn-map-svg     { display: block; width: 100%; height: auto; }
	.sn-map-legend {
		display: flex;
		align-items: center;
		gap: 14px;
		padding: 4px 12px 10px;
		font-size: 11px;
		color: #646970;
		flex-wrap: wrap;
	}
	.sn-legend-item   { display: inline-flex; align-items: center; gap: 5px; }
	.sn-legend-swatch { width: 14px; height: 12px; display: inline-block; border: 1px solid #c3c4c7; }

	/* ── tiled geo tables below the split ───────────────────────────────────── */
	.sn-geo-tiles {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 20px;
	}

	/* ── responsive collapse ─────────────────────────────────────────────────── */
	@media screen and (max-width: 1100px) {
		.sn-kpi-row { grid-template-columns: repeat(3, 1fr); }
		.sn-kpi:nth-child(3n) { border-right: none; }
	}
	@media screen and (max-width: 860px) {
		.sn-geo-split  { grid-template-columns: 1fr; }
		.sn-geo-tiles  { grid-template-columns: 1fr; }
	}
	@media screen and (max-width: 600px) {
		.sn-kpi-row { grid-template-columns: repeat(2, 1fr); }
		.sn-kpi:nth-child(3n) { border-right: 1px solid #dcdcde; }
		.sn-kpi:nth-child(2n) { border-right: none; }
	}

	/* ── layout helpers still consumed by current render fns ────────────────── */
	.sn-an-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
	.sn-an-view-tabs { margin: 18px 0 0; }
	.sn-an-view { margin-top: 16px; }
	.sn-an-empty { color: #646970; font-style: italic; font-size: 13px; margin-top: 16px; }
	.sn-an-sep { color: #646970; font-size: 13px; margin: 0 0 14px; }

	/* ── bespoke viz (still rendered by current panel fns; cleaned in Task 8) ─ */
	.sn-an-delta { font-size: 0.72em; font-weight: 600; margin-left: 5px; white-space: nowrap; }
	.sn-an-delta--up   { color: #0a7c2f; }
	.sn-an-delta--down { color: #b32d2e; }
	.sn-an-delta--flat { color: #646970; }
	.sn-an-refcats-bars { display: flex; flex-direction: column; gap: 8px; }
	.sn-an-refcat-h { display: flex; justify-content: space-between; font-size: 13px; color: #1d2327; margin-bottom: 3px; }
	.sn-an-refcat-bar { height: 8px; background: #f0f0f1; border-radius: 4px; overflow: hidden; }
	.sn-an-refcat-bar span { display: block; height: 100%; background: #2271b1; border-radius: 4px; }
	.sn-an-dist-bars { display: flex; flex-direction: column; gap: 7px; }
	.sn-an-dist-row { display: flex; align-items: center; gap: 8px; font-size: 13px; }
	.sn-an-dist-l { flex: 0 0 72px; color: #646970; }
	.sn-an-dist-bar { flex: 1; height: 10px; background: #f0f0f1; border-radius: 5px; overflow: hidden; }
	.sn-an-dist-bar span { display: block; height: 100%; background: #2271b1; border-radius: 5px; }
	.sn-an-dist-n { flex: 0 0 auto; }
	.sn-an-heatmap { display: flex; flex-direction: column; gap: 3px; overflow-x: auto; }
	.sn-an-hm-row { display: flex; align-items: center; gap: 3px; }
	.sn-an-hm-day { flex: 0 0 32px; font-size: 11px; color: #646970; }
	.sn-an-hm-cell { flex: 1; min-width: 9px; height: 14px; background: #f0f0f1; border-radius: 2px; }
	.sn-an-quality-bar { display: flex; height: 16px; border-radius: 4px; overflow: hidden; margin: 0 0 8px; background: #f0f0f1; }
	.sn-an-q { display: block; height: 100%; }
	.sn-an-q--human   { background: #2271b1; }
	.sn-an-q--suspect { background: #dba617; }
	.sn-an-q--bot     { background: #d63638; }
	.sn-an-q-legend { font-size: 12px; color: #646970; margin: 0 0 10px; }
	.sn-an-q-key { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 2px; }
	.sn-an-subh { font-size: 12px; color: #646970; margin: 10px 0 4px; }
	/* per-dimension inline sparkline bars (dim tables) */
	.sn-an-spark { display: inline-flex; align-items: flex-end; gap: 1px; height: 1.1em; }
	.sn-an-spark .b { width: 2px; background: currentColor; opacity: .45; }
	.sn-an-spark--empty { opacity: .2; }
	/* bot-share trend bar modifier */
	.sn-an-trend--bot .bar { opacity: .7; }
	/* choropleth panel — still used until Task 6 restructures geography */
	.sn-an-choropleth { max-width: 720px; }
	.sn-an-choropleth-map { margin-top: 4px; }
	.sn-an-choropleth-map svg { width: 100%; max-width: 100%; height: auto; display: block; }
	.sn-an-choropleth-map path { stroke: #fff; stroke-width: 0.3; }
	/* old trend bars — still emitted until Task 4 replaces snt_analytics_render_trend() */
	.sn-an-trend { display: flex; align-items: flex-end; gap: 3px; height: 48px; margin: 0 0 18px; }
	.sn-an-trend .bar { flex: 1; background: #2271b1; min-height: 2px; border-radius: 2px 2px 0 0; }
	/* old card grid — still emitted until Task 3 replaces snt_analytics_render_cards() */
	.sn-an-cards { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 12px; margin: 0 0 20px; }
	.sn-an-card { border: 1px solid #dcdcde; border-radius: 6px; padding: 12px; background: #fff; }
	.sn-an-card .n { font-size: 1.5rem; font-weight: 600; color: #1d2327; line-height: 1.1; font-variant-numeric: tabular-nums; }
	.sn-an-card .l { font-size: 0.8em; color: #646970; margin-top: 3px; }
	/* old controls/seg — still emitted until Task 7 replaces render_controls() */
	.sn-an-controls { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin: 4px 0 14px; }
	.sn-an-seg { display: inline-flex; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden; }
	.sn-an-seg a { padding: 4px 12px; font-size: 13px; text-decoration: none; color: #2271b1; background: #fff; border-right: 1px solid #c3c4c7; }
	.sn-an-seg a:last-child { border-right: 0; }
	.sn-an-seg a.is-active { background: #2271b1; color: #fff; }
	/* old panel/table styles — still emitted until Task 5 migrates render fns */
	.sn-an-panel { border: 1px solid #dcdcde; border-radius: 6px; padding: 14px; background: #fff; }
	.sn-an-panel h3 { margin: 0 0 8px; font-size: 13px; color: #1d2327; }
	.sn-an-table { width: 100%; border-collapse: collapse; font-size: 13px; }
	.sn-an-table th { text-align: left; color: #646970; font-weight: 400; border-bottom: 1px solid #f0f0f1; padding: 5px 0; }
	.sn-an-table td { border-bottom: 1px solid #f0f0f1; padding: 5px 0; color: #1d2327; }
	.sn-an-table td.num, .sn-an-table th.num { text-align: right; font-variant-numeric: tabular-nums; color: #646970; }
	.sn-an-table tr:last-child td { border-bottom: 0; }
	@media (max-width: 782px) {
		.sn-an-cards { grid-auto-flow: row; grid-template-columns: repeat(2, 1fr); }
		.sn-an-grid  { grid-template-columns: 1fr; }
	}

	/* ── settings surface (Monitoring → Analytics creds form) ───────────────── */
	.sn-an-settings { max-width: 640px; }
	.sn-an-settings-help { color: #646970; font-size: 13px; margin: .25rem 0 1rem; }
	.sn-an-worker { margin-top: 16px; border: 1px solid #dcdcde; border-radius: 6px; padding: 10px 14px; background: #fff; }
	.sn-an-worker summary { cursor: pointer; font-weight: 600; font-size: 13px; color: #1d2327; }
	.sn-an-steps { margin: 10px 0 0; padding-left: 20px; font-size: 13px; color: #1d2327; }
	.sn-an-steps li { margin: 6px 0; }
	.sn-an-pre { background: #f6f7f7; border: 1px solid #e0e0e0; padding: 8px 10px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; white-space: pre; overflow: auto; }
	.sn-an-worker[open] summary { margin-bottom: 6px; }
	</style>
	<?php
}

/**
 * Unconfigured notice shown above the settings form when creds are missing.
 * Points users to the form rendered immediately after this notice, and mentions
 * the wp-config-constant alternative for those who prefer it.
 *
 * @param string $reason 'unconfigured'.
 */
function snt_analytics_render_empty( $reason ) {
	snt_analytics_styles();
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
