<?php
/**
 * Tests for inc/analytics-admin.php + inc/analytics-admin-render.php — the
 * v5.4.0 split: snt_analytics_render_dashboard() (read-only, no settings) and
 * snt_analytics_render_settings_section() (creds form + "View dashboard →").
 * Behavioral: drives the render via stubbed accessors and asserts on captured HTML.
 * Run: php tests/analytics-admin.php
 * @since plugin v5.0.1 (split v5.4.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// Escaping + i18n stubs.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = null ) {
	if ( null === $url ) { $url = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/wp-admin/admin.php?page=sn-theme-options&tab=dashboard'; }
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

// Option store seam for the settings form.
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
function sn_analytics_config() { return $GLOBALS['__aa_config'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
$GLOBALS['__aa_error'] = null;
function sn_analytics_last_error() { return $GLOBALS['__aa_error']; }

// Core accessor seams.
$GLOBALS['__aa'] = array( 'realtime' => null, 'totals' => array(), 'class_totals' => array(), 'series' => array(), 'paths' => array(), 'dim' => array() );
function sn_analytics_realtime( $class = 'human' ) { return $GLOBALS['__aa']['realtime']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__aa']['totals']; }
function sn_analytics_class_totals( $from, $to ) { return $GLOBALS['__aa']['class_totals']; }
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day' ) { return $GLOBALS['__aa']['series']; }
function sn_analytics_granularity( $days ) { return ( (int) $days > 90 ) ? 'week' : 'day'; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['paths']; }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['dim']; }

// Derived + buckets seams (the dashboard composes these; isolated here).
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	return array(
		'views'      => array( 'current' => 1204, 'previous' => 600, 'pct' => 101, 'dir' => 'up' ),
		'visits'     => array( 'current' => 389,  'previous' => 400, 'pct' => -3,  'dir' => 'down' ),
		'scroll_avg' => array( 'current' => 62.0, 'previous' => 62.0, 'pct' => 0,  'dir' => 'flat' ),
		'time_avg'   => array( 'current' => 108.0, 'previous' => 90.0, 'pct' => 20, 'dir' => 'up' ),
	);
}
function sn_analytics_hour_dow_grid( $from, $to, $class = 'human' ) {
	$grid = array();
	for ( $d = 1; $d <= 7; $d++ ) { $grid[ $d ] = array_fill( 0, 24, 0 ); }
	$grid[3][14] = 9;
	return array( 'grid' => $grid, 'max' => 9 );
}
function sn_analytics_distribution( $metric, $from, $to, $class = 'human' ) {
	if ( 'scroll' === $metric ) { return array( array( 'label' => '0–25%', 'views' => 4 ), array( 'label' => '75–100%', 'views' => 40 ) ); }
	return array( array( 'label' => '0–10s', 'views' => 7 ), array( 'label' => '3m+', 'views' => 2 ) );
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

require_once __DIR__ . '/../inc/analytics-admin-render.php';
require_once __DIR__ . '/../inc/analytics-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
// Strip the inline <style> block so structural assertions (class names, counts)
// see markup only — snt_analytics_styles() prints once per process (static
// guard), so which capture() carries the CSS is call-order-dependent noise.
function capture( $cb ) { ob_start(); $cb(); return preg_replace( '!<style>.*?</style>!s', '', (string) ob_get_clean() ); }

echo "Analytics admin (dashboard + settings split)\n\n";

echo "Group: range + class resolution\n";
ok( snt_analytics_resolve_range( '30' ) === 30, 'resolve_range: 30 → 30' );
ok( snt_analytics_resolve_range( '999' ) === 7, 'resolve_range: out-of-list → default 7' );
ok( snt_analytics_resolve_range( "7); DROP" ) === 7, 'resolve_range: junk → default 7' );
ok( snt_analytics_resolve_class( 'bot' ) === 'bot', 'resolve_class: bot allowed' );
ok( snt_analytics_resolve_class( 'martian' ) === 'human', 'resolve_class: unknown → human' );
ok( snt_analytics_resolve_view( 'technology' ) === 'technology', 'resolve_view: known view allowed' );
ok( snt_analytics_resolve_view( 'martian' ) === 'content', 'resolve_view: unknown → content default' );
ok( snt_analytics_resolve_view( '' ) === 'content', 'resolve_view: empty → content default' );

echo "\nGroup: date math\n";
list( $from, $to ) = snt_analytics_range_dates( 7, gmmktime( 0, 0, 0, 6, 11, 2026 ) );
ok( $to === '2026-06-11', 'range_dates: $to is the anchor day (UTC)' );
ok( $from === '2026-06-05', 'range_dates: 7-day window is inclusive (anchor-6)' );

// Fixtures for a fully-configured dashboard render.
function aa_fill_data() {
	$GLOBALS['__aa_config']          = true;
	$GLOBALS['__aa_error']           = null;
	$GLOBALS['__aa']['realtime']     = 7;
	$GLOBALS['__aa']['totals']       = array( 'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108.0 );
	$GLOBALS['__aa']['class_totals'] = array( 'human' => array( 'views' => 1204, 'visits' => 389 ), 'bot' => array( 'views' => 268, 'visits' => 12 ), 'suspect' => array( 'views' => 44, 'visits' => 9 ) );
	$GLOBALS['__aa']['series']       = array( array( 'day' => '2026-06-10', 'views' => 100, 'visits' => 40 ), array( 'day' => '2026-06-11', 'views' => 300, 'visits' => 90 ) );
	$GLOBALS['__aa']['paths']        = array( array( 'path' => '/notes/x', 'views' => 412, 'visits' => 158, 'scroll_avg' => 71.0, 'time_avg' => 150.0 ) );
	$GLOBALS['__aa']['dim']          = array( array( 'value' => 'news.ycombinator.com', 'views' => 312, 'visits' => 98 ) );
}

echo "\nGroup: dashboard — core render\n";
aa_fill_data();
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, '1,204' ) !== false, 'dashboard: views stat card formatted' );
ok( strpos( $html, '<div class="n">7</div>' ) !== false, 'dashboard: visitors-now card shows 7' );
ok( strpos( $html, '312 automated filtered (268 bot · 44 suspect)' ) !== false, 'dashboard: separation line' );
ok( strpos( $html, '/notes/x' ) !== false, 'dashboard: top path row present' );
ok( substr_count( $html, 'sn-an-trend' ) === 1 && substr_count( $html, 'class="bar"' ) === 2, 'dashboard: trend strip one bar per day' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) === false, 'dashboard: read-only — NO settings form embedded (split)' );
ok( strpos( $html, 'value="analytics_save"' ) === false, 'dashboard: read-only — NO save button (split)' );

echo "\nGroup: dashboard — period-over-period deltas on cards\n";
ok( substr_count( $html, 'sn-an-delta' ) >= 2, 'dashboard: delta indicators on the stat cards' );
ok( strpos( $html, 'sn-an-delta--up' ) !== false && strpos( $html, 'sn-an-delta--down' ) !== false, 'dashboard: up + down delta directions rendered' );

echo "\nGroup: dashboard — view tab nav\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'nav-tab-wrapper' ) !== false, 'tabs: WP-native nav-tab-wrapper present' );
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality' ) as $v ) {
	ok( strpos( $html, 'sn_view=' . $v ) !== false, "tabs: link to '$v' view present" );
}
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'tabs: exactly one active tab' );
ok( strpos( $html, 'page=sn-analytics' ) !== false, 'tabs: links target the current page (sn-analytics)' );

echo "\nGroup: dashboard — persistent header on every tab\n";
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality' ) as $v ) {
	$_GET['sn_view'] = $v;
	$h = capture( 'snt_analytics_render_dashboard' );
	ok(
		strpos( $h, 'sn-an-cards' ) !== false && strpos( $h, 'sn-an-controls' ) !== false && substr_count( $h, 'class="bar"' ) === 2,
		"header: controls + delta cards + trend persist on the '$v' tab"
	);
}

echo "\nGroup: dashboard — Content view (default)\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages' ) !== false && strpos( $html, 'Top sources' ) !== false && strpos( $html, 'Countries' ) !== false, 'content: pages/sources/countries panels' );
ok( strpos( $html, 'sn-an-refcats' ) !== false && strpos( $html, 'Search' ) !== false, 'content: referrer categories' );
ok( strpos( $html, '>Browsers<' ) === false && strpos( $html, 'sn-an-heatmap' ) === false, 'content: technology/engagement panels NOT in this view (lazy per-tab render)' );

echo "\nGroup: dashboard — Technology view\n";
$_GET['sn_view'] = 'technology';
$html = capture( 'snt_analytics_render_dashboard' );
foreach ( array( 'Browsers', 'Operating systems', 'Devices', 'Protocols', 'TLS' ) as $p ) {
	ok( strpos( $html, $p ) !== false, "technology: '$p' panel present" );
}
ok( strpos( $html, 'Top pages' ) === false && strpos( $html, 'Cities' ) === false, 'technology: content/geography panels NOT in this view' );

echo "\nGroup: dashboard — Geography view\n";
$_GET['sn_view'] = 'geography';
$html = capture( 'snt_analytics_render_dashboard' );
foreach ( array( 'Cities', 'Regions', 'Networks', 'Edge locations' ) as $p ) {
	ok( strpos( $html, $p ) !== false, "geography: '$p' panel present" );
}

echo "\nGroup: dashboard — Engagement view\n";
$_GET['sn_view'] = 'engagement';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-heatmap' ) !== false, 'engagement: hour×dow heatmap rendered' );
ok( strpos( $html, 'Scroll depth' ) !== false && strpos( $html, 'Time on page' ) !== false, 'engagement: scroll + time distributions' );

echo "\nGroup: dashboard — Quality view\n";
$_GET['sn_view'] = 'quality';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-botbreak' ) !== false && strpos( $html, 'Amazon.com, Inc.' ) !== false, 'quality: bot breakdown + top bot ASN rendered' );

echo "\nGroup: dashboard — view param whitelist + escaping\n";
$_GET['sn_view'] = '../../etc/passwd';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages' ) !== false, 'view: junk sn_view falls back to content (default)' );
$_GET['sn_view'] = 'content';
$GLOBALS['__aa']['paths'] = array( array( 'path' => '/x"<script>', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, '<script>' ) === false, 'dashboard: path output escaped (no raw <script>)' );
unset( $_GET['sn_view'] );

echo "\nGroup: dashboard — unconfigured shows empty + Configure link, NOT the form\n";
$GLOBALS['__aa_config'] = false;
$html = capture( 'snt_analytics_render_dashboard' );
ok( stripos( $html, 'not receiving data' ) !== false || stripos( $html, 'isn' ) !== false, 'dashboard(unconfigured): shows the empty/config notice' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) === false, 'dashboard(unconfigured): does NOT embed the settings form' );
ok( stripos( $html, 'Configure' ) !== false, 'dashboard(unconfigured): links to the settings page' );

echo "\nGroup: settings section — the creds form + dashboard backlink\n";
$GLOBALS['__aa_config'] = false;
$GLOBALS['__aa_opts']   = array();
$html = capture( 'snt_analytics_render_settings_section' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) !== false, 'settings: account_id input present' );
ok( strpos( $html, 'name="sn_cf_analytics_token"' ) !== false, 'settings: token input present' );
ok( strpos( $html, 'value="analytics_save"' ) !== false, 'settings: analytics_save submit present' );
ok( strpos( $html, 'value="analytics_test"' ) !== false, 'settings: analytics_test submit present' );
ok( strpos( $html, 'wrangler' ) !== false && strpos( $html, 'SN_PX_TOKEN' ) !== false, 'settings: Worker-setup console present' );
ok( stripos( $html, 'View dashboard' ) !== false, 'settings: links back to the read-only dashboard' );
ok( strpos( $html, 'index.php?page=sn-analytics' ) !== false, 'settings: dashboard link targets the WP Dashboard → Analytics page' );

echo "\nGroup: settings section — escaping of stored values\n";
$GLOBALS['__aa_opts'] = array( SN_CF_ACCOUNT_ID_OPT => 'acct"<script>' );
$html = capture( 'snt_analytics_render_settings_section' );
ok( strpos( $html, '<script>' ) === false, 'settings: stored account_id with <script> is escaped' );
$GLOBALS['__aa_opts'] = array();

echo "\nGroup: the v5.3.0 Dashboard-tab hook is reverted (no auto-render on the plugin Dashboard tab)\n";
ok( strpos( file_get_contents( __DIR__ . '/../inc/analytics-admin.php' ), "add_action( 'sn_admin_dashboard_extras', 'snt_analytics_render" ) === false, 'revert: analytics no longer hooks sn_admin_dashboard_extras' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
