<?php
/**
 * Signal & Noise — first-party analytics dashboard widgets.
 *
 * Registers four discrete WP dashboard-home widgets that read data from
 * the first-party analytics rollup tables via the sn_analytics_* accessors:
 *
 *   - sn_plausible_snapshot — 7-day aggregate (Views, Visits, Avg scroll %,
 *                              Avg time on page, Engaged %, Filtered)
 *   - sn_plausible_realtime — visitors right now (last 5 min)
 *   - sn_plausible_pages    — top 7 pages by views, last 7 days
 *   - sn_plausible_sources  — top 7 referrers by views, last 7 days
 *
 * Widget IDs are intentionally kept as sn_plausible_* to preserve any
 * per-user dashboard-layout preferences; the rename to sn_analytics_*
 * is deferred to the Plausible cutover milestone.
 *
 * Requires SN_CF_ANALYTICS_TOKEN + SN_CF_ACCOUNT_ID in wp-config.php.
 *
 * @package SignalNoise
 * @since 7.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', function() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Widget IDs intentionally keep the sn_plausible_* prefix to preserve users'
	// existing dashboard layout/visibility meta. The file + functions are analytics-named.
	wp_add_dashboard_widget( 'sn_plausible_snapshot', 'Analytics — Last 7 days',      'sn_aw_snapshot' );
	wp_add_dashboard_widget( 'sn_plausible_realtime', 'Analytics — Right now',        'sn_aw_realtime' );
	wp_add_dashboard_widget( 'sn_plausible_pages',    'Analytics — Top pages (7d)',   'sn_aw_pages' );
	wp_add_dashboard_widget( 'sn_plausible_sources',  'Analytics — Top sources (7d)', 'sn_aw_sources' );
} );

/**
 * Inline CSS, printed once per pageload (the first widget that renders
 * triggers it; subsequent calls are no-ops via the static guard).
 */
function sn_aw_styles() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<style>
	/* WP admin native styling — no theme fonts, WP palette only.
	   #1d2327 primary text · #646970 muted · #2271b1 link · #f0f0f1 hairline · #d63638 error. */
	.sn-aw-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 18px;margin:0;}
	.sn-aw-stat-n{font-size:1.6rem;font-weight:600;color:#1d2327;line-height:1.1;font-variant-numeric:tabular-nums;}
	.sn-aw-stat-l{font-size:0.85em;color:#646970;margin-top:2px;}
	.sn-aw-big{font-size:2.5rem;font-weight:600;color:#1d2327;text-align:center;line-height:1;padding:8px 0 4px;font-variant-numeric:tabular-nums;}
	.sn-aw-big-l{font-size:0.85em;color:#646970;text-align:center;}
	.sn-aw-list{list-style:none;margin:0;padding:0;font-size:0.875em;}
	.sn-aw-list li{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f1;gap:10px;}
	.sn-aw-list li:last-child{border-bottom:0;}
	.sn-aw-list .k{color:#1d2327;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
	.sn-aw-list .v{color:#646970;flex-shrink:0;font-variant-numeric:tabular-nums;}
	.sn-aw-foot{margin:12px 0 0;font-size:0.85em;color:#646970;}
	.sn-aw-empty{color:#646970;font-size:0.875em;font-style:italic;margin:0;}
	.sn-aw-err{color:#d63638;font-size:0.9em;margin:0;}
	.sn-aw-config-snippet{background:#f6f7f7;border:1px solid #e0e0e0;padding:6px 10px;margin:6px 0 0;font-size:0.85em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
	</style>
	<?php
}

/**
 * Shared analytics not-configured copy. Renders inside the widget body
 * with a single line + a code snippet showing the wp-config constants to add.
 * Extracted in v1.14.0 (was duplicated across snapshot + realtime widgets).
 */
function sn_aw_not_configured() {
	echo '<p class="sn-aw-err">Analytics not configured. Deploy the edge worker, then add the read credentials to <code>wp-config.php</code>:</p>';
	echo '<pre class="sn-aw-config-snippet">define( \'SN_CF_ANALYTICS_TOKEN\', \'…\' );' . "\n" . 'define( \'SN_CF_ACCOUNT_ID\', \'…\' );</pre>';
}

/**
 * Inclusive last-7-days UTC window for the widgets: [from, to] YYYY-MM-DD.
 */
function sn_aw_window7() {
	$now = time();
	return array( gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now ) );
}

/**
 * Preamble: print styles, gate on analytics config. Returns true when data may
 * be read, false (after printing the config copy) otherwise.
 */
function sn_aw_preamble() {
	sn_aw_styles();
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		sn_aw_not_configured();
		return false;
	}
	return true;
}

function sn_aw_snapshot() {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$t = sn_analytics_range_totals( $from, $to, 'human' );
	echo '<div class="sn-aw-grid">';
	sn_aw_stat( 'Views',      $t['views'] ?? null );
	sn_aw_stat( 'Visits',     $t['visits'] ?? null );
	sn_aw_stat( 'Avg scroll', isset( $t['scroll_avg'] ) ? (int) round( (float) $t['scroll_avg'] ) . '%' : null );
	sn_aw_stat( 'Avg time',   isset( $t['time_avg'] ) ? sn_aw_duration( (int) round( (float) $t['time_avg'] / 1000 ) ) : null );
	// Engaged = signal: share of human pageviews that crossed the engaged-time threshold
	// (int 0–100, or null when no time-distribution data exists yet → renders em-dash).
	$eng = function_exists( 'sn_analytics_engaged_rate' ) ? sn_analytics_engaged_rate( $from, $to, 'human' ) : null;
	sn_aw_stat( 'Engaged', null === $eng ? null : $eng . '%' );
	// Filtered = noise: suspect + bot pageviews the edge classifier caught and excluded.
	// Empty class_totals (no rollups in window) → null → em-dash; a measured 0 is honest
	// ("classified traffic, zero noise") and intentionally renders 0, not em-dash.
	$ct       = function_exists( 'sn_analytics_class_totals' ) ? sn_analytics_class_totals( $from, $to ) : array();
	$filtered = empty( $ct ) ? null : (int) ( ( $ct['suspect']['views'] ?? 0 ) + ( $ct['bot']['views'] ?? 0 ) );
	sn_aw_stat( 'Filtered', $filtered );
	echo '</div>';
	sn_aw_footer();
}

function sn_aw_realtime() {
	sn_aw_styles();
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		sn_aw_not_configured();
		return;
	}
	$n = sn_analytics_realtime( 'human' );
	echo '<div class="sn-aw-big-l">Visitors right now</div>';
	echo '<div class="sn-aw-big">' . esc_html( null === $n ? '—' : number_format_i18n( (int) $n ) ) . '</div>';
	echo '<p class="sn-aw-foot">Last 5 min · refreshes every 30 s · <a href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">Open Analytics →</a></p>';
}

function sn_aw_pages() {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$rows = array_map( function ( $r ) {
		return array( 'k' => $r['path'], 'v' => $r['views'] );
	}, sn_analytics_top_paths( $from, $to, 'human', 7 ) );
	sn_aw_kv_list( $rows, 'No page views in the last 7 days.' );
	sn_aw_footer();
}

function sn_aw_sources() {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$rows = array_map( function ( $r ) {
		return array( 'k' => $r['value'], 'v' => $r['views'] );
	}, sn_analytics_top_dimension( 'referrer', $from, $to, 'human', 7 ) );
	sn_aw_kv_list( $rows, 'No referrers in the last 7 days.' );
	sn_aw_footer();
}

/**
 * Shared key/value list for a [{k,v}] set of rows.
 *
 * @param array  $rows  [{k,v}]
 * @param string $empty
 */
function sn_aw_kv_list( $rows, $empty ) {
	if ( empty( $rows ) ) {
		echo '<p class="sn-aw-empty">' . esc_html( $empty ) . '</p>';
		return;
	}
	echo '<ul class="sn-aw-list">';
	foreach ( $rows as $row ) {
		echo '<li><span class="k">' . esc_html( (string) $row['k'] ) . '</span><span class="v">'
			. esc_html( number_format_i18n( (int) $row['v'] ) ) . '</span></li>';
	}
	echo '</ul>';
}

/**
 * Footer linking to the analytics dashboard — the native WP Dashboard → Analytics
 * page (v5.4.0; was the plugin Dashboard tab in v5.3.0).
 */
function sn_aw_footer() {
	echo '<p class="sn-aw-foot">7d · first-party · <a href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">Open Analytics →</a></p>';
}

function sn_aw_stat( $label, $value ) {
	$display = ( null === $value || '' === $value )
		? '—'
		: ( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value );
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( $display ) . '</div><div class="sn-aw-stat-l">' . esc_html( $label ) . '</div></div>';
}

function sn_aw_duration( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	$m = (int) floor( $seconds / 60 );
	$s = $seconds % 60;
	return $m . 'm ' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) . 's';
}
