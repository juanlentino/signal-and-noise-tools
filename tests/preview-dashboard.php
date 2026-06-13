<?php
/**
 * DEV preview: render snt_analytics_render_dashboard() with stub data to an HTML
 * file for visual comparison against the approved mockup. NOT a pass/fail test.
 * Run: php tests/preview-dashboard.php  → writes /tmp/sn-dashboard-preview.html
 * Pass a tab name as the first argument to preview a specific view, e.g.:
 *   php tests/preview-dashboard.php geography
 * @since plugin v6.5.0
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Escaping + i18n stubs.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
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
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['paths']; }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['dim']; }

// Derived + buckets seams.
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	return array(
		'views'      => array( 'current' => 1204, 'previous' => 600,   'pct' => 101, 'dir' => 'up' ),
		'visits'     => array( 'current' => 389,  'previous' => 400,   'pct' => -3,  'dir' => 'down' ),
		'scroll_avg' => array( 'current' => 62.0, 'previous' => 62.0,  'pct' => 0,   'dir' => 'flat' ),
		'time_avg'   => array( 'current' => 108.0, 'previous' => 90.0, 'pct' => 20,  'dir' => 'up' ),
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

require_once __DIR__ . '/../inc/analytics-admin-render.php';
require_once __DIR__ . '/../inc/analytics-admin.php';

// Fill realistic data with country rows and sparkline series.
$GLOBALS['__aa_config']          = true;
$GLOBALS['__aa_error']           = null;
$GLOBALS['__aa']['realtime']     = 7;
$GLOBALS['__aa']['totals']       = array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108000.0 );
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

$_GET['sn_view'] = isset( $argv[1] ) ? $argv[1] : 'geography';

ob_start();
echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
// dev-only: approximate core admin CSS for the preview (never shipped).
echo '<link rel="stylesheet" href="https://s.w.org/wp-admin/css/common.css">';
echo '</head><body class="wp-admin wp-core-ui" style="background:#f0f0f1">';
echo '<div class="wrap"><h1>Analytics</h1>';
snt_analytics_render_dashboard();
echo '</div></body></html>';
file_put_contents( '/tmp/sn-dashboard-preview.html', ob_get_clean() );
echo "wrote /tmp/sn-dashboard-preview.html (view: " . $_GET['sn_view'] . " — open in browser)\n";
