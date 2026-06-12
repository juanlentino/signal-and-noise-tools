<?php
/**
 * Signal & Noise — Monitoring → Analytics tab.
 *
 * Native wp-admin surface (no theme vocabulary) for the first-party edge
 * analytics. Reads only the durable rollup accessors (never AE) so it never
 * blocks; shows a config/empty state until the Cloudflare creds + worker land.
 * Renders: separation line → trend → stat cards → 2×2 breakdown grid.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_RANGES = array( 7, 30, 90 );

/**
 * Whitelist the ?sn_range GET value to a supported window; default 7.
 *
 * @param mixed $raw
 * @return int 7 | 30 | 90
 */
function snt_analytics_resolve_range( $raw ) {
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

/**
 * Inclusive [$from,$to] YYYY-MM-DD window of $days ending on the anchor day.
 * UTC (gmdate) to align with AE's toStartOfDay() buckets. $now is injectable
 * for deterministic tests.
 *
 * @param int      $days
 * @param int|null $now Unix timestamp anchor (defaults to now).
 * @return array{0:string,1:string} [$from, $to]
 */
function snt_analytics_range_dates( $days, $now = null ) {
	$now  = ( null === $now ) ? time() : (int) $now;
	$to   = gmdate( 'Y-m-d', $now );
	$from = gmdate( 'Y-m-d', $now - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
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
 * Render the comprehensive READ-ONLY analytics dashboard (v5.4.0). Lives on the
 * native WP Dashboard → Analytics page (inc/analytics-dashboard-page.php); the
 * credential settings are split out to Monitoring → Analytics
 * (snt_analytics_render_settings_section). No <h1>/<h2> heading or settings form
 * here — the page chrome owns the title, and the read view carries no form.
 *
 * Sections: controls + separation + delta cards + trend, then Top content,
 * Technology, Geography & network, Engagement (heatmap + distributions), and
 * Traffic quality. Every dimension/derived panel renders its own empty state
 * until the edge data accrues (worker v1.1.0 — no backfill).
 */
function snt_analytics_render_dashboard() {
	snt_analytics_styles();

	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range = snt_analytics_resolve_range( isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7' );
	$class = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );

	// Config gate: empty notice + a link to the settings page (the form lives there now).
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		snt_analytics_render_empty( 'unconfigured' );
		echo '<p><a class="button button-primary" href="' . esc_url( snt_analytics_settings_url() ) . '">Configure analytics &rarr;</a></p>';
		return;
	}

	list( $from, $to ) = snt_analytics_range_dates( $range );

	// Core aggregates.
	$totals       = sn_analytics_range_totals( $from, $to, $class );
	$class_totals = sn_analytics_class_totals( $from, $to );
	$now          = sn_analytics_realtime( $class );
	$series       = sn_analytics_daily_series( $from, $to, $class );
	$paths        = sn_analytics_top_paths( $from, $to, $class, 25 );
	$deltas       = sn_analytics_period_deltas( $from, $to, $class );

	// Dimension breakdowns (the 11 dims — 3 original + 8 edge).
	$referrers = sn_analytics_top_dimension( 'referrer', $from, $to, $class, 10 );
	$countries = sn_analytics_top_dimension( 'country', $from, $to, $class, 10 );
	$devices   = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );
	$browsers  = sn_analytics_top_dimension( 'browser', $from, $to, $class, 10 );
	$os        = sn_analytics_top_dimension( 'os', $from, $to, $class, 10 );
	$cities    = sn_analytics_top_dimension( 'city', $from, $to, $class, 10 );
	$regions   = sn_analytics_top_dimension( 'region', $from, $to, $class, 10 );
	$networks  = sn_analytics_top_dimension( 'network', $from, $to, $class, 10 );
	$colos     = sn_analytics_top_dimension( 'colo', $from, $to, $class, 10 );
	$protocols = sn_analytics_top_dimension( 'protocol', $from, $to, $class, 10 );
	$tls       = sn_analytics_top_dimension( 'tls', $from, $to, $class, 10 );

	// Derived views.
	$ref_cats    = sn_analytics_referrer_categories( $from, $to, $class );
	$heatmap     = sn_analytics_hour_dow_grid( $from, $to, $class );
	$scroll_dist = sn_analytics_distribution( 'scroll', $from, $to, $class );
	$time_dist   = sn_analytics_distribution( 'time', $from, $to, $class );
	$bot         = sn_analytics_bot_breakdown( $from, $to );

	// AE error diagnostic (admins only), above the data.
	snt_analytics_render_error();

	snt_analytics_render_controls( $range, $class );
	snt_analytics_render_separation( $class_totals, $class );
	snt_analytics_render_cards( $now, $totals, $deltas );
	snt_analytics_render_trend( $series );

	echo '<h2 class="sn-section-h">Top content</h2>';
	echo '<div class="sn-an-grid">';
	snt_analytics_render_paths_table( $paths );
	snt_analytics_render_dim_table( 'Top sources', $referrers, 'No referrers in this range.' );
	snt_analytics_render_referrer_categories( $ref_cats );
	snt_analytics_render_dim_table( 'Countries', $countries, 'No country data in this range.' );
	echo '</div>';

	echo '<h2 class="sn-section-h">Technology</h2>';
	echo '<div class="sn-an-grid">';
	snt_analytics_render_dim_table( 'Browsers', $browsers, 'No browser data in this range yet.' );
	snt_analytics_render_dim_table( 'Operating systems', $os, 'No OS data in this range yet.' );
	snt_analytics_render_dim_table( 'Devices', $devices, 'No device data in this range.' );
	snt_analytics_render_dim_table( 'Protocols', $protocols, 'No protocol data in this range yet.' );
	snt_analytics_render_dim_table( 'TLS versions', $tls, 'No TLS data in this range yet.' );
	echo '</div>';

	echo '<h2 class="sn-section-h">Geography &amp; network</h2>';
	echo '<div class="sn-an-grid">';
	snt_analytics_render_dim_table( 'Cities', $cities, 'No city data in this range yet.' );
	snt_analytics_render_dim_table( 'Regions', $regions, 'No region data in this range yet.' );
	snt_analytics_render_dim_table( 'Networks', $networks, 'No network data in this range yet.' );
	snt_analytics_render_dim_table( 'Edge locations', $colos, 'No edge-location data in this range yet.' );
	echo '</div>';

	echo '<h2 class="sn-section-h">Engagement</h2>';
	snt_analytics_render_heatmap( $heatmap );
	echo '<div class="sn-an-grid">';
	snt_analytics_render_distribution( 'Scroll depth', $scroll_dist );
	snt_analytics_render_distribution( 'Time on page', $time_dist );
	echo '</div>';

	echo '<h2 class="sn-section-h">Traffic quality</h2>';
	snt_analytics_render_bot_breakdown( $bot );

	// Empty hint when configured but the tables are still dormant.
	if ( empty( $paths ) && (int) ( $totals['views'] ?? 0 ) === 0 ) {
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
	/* WP admin native: #1d2327 text · #646970 muted · #2271b1 link · #f0f0f1 hairline · #d63638 error. */
	.sn-an-controls{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:4px 0 14px;}
	.sn-an-seg{display:inline-flex;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;}
	.sn-an-seg a{padding:4px 12px;font-size:13px;text-decoration:none;color:#2271b1;background:#fff;border-right:1px solid #c3c4c7;}
	.sn-an-seg a:last-child{border-right:0;}
	.sn-an-seg a.is-active{background:#2271b1;color:#fff;}
	.sn-an-sep{color:#646970;font-size:13px;margin:0 0 14px;}
	.sn-an-trend{display:flex;align-items:flex-end;gap:3px;height:48px;margin:0 0 18px;}
	.sn-an-trend .bar{flex:1;background:#2271b1;min-height:2px;border-radius:2px 2px 0 0;}
	.sn-an-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:0 0 20px;}
	.sn-an-card{border:1px solid #dcdcde;border-radius:6px;padding:12px;background:#fff;}
	.sn-an-card .n{font-size:1.5rem;font-weight:600;color:#1d2327;line-height:1.1;font-variant-numeric:tabular-nums;}
	.sn-an-card .l{font-size:0.8em;color:#646970;margin-top:3px;}
	.sn-an-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;}
	.sn-an-panel{border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff;}
	.sn-an-panel h3{margin:0 0 8px;font-size:13px;color:#1d2327;}
	.sn-an-table{width:100%;border-collapse:collapse;font-size:13px;}
	.sn-an-table th{text-align:left;color:#646970;font-weight:400;border-bottom:1px solid #f0f0f1;padding:5px 0;}
	.sn-an-table td{border-bottom:1px solid #f0f0f1;padding:5px 0;color:#1d2327;}
	.sn-an-table td.num,.sn-an-table th.num{text-align:right;font-variant-numeric:tabular-nums;color:#646970;}
	.sn-an-table tr:last-child td{border-bottom:0;}
	.sn-an-empty{color:#646970;font-style:italic;font-size:13px;margin-top:16px;}
	@media (max-width:782px){.sn-an-cards{grid-template-columns:repeat(2,1fr);}.sn-an-grid{grid-template-columns:1fr;}}
	.sn-an-settings{max-width:640px;}
	.sn-an-settings-help{color:#646970;font-size:13px;margin:.25rem 0 1rem;}
	.sn-an-worker{margin-top:16px;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;background:#fff;}
	.sn-an-worker summary{cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;}
	.sn-an-steps{margin:10px 0 0;padding-left:20px;font-size:13px;color:#1d2327;}
	.sn-an-steps li{margin:6px 0;}
	.sn-an-pre{background:#f6f7f7;border:1px solid #e0e0e0;padding:8px 10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre;overflow:auto;}
	.sn-an-worker[open] summary{margin-bottom:6px;}
	/* v5.4.0 — period deltas, referrer categories, distributions, heatmap, quality. */
	.sn-an-delta{font-size:0.72em;font-weight:600;margin-left:5px;white-space:nowrap;}
	.sn-an-delta--up{color:#0a7c2f;}
	.sn-an-delta--down{color:#b32d2e;}
	.sn-an-delta--flat{color:#646970;}
	.sn-an-refcats-bars{display:flex;flex-direction:column;gap:8px;}
	.sn-an-refcat-h{display:flex;justify-content:space-between;font-size:13px;color:#1d2327;margin-bottom:3px;}
	.sn-an-refcat-bar{height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;}
	.sn-an-refcat-bar span{display:block;height:100%;background:#2271b1;border-radius:4px;}
	.sn-an-dist-bars{display:flex;flex-direction:column;gap:7px;}
	.sn-an-dist-row{display:flex;align-items:center;gap:8px;font-size:13px;}
	.sn-an-dist-l{flex:0 0 72px;color:#646970;}
	.sn-an-dist-bar{flex:1;height:10px;background:#f0f0f1;border-radius:5px;overflow:hidden;}
	.sn-an-dist-bar span{display:block;height:100%;background:#2271b1;border-radius:5px;}
	.sn-an-dist-n{flex:0 0 auto;}
	.sn-an-heatmap{display:flex;flex-direction:column;gap:3px;overflow-x:auto;}
	.sn-an-hm-row{display:flex;align-items:center;gap:3px;}
	.sn-an-hm-day{flex:0 0 32px;font-size:11px;color:#646970;}
	.sn-an-hm-cell{flex:1;min-width:9px;height:14px;background:#f0f0f1;border-radius:2px;}
	.sn-an-quality-bar{display:flex;height:16px;border-radius:4px;overflow:hidden;margin:0 0 8px;background:#f0f0f1;}
	.sn-an-q{display:block;height:100%;}
	.sn-an-q--human{background:#2271b1;}
	.sn-an-q--suspect{background:#dba617;}
	.sn-an-q--bot{background:#d63638;}
	.sn-an-q-legend{font-size:12px;color:#646970;margin:0 0 10px;}
	.sn-an-q-key{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:2px;}
	.sn-an-subh{font-size:12px;color:#646970;margin:10px 0 4px;}
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
