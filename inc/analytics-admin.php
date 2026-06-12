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
 * Render the Analytics dashboard (hooked at sn_admin_dashboard_extras, priority 5
 * — leads the plugin Dashboard tab since v5.3.0; was Monitoring → Analytics).
 */
function snt_analytics_render_admin_tab() {
	snt_analytics_styles();

	// v5.3.0: section heading — this view now leads the Dashboard tab.
	echo '<h2 class="sn-section-h">Analytics</h2>';

	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range = snt_analytics_resolve_range( isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7' );
	$class = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );

	// Config gate: show the settings form (+ empty notice) when unconfigured.
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		snt_analytics_render_empty( 'unconfigured' );
		snt_analytics_render_settings();
		return;
	}

	list( $from, $to ) = snt_analytics_range_dates( $range );

	$totals       = sn_analytics_range_totals( $from, $to, $class );
	$class_totals = sn_analytics_class_totals( $from, $to );
	$now          = sn_analytics_realtime( $class );
	$series       = sn_analytics_daily_series( $from, $to, $class );
	$paths        = sn_analytics_top_paths( $from, $to, $class, 25 );
	$referrers    = sn_analytics_top_dimension( 'referrer', $from, $to, $class, 10 );
	$countries    = sn_analytics_top_dimension( 'country', $from, $to, $class, 10 );
	$devices      = sn_analytics_top_dimension( 'device', $from, $to, $class, 10 );

	// AE error diagnostic (admins only), shown above the data.
	snt_analytics_render_error();

	snt_analytics_render_controls( $range, $class );
	snt_analytics_render_separation( $class_totals, $class );
	snt_analytics_render_trend( $series );
	snt_analytics_render_cards( $now, $totals );

	echo '<div class="sn-an-grid">';
	snt_analytics_render_paths_table( $paths );
	snt_analytics_render_dim_table( 'Top sources', $referrers, 'No referrers in this range.' );
	snt_analytics_render_dim_table( 'Top countries', $countries, 'No country data in this range.' );
	snt_analytics_render_dim_table( 'Devices', $devices, 'No device data in this range.' );
	echo '</div>';

	// Empty hint when configured but the tables are still dormant.
	if ( empty( $paths ) && (int) ( $totals['views'] ?? 0 ) === 0 ) {
		echo '<p class="sn-an-empty">No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.</p>';
	}

	echo '<details class="sn-an-settings-wrap"><summary class="sn-an-seg-summary">Settings &amp; Worker setup</summary>';
	snt_analytics_render_settings();
	echo '</details>';
}
// v5.3.0: the analytics dashboard now LEADS the plugin Dashboard tab (moved out
// of Monitoring → Analytics). Priority 5 renders it above the operational state
// grid (snt_dashboard_tab_render, priority 10). The renderer is location-agnostic
// (reads rollup accessors; controls derive their URL from the current request).
add_action( 'sn_admin_dashboard_extras', 'snt_analytics_render_admin_tab', 5 );

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
	.sn-an-settings-wrap{margin-top:20px;border-top:1px solid #dcdcde;padding-top:14px;}
	.sn-an-seg-summary{cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;}
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
