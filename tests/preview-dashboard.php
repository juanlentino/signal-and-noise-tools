<?php
/**
 * Render smoke test + dev preview: renders snt_analytics_render_dashboard() with
 * stub data, asserts the native-component markers, and writes the HTML to
 * /tmp/sn-dashboard-preview.html for visual comparison against the approved mockup.
 * Emits a "Result: N passed, M failed." line so it participates in the CI sweep.
 * Run: php tests/preview-dashboard.php [view]  → writes /tmp/sn-dashboard-preview.html
 * Pass a tab name as the first argument to preview a specific view, e.g.:
 *   php tests/preview-dashboard.php geography
 * @since plugin v6.5.0
 */
if ( PHP_SAPI !== 'cli' ) { exit; }


define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
// v8.4.2: the dashboard fires the snt_analytics_after_overview seam.
if ( ! function_exists( 'do_action' ) ) { function do_action( $h = '', ...$args ) {} }

// Escaping + i18n stubs.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, (int) $decimals ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/wp-admin/admin.php?page=sn-analytics'; }
	$sep = ( strpos( (string) $url, '?' ) !== false ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $q ? $parts[0] . '?' . http_build_query( $q ) : $parts[0];
}
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics';
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function current_user_can( $c ) { return true; }

// Option store seam.
$GLOBALS['__aa_opts'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['__aa_opts'] ) ? $GLOBALS['__aa_opts'][ $k ] : $default; }
function update_option( $k, $v, $autoload = true ) { $GLOBALS['__aa_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__aa_opts'][ $k ] ); return true; }

if ( ! defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ) { define( 'SN_CF_ANALYTICS_TOKEN_OPT', 'sn_cf_analytics_token' ); }
if ( ! defined( 'SN_CF_ACCOUNT_ID_OPT' ) )      { define( 'SN_CF_ACCOUNT_ID_OPT', 'sn_cf_account_id' ); }

if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" />'; } }
if ( ! function_exists( 'sn_mask_secret' ) ) {
	function sn_mask_secret( $v ) { $v = (string) $v; return '' === $v ? '' : ( strlen( $v ) <= 8 ? '••••••••' : '••••' . substr( $v, -4 ) ); }
}

// AE config seam.
$GLOBALS['__aa_config'] = true;
function sn_analytics_config() { return $GLOBALS['__aa_config'] ? array( 'account_id' => 'preview-acct', 'token' => 'preview-token' ) : null; }
$GLOBALS['__aa_error'] = null;
function sn_analytics_last_error() { return $GLOBALS['__aa_error']; }

// Core accessor seams.
$GLOBALS['__aa'] = array(
	'realtime'   => null,
	'totals'     => array(),
	'class_totals' => array(),
	'series'     => array(),
	'paths'      => array(),
	'dim'        => array(),
	'events'     => array(),
	'event_props' => array(),
);
function sn_analytics_realtime( $class = 'human' ) { return $GLOBALS['__aa']['realtime']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__aa']['totals']; }
function sn_analytics_class_totals( $from, $to ) { return $GLOBALS['__aa']['class_totals']; }
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day' ) { return $GLOBALS['__aa']['series']; }
function sn_analytics_granularity( $days ) { return ( (int) $days > 90 ) ? 'week' : 'day'; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
	// v8.5.0: window-aware — the PRIOR window returns shifted views so the
	// Movers tile shows real deltas in the preview.
	if ( 'PRIOR' === $from ) {
		$out = array();
		foreach ( (array) $GLOBALS['__aa']['paths'] as $i => $r ) {
			$r['views'] = max( 0, (int) round( $r['views'] * ( 0 === $i % 2 ? 0.7 : 1.3 ) ) );
			$out[] = $r;
		}
		return $out;
	}
	return $GLOBALS['__aa']['paths'];
}
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['dim']; }

// Derived + buckets seams.
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	return array(
		'views'               => array( 'current' => 1204, 'previous' => 600,   'pct' => 101, 'dir' => 'up' ),
		'visits'              => array( 'current' => 389,  'previous' => 400,   'pct' => -3,  'dir' => 'down' ),
		'scroll_avg'          => array( 'current' => 62.0, 'previous' => 62.0,  'pct' => 0,   'dir' => 'flat' ),
		'time_avg'            => array( 'current' => 108.0, 'previous' => 90.0, 'pct' => 20,  'dir' => 'up' ),
		// v9.64.0 nullable trio (the keys the honest Overview actually wires).
		'pageview_visits'     => array( 'current' => 340, 'previous' => 371, 'pct' => -8, 'dir' => 'down' ),
		'scroll_avg_per_view' => array( 'current' => 55.0, 'previous' => 50.0, 'pct' => 10, 'dir' => 'up' ),
		'time_avg_per_view'   => array( 'current' => 98000.0, 'previous' => 90000.0, 'pct' => 9, 'dir' => 'up' ),
	);
}
function sn_analytics_hour_dow_grid( $from, $to, $class = 'human' ) {
	$grid = array();
	for ( $d = 1; $d <= 7; $d++ ) { $grid[ $d ] = array_fill( 0, 24, 0 ); }
	$grid[3][14] = 9;
	return array( 'grid' => $grid, 'max' => 9 );
}
function sn_analytics_distribution( $metric, $from, $to, $class = 'human' ) {
	if ( 'scroll' === $metric ) { return array( array( 'label' => '0–25%', 'views' => 4 ), array( 'label' => '25–50%', 'views' => 10 ), array( 'label' => '50–75%', 'views' => 20 ), array( 'label' => '75–100%', 'views' => 40 ) ); }
	return array( array( 'label' => '0–10s', 'views' => 7 ), array( 'label' => '10–30s', 'views' => 15 ), array( 'label' => '30s–2m', 'views' => 30 ), array( 'label' => '3m+', 'views' => 2 ) );
}
function sn_analytics_top_entry_pages( $f, $t, $l = 25 ) { return array( array( 'path' => '/', 'views' => 98, 'visits' => 91 ), array( 'path' => '/notes/falsifiability-is-the-line/', 'views' => 67, 'visits' => 60 ), array( 'path' => '/notes/start-here/', 'views' => 31, 'visits' => 29 ) ); }
function sn_analytics_top_exit_pages( $f, $t, $l = 25 ) { return array( array( 'path' => '/contact/', 'views' => 54, 'visits' => 50 ), array( 'path' => '/notes/start-here/', 'views' => 41, 'visits' => 38 ) ); }
function sn_analytics_percentiles( $m, $f, $t, $c = 'human' ) { return array( array( 'label' => 'p50', 'value' => 63.0 ), array( 'label' => 'p75', 'value' => 84.0 ), array( 'label' => 'p90', 'value' => 95.0 ) ); }
function sn_analytics_referrer_categories( $from, $to, $class = 'human' ) {
	return array(
		array( 'category' => 'search', 'label' => 'Search', 'views' => 120, 'visits' => 70 ),
		array( 'category' => 'social', 'label' => 'Social', 'views' => 50,  'visits' => 30 ),
		array( 'category' => 'direct', 'label' => 'Direct', 'views' => 200, 'visits' => 120 ),
		array( 'category' => 'other',  'label' => 'Other',  'views' => 5,   'visits' => 3 ),
	);
}
function sn_analytics_bot_breakdown( $from, $to, $limit = 10 ) {
	return array(
		'totals'           => array( 'human' => 1204, 'suspect' => 44, 'bot' => 268, 'total' => 1516 ),
		'top_bot_networks' => array( array( 'value' => 'Amazon.com, Inc.', 'views' => 180, 'visits' => 8 ) ),
	);
}
function sn_analytics_engaged_rate( $f, $t, $c = 'human' ) { return 42; }
function sn_analytics_engaged_rate_delta( $f, $t, $c = 'human' ) { return array( 'current' => 42, 'previous' => 40, 'pct' => 5, 'dir' => 'up' ); }
function sn_analytics_low_engagement_paths( $f, $t, $c = 'human', $l = 15 ) { return array( array( 'path' => '/notes/old-post', 'views' => 10, 'visits' => 8, 'scroll_avg' => 12.0, 'time_avg' => 5000.0 ) ); }
function sn_analytics_dimension_series( $dim, $vals, $f, $t, $c = 'human', $g = 'day' ) {
	$out = array();
	foreach ( (array) $vals as $v ) { $out[ (string) $v ] = array( array( 'day' => '2026-06-09', 'views' => 2 ), array( 'day' => '2026-06-10', 'views' => 5 ), array( 'day' => '2026-06-11', 'views' => 3 ) ); }
	return $out;
}
function sn_analytics_class_series( $f, $t, $g = 'day' ) { return array( array( 'day' => '2026-06-09', 'bot_pct' => 28, 'total' => 80 ), array( 'day' => '2026-06-10', 'bot_pct' => 30, 'total' => 90 ), array( 'day' => '2026-06-11', 'bot_pct' => 25, 'total' => 70 ) ); }
function sn_analytics_top_events( $f, $t, $l = 25 ) { return $GLOBALS['__aa']['events']; }
function sn_analytics_top_event_props( $f, $t, $prop = '', $l = 50 ) { return $GLOBALS['__aa']['event_props']; }
// v9.68.0 Overview landing seams: the durable session rollup (typed rows —
// the real sn_session_rollup_read contract), the UTM campaigns rollup, and
// the cron-warmed views-today. Realistic small-site numbers.
function sn_session_rollup_read( $from, $to, $class ) {
	// Fixed trend window (56d) vs header window: return 8 weekly-ish days for
	// the long window, the last 4 for the short one.
	$rows = array();
	$anchor = strtotime( '2026-06-11 00:00:00 UTC' );
	for ( $i = 7; $i >= 0; $i-- ) {
		$rows[] = array(
			'day'        => gmdate( 'Y-m-d', $anchor - $i * 7 * DAY_IN_SECONDS ),
			'visits'     => 30 + ( $i % 3 ) * 9,
			'bounce_pct' => 64.0 - $i * 1.5,
			'ppv'        => 1.3 + 0.05 * ( $i % 4 ),
			'median_dur' => 48 + 4 * ( $i % 3 ),
		);
	}
	$days = (int) floor( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
	return ( $days > 30 ) ? $rows : array_slice( $rows, -4 );
}
function sn_analytics_top_utm_campaigns( $f, $t, $c = 'human', $l = 25 ) {
	return array(
		array( 'value' => 'qr-provhub', 'views' => 6, 'visits' => 5 ),
		array( 'value' => 'newsletter', 'views' => 3, 'visits' => 3 ),
	);
}
function sn_analytics_views_today() { return 6; }

// Canonical-source mapper (the content view's Top sources fold) — real module
// over the stubbed read accessors; its WP-seam calls are function_exists-guarded.
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
// v12.10.0 seam: the Analytics screen moved to its own top-level menu and its
// URL is now an accessor owned by inc/analytics-dashboard-page.php. Placed
// immediately before the requires — an earlier version of this stub landed
// inside the non-CLI guard block above, which never executes under `php
// tests/...`, so the function stayed undefined and the suite fataled.
if ( ! function_exists( 'snt_analytics_page_url' ) ) {
	function snt_analytics_page_url( $args = array() ) {
		$url = 'https://example.test/wp-admin/admin.php?page=sn-analytics';
		if ( is_array( $args ) && array() !== $args ) {
			foreach ( $args as $k => $v ) { $url .= '&' . $k . '=' . $v; }
		}
		return $url;
	}
}

require_once __DIR__ . '/../inc/analytics-sources.php';
// v8.5.0 header-region dependencies (movers + primitive run real; uptime
// surfaces absent = unconfigured install).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__pv_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__pv_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'sn_analytics_prior_window' ) ) { function sn_analytics_prior_window( $f, $t ) { return array( 'PRIOR', 'PRIOR' ); } }
require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-movers.php';
require_once __DIR__ . '/../inc/analytics-header-region.php';
require_once __DIR__ . '/../inc/analytics-view-content.php';
require_once __DIR__ . '/../inc/analytics-view-overview.php'; // v9.68.0: the default landing
require_once __DIR__ . '/../inc/analytics-view-technology.php';
require_once __DIR__ . '/../inc/analytics-view-geography.php';
require_once __DIR__ . '/../inc/analytics-view-engagement.php';
require_once __DIR__ . '/../inc/analytics-view-quality.php';
require_once __DIR__ . '/../inc/analytics-view-events.php';
require_once __DIR__ . '/../inc/analytics-admin-render.php';
require_once __DIR__ . '/../inc/analytics-admin.php';

// Fill realistic data with country rows and sparkline series.
$GLOBALS['__aa_config']          = true;
$GLOBALS['__aa_error']           = null;
$GLOBALS['__aa']['realtime']     = 7;
// The REAL sn_analytics_range_totals() contract since v9.63.0 (legacy quartet
// + spec-§4 derived + exact_metrics_since) — the honest Overview reads the
// derived keys, so a legacy-only stub here would be the stub-drift trap.
$GLOBALS['__aa']['totals']       = array(
	'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108000.0,
	'unique_visitor_days' => 389, 'pageview_visits' => 340, 'viewless_visits' => 49,
	'view_visit_ratio' => 1204 / 340, 'pageviews_per_visitor_day' => 1204 / 389,
	'scroll_avg_per_view' => 55.0, 'time_avg_per_view' => 98000.0,
	'scroll_avg_per_visit' => 48.0, 'time_avg_per_visit' => 85000.0,
	'integrity_violation' => false, 'exact_metrics_since' => '2026-04-18',
);
$GLOBALS['__aa']['class_totals'] = array(
	'human'   => array( 'views' => 1204, 'visits' => 389 ),
	'bot'     => array( 'views' => 268,  'visits' => 12 ),
	'suspect' => array( 'views' => 44,   'visits' => 9 ),
);
$GLOBALS['__aa']['series'] = array(
	array( 'day' => '2026-06-05', 'views' => 80,  'visits' => 32 ),
	array( 'day' => '2026-06-06', 'views' => 120, 'visits' => 48 ),
	array( 'day' => '2026-06-07', 'views' => 95,  'visits' => 38 ),
	array( 'day' => '2026-06-08', 'views' => 200, 'visits' => 72 ),
	array( 'day' => '2026-06-09', 'views' => 150, 'visits' => 56 ),
	array( 'day' => '2026-06-10', 'views' => 259, 'visits' => 90 ),
	array( 'day' => '2026-06-11', 'views' => 300, 'visits' => 108 ),
);
$GLOBALS['__aa']['paths'] = array(
	array( 'path' => '/notes/x',                    'views' => 412, 'visits' => 158, 'scroll_avg' => 71.0,  'time_avg' => 150000.0 ),
	array( 'path' => '/notes/the-browser-is-fine',  'views' => 211, 'visits' => 88,  'scroll_avg' => 65.0,  'time_avg' => 120000.0 ),
	array( 'path' => '/',                            'views' => 189, 'visits' => 145, 'scroll_avg' => 38.0,  'time_avg' => 45000.0 ),
	array( 'path' => '/notes/signal-and-noise',      'views' => 101, 'visits' => 52,  'scroll_avg' => 80.0,  'time_avg' => 200000.0 ),
	array( 'path' => '/notes/on-tools',              'views' => 78,  'visits' => 41,  'scroll_avg' => 55.0,  'time_avg' => 90000.0 ),
	// v8.5.0: enough rows to exercise the 10-visible clamp + View all.
	array( 'path' => '/provenance/',                 'views' => 64,  'visits' => 39,  'scroll_avg' => 62.0,  'time_avg' => 110000.0 ),
	array( 'path' => '/notes/start-here',            'views' => 51,  'visits' => 33,  'scroll_avg' => 70.0,  'time_avg' => 95000.0 ),
	array( 'path' => '/about',                       'views' => 44,  'visits' => 30,  'scroll_avg' => 48.0,  'time_avg' => 60000.0 ),
	array( 'path' => '/notes/signing-the-inputs',    'views' => 39,  'visits' => 26,  'scroll_avg' => 74.0,  'time_avg' => 130000.0 ),
	array( 'path' => '/music',                       'views' => 31,  'visits' => 22,  'scroll_avg' => 41.0,  'time_avg' => 40000.0 ),
	array( 'path' => '/notes/detection-scales',      'views' => 26,  'visits' => 18,  'scroll_avg' => 66.0,  'time_avg' => 88000.0 ),
	array( 'path' => '/colophon',                    'views' => 19,  'visits' => 14,  'scroll_avg' => 52.0,  'time_avg' => 47000.0 ),
);
// Country rows (realistic: US heavy, SG, AR, CN).
$GLOBALS['__aa']['dim'] = array(
	array( 'value' => 'US', 'views' => 812, 'visits' => 270 ),
	array( 'value' => 'SG', 'views' => 180, 'visits' => 60 ),
	array( 'value' => 'AR', 'views' => 62,  'visits' => 22 ),
	array( 'value' => 'CN', 'views' => 38,  'visits' => 12 ),
	array( 'value' => 'GB', 'views' => 30,  'visits' => 12 ),
	array( 'value' => 'DE', 'views' => 25,  'visits' => 9 ),
	array( 'value' => 'CA', 'views' => 22,  'visits' => 8 ),
	array( 'value' => 'AU', 'views' => 18,  'visits' => 7 ),
	array( 'value' => 'FR', 'views' => 10,  'visits' => 4 ),
	array( 'value' => 'JP', 'views' => 7,   'visits' => 3 ),
);
$GLOBALS['__aa']['events']      = array( array( 'name' => 'signup', 'events' => 120, 'visitors' => 90 ), array( 'name' => 'share', 'events' => 45, 'visitors' => 35 ) );
$GLOBALS['__aa']['event_props'] = array( array( 'property' => 'utm_source', 'value' => 'hn', 'events' => 50, 'visitors' => 40 ) );

// Render a given tab to a string (captures the dashboard body).
function snt_preview_render( $view ) {
	$_GET['sn_view'] = $view;
	ob_start();
	snt_analytics_render_dashboard();
	return (string) ob_get_clean();
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Analytics dashboard render smoke (native dense redesign)\n\n";

$geo = snt_preview_render( 'geography' );
ok( strpos( $geo, 'class="postbox' ) !== false, 'native postbox cards present' );
ok( strpos( $geo, 'sn-kpi-row' ) !== false, 'fused KPI strip present' );
ok( strpos( $geo, 'sn-spark' ) !== false || strpos( $geo, '<polyline' ) !== false, 'SVG sparkline present' );
ok( strpos( $geo, 'sn-geo-split' ) !== false, 'geography map↔countries split present' );
ok( strpos( $geo, 'wp-list-table widefat' ) !== false, 'native widefat tables present' );
ok( strpos( $geo, 'button button-secondary' ) !== false, 'export buttons present (not text links)' );
ok( strpos( $geo, 'handlediv' ) === false, 'no JS collapse toggles shipped' );

$content = snt_preview_render( 'content' );
ok( strpos( $content, '/notes/x' ) !== false, 'content tab: top pages rendered' );

// v9.68.0: the default landing renders under the shared chrome with the
// wired body (session quality + right now + bento + entry/exit pair).
$overview = snt_preview_render( 'overview' );
ok( strpos( $overview, 'postbox sn-overview' ) !== false, 'overview tab: shared Overview KPI card inherited (headline)' );
ok( strpos( $overview, 'sn-an-movers' ) !== false, 'overview tab: movers rail renders beside the shared card' );
ok( strpos( $overview, 'Session quality' ) !== false, 'overview tab: session quality panel rendered' );
ok( strpos( $overview, 'sn-an-overview-bento' ) !== false, 'overview tab: bento midsection rendered' );
ok( strpos( $overview, 'sn-an-overview-pair' ) !== false, 'overview tab: entry/exit pair rendered' );

// Write the visual preview file (CLI arg picks the tab; default geography) for browser comparison.
// v6.5.1: the dense layout CSS is now an external enqueued stylesheet, not an inline
// <style> echoed by the render path. Embed the asset file's CSS here (exactly what the
// live page loads via wp_enqueue_style) so the standalone preview stays faithful.
$css_file = __DIR__ . '/../assets/analytics/analytics-admin.css';
$css      = is_file( $css_file ) ? (string) file_get_contents( $css_file ) : '';
$view     = isset( $argv[1] ) ? (string) $argv[1] : 'geography';
$html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
	// dev-only: approximate core admin CSS for the standalone preview (never shipped).
	. '<link rel="stylesheet" href="https://s.w.org/wp-admin/css/common.css">'
	. '<style>' . $css . '</style>'
	. '</head><body class="wp-admin wp-core-ui" style="background:#f0f0f1">'
	. '<div class="wrap"><h1>Analytics</h1>' . snt_preview_render( $view ) . '</div></body></html>';
file_put_contents( '/tmp/sn-dashboard-preview.html', $html );
echo "\nWrote /tmp/sn-dashboard-preview.html (view: " . $view . ")\n";

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
