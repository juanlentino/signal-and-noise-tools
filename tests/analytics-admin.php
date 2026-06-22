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
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
// v6.23.0: snt_analytics_render_settings_section() now also renders the "Exclude
// my own visits" card, which reads sn_setting('analytics.exclude_roles'). Stub it
// (the card's full markup is covered by tests/analytics-exclusion-render.php; with
// no role list available here it renders its empty state and the creds-form
// assertions below are unaffected).
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $path, $default = null ) { return $default; } }
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
	if ( 'botscore' === $metric ) { return array( array( 'label' => '1–30', 'views' => 5 ), array( 'label' => '31–60', 'views' => 2 ), array( 'label' => '61–99', 'views' => 20 ) ); }
	return array( array( 'label' => '0–10s', 'views' => 7 ), array( 'label' => '3m+', 'views' => 2 ) );
}
function sn_analytics_percentiles( $metric, $from, $to, $class = 'human' ) {
	if ( 'scroll' === $metric ) { return array( array( 'label' => 'p50', 'value' => 63.0 ), array( 'label' => 'p75', 'value' => 84.0 ), array( 'label' => 'p90', 'value' => 95.0 ) ); }
	return array( array( 'label' => 'p50', 'value' => 38000.0 ), array( 'label' => 'p75', 'value' => 72000.0 ), array( 'label' => 'p90', 'value' => 220000.0 ) );
}
// Faithful to the real contract: reject no-colon, empty value, and unknown dim
// (so the negative assertions below can't be masked by an over-permissive stub).
function sn_analytics_drilldown_parse( $raw ) {
	$p = strpos( (string) $raw, ':' );
	if ( false === $p || 0 === $p ) { return null; }
	$dim = substr( $raw, 0, $p ); $val = substr( $raw, $p + 1 );
	$known = array( 'referrer', 'country', 'device', 'browser', 'os', 'region', 'city', 'network', 'colo', 'protocol', 'tls' );
	if ( '' === $val || ! in_array( $dim, $known, true ) ) { return null; }
	return array( $dim, $val );
}
function sn_analytics_drilldown( $dim, $value, $from, $to, $class = 'human' ) { return array( array( 'path' => '/x', 'views' => 9, 'visits' => 5 ) ); }
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
function sn_analytics_low_engagement_paths( $f, $t, $c = 'human', $l = 15 ) { return array(); }
function sn_analytics_dimension_series( $dim, $vals, $f, $t, $c = 'human', $g = 'day' ) {
	$out = array();
	foreach ( (array) $vals as $v ) { $out[ (string) $v ] = array( array( 'day' => '2026-06-11', 'views' => 3 ) ); }
	return $out;
}
function sn_analytics_class_series( $f, $t, $g = 'day' ) { return array( array( 'day' => '2026-06-11', 'bot_pct' => 30, 'total' => 80 ) ); }
function sn_analytics_top_events( $f, $t, $l = 25 ) { return $GLOBALS['__aa']['events'] ?? array(); }
function sn_analytics_top_event_props( $f, $t, $prop = '', $l = 50 ) { return $GLOBALS['__aa']['event_props'] ?? array(); }

// Canonical-source mapper (the content view's Top sources fold) — real module
// over the stubbed read accessors; its WP-seam calls are function_exists-guarded.
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
require_once __DIR__ . '/../inc/analytics-sources.php';
require_once __DIR__ . '/../inc/analytics-admin-render.php';
require_once __DIR__ . '/../inc/analytics-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
// Strip any <style> block so structural assertions (class names, counts) see markup
// only. As of v6.5.1 the dense layout CSS is an external enqueued stylesheet, so the
// dashboard body emits no <style>; this strip now only removes the world-map SVG's
// internal <style> on the geography view (defensive — keeps assertions markup-pure).
function capture( $cb ) { ob_start(); $cb(); return preg_replace( '!<style>.*?</style>!s', '', (string) ob_get_clean() ); }

echo "Analytics admin (dashboard + settings split)\n\n";

// v6.5.1: the dense layout CSS is now an EXTERNAL, enqueued stylesheet
// (assets/analytics/analytics-admin.css), not an inline <style> echoed mid-body by
// snt_analytics_styles() — a body-injected <style> could be dropped on the live page
// (edge/cache HTML rewriting, strict CSP, fragile once-guard), rendering it unstyled.
// The contract is now asserted against the file; the render-body check below confirms
// no inline <style> is emitted. See inc/admin-menu.php for the wp_enqueue_style wiring.
echo "Group: styles — CSS contract (external stylesheet)\n";
$css_path       = __DIR__ . '/../assets/analytics/analytics-admin.css';
$snt_styles_css = is_file( $css_path ) ? (string) file_get_contents( $css_path ) : '';
ok( '' !== $snt_styles_css, 'styles: analytics-admin.css exists and is non-empty (enqueued, not inline)' );
ok( strpos( $snt_styles_css, '.sn-kpi-row' ) !== false
	&& preg_match( '/\.sn-kpi-row\s*\{[^}]*display:\s*grid/s', $snt_styles_css ) === 1
	&& preg_match( '/\.sn-kpi-row\s*\{[^}]*grid-template-columns:/s', $snt_styles_css ) === 1,
	'styles: fused KPI strip is a CSS grid (the layout the live page must receive)' );
// v6.5.4: this page registers postboxes outside the metabox context, so core's
// .hndle padding never applies and panel titles hugged the box edge at 1px. The
// stylesheet must restore the header gutter so titles align with the content rail.
ok( preg_match( '/\.postbox[^{]*\.hndle\s*\{[^}]*padding:\s*[1-9]/s', $snt_styles_css ) === 1,
	'styles: postbox header title (.hndle) has an explicit padding gutter (titles do not hug the edge)' );
ok( strpos( $snt_styles_css, '.sn-map-figure svg' ) !== false,
	'styles: .sn-map-figure svg responsive rule present (constrains vendored 2000px SVG inside its column)' );
ok( strpos( $snt_styles_css, 'max-width:720px' ) === false && strpos( $snt_styles_css, 'max-width: 720px' ) === false,
	'styles: .sn-an-choropleth max-width:720px cap removed (no longer constrains map column)' );

echo "\nGroup: range + class resolution\n";
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
	$GLOBALS['__aa']['events']      = array( array( 'name' => 'signup', 'events' => 120, 'visitors' => 90 ) );
	$GLOBALS['__aa']['event_props'] = array( array( 'property' => 'utm_source', 'value' => 'hn', 'events' => 50, 'visitors' => 40 ) );
}

echo "\nGroup: dashboard — core render\n";
aa_fill_data();
// Raw (unstripped) body: the default 'content' view renders no choropleth SVG, so
// ANY <style> here would be the regressed inline echo. The CSS must come from the
// enqueued external stylesheet, never the body.
ob_start(); snt_analytics_render_dashboard(); $raw_body = (string) ob_get_clean();
ok( strpos( $raw_body, '<style' ) === false, 'dashboard: no inline <style> echoed in body (CSS is enqueued externally)' );
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, '1,204' ) !== false, 'dashboard: views stat card formatted' );
ok( strpos( $html, 'sn-kpi-value">7<' ) !== false, 'cards: Now card value (7) rendered in sn-kpi-value element' );
ok( strpos( $html, '312 automated filtered (268 bot · 44 suspect)' ) !== false, 'dashboard: separation line' );
ok( strpos( $html, 'notice notice-info inline' ) !== false, 'controls: separation wrapped in native notice-info inline' );
ok( strpos( $html, 'sn-toolbar' ) !== false, 'controls: native toolbar wrapper present' );
ok( strpos( $html, 'button-group' ) !== false, 'controls: button-group pill rows present' );
ok( strpos( $html, 'button button-small' ) !== false, 'controls: pills use button button-small class' );
ok( strpos( $html, '/notes/x' ) !== false, 'dashboard: top path row present' );
ok( strpos( $html, 'sn-kpi-row' ) !== false, 'cards: fused KPI strip rendered' );
ok( substr_count( $html, 'sn-kpi-promoted' ) === 2, 'cards: Views + Visits promoted' );
ok( strpos( $html, '<div class="n">7</div>' ) === false, 'cards: old .n markup gone' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) === false, 'dashboard: read-only — NO settings form embedded (split)' );
ok( strpos( $html, 'value="analytics_save"' ) === false, 'dashboard: read-only — NO save button (split)' );

echo "\nGroup: dashboard — period-over-period deltas on cards\n";
ok( strpos( $html, 'sn-delta-down' ) !== false && strpos( $html, 'sn-delta-up' ) !== false, 'cards: up + down deltas' );

echo "\nGroup: dashboard — view tab nav\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'nav-tab-wrapper' ) !== false, 'tabs: WP-native nav-tab-wrapper present' );
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality', 'events' ) as $v ) {
	ok( strpos( $html, 'sn_view=' . $v ) !== false, "tabs: link to '$v' view present" );
}
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'tabs: exactly one active tab' );
ok( strpos( $html, 'page=sn-analytics' ) !== false, 'tabs: links target the current page (sn-analytics)' );

echo "\nGroup: dashboard — trend: smooth SVG area chart (v6.5.2)\n";
aa_fill_data();
$_GET['sn_view'] = 'content';
$html_trend = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_trend, 'sn-spark' ) !== false && strpos( $html_trend, '<svg' ) !== false, 'trend: SVG sparkline rendered' );
ok( strpos( $html_trend, '<path' ) !== false, 'trend: SVG path present' );
ok( strpos( $html_trend, '<polyline' ) === false && preg_match( '/<path d="M [\d.]+,[\d.]+ C /', $html_trend ) === 1,
	'trend: line is a smoothed bézier path (C commands), not an angular polyline' );
ok( strpos( $html_trend, 'vector-effect="non-scaling-stroke"' ) !== false,
	'trend: non-scaling-stroke keeps the line crisp under the full-width stretch' );
ok( strpos( $html_trend, 'class="bar"' ) === false, 'trend: old chunky bars gone' );

echo "\nGroup: dashboard — fused Overview panel (KPIs + trend in ONE postbox)\n";
ok( strpos( $html_trend, 'postbox sn-overview' ) !== false, 'overview: single fused .sn-overview postbox present' );
ok( strpos( $html_trend, 'sn-overview-trend' ) !== false, 'overview: trend band rendered inside the panel (no separate box)' );
// The KPI strip and the trend band share one .inside — the trend follows the KPI row.
$kpi_pos   = strpos( $html_trend, 'sn-kpi-row' );
$trend_pos = strpos( $html_trend, 'sn-overview-trend' );
ok( false !== $kpi_pos && false !== $trend_pos && $kpi_pos < $trend_pos,
	'overview: KPI strip precedes the trend band within the fused panel' );
ok( strpos( $html_trend, '>Daily views<' ) === false, 'overview: redundant standalone "Daily views" header removed' );

echo "\nGroup: table panels keep the native widget gutter (not flush) (v6.5.3)\n";
$_GET['sn_view'] = 'content';
$html_tbl = capture( 'snt_analytics_render_dashboard' );
// The widefat data tables must sit in a padded .inside (native widget gutter), not
// the full-bleed .inside-flush the KPI strip / chart / map use — otherwise the text
// hugs the box edges.
ok( strpos( $html_tbl, 'Top pages</span></h2></div><div class="inside sn-an-table-inside"' ) !== false,
	'tables: Top pages panel uses the padded .sn-an-table-inside (not flush)' );
ok( strpos( $html_tbl, 'inside sn-an-table-inside' ) !== false,
	'tables: padded table-inside wrapper present on the Content view' );
$css_pad = is_file( $css_path ) ? (string) file_get_contents( $css_path ) : '';
ok( preg_match( '/\.sn-an-table-inside\s*\{[^}]*padding:/s', $css_pad ) === 1,
	'tables: .sn-an-table-inside defines an explicit side gutter (version-robust, not core-dependent)' );

echo "\nGroup: dashboard — persistent header on every tab\n";
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality', 'events' ) as $v ) {
	$_GET['sn_view'] = $v;
	$h = capture( 'snt_analytics_render_dashboard' );
	ok(
		strpos( $h, 'sn-kpi-row' ) !== false && strpos( $h, 'sn-toolbar' ) !== false && strpos( $h, 'sn-spark' ) !== false,
		"header: controls + KPI strip + trend persist on the '$v' tab"
	);
}

echo "\nGroup: dashboard — Content view (default)\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages' ) !== false && strpos( $html, 'Top sources' ) !== false, 'content: pages/sources panels' );
ok( strpos( $html, '>Countries<' ) === false, 'content: Countries panel relocated OUT of Content' );
ok( strpos( $html, 'sn-an-refcats' ) !== false && strpos( $html, 'Search' ) !== false, 'content: referrer categories' );
ok( strpos( $html, '>Browsers<' ) === false && strpos( $html, 'sn-an-heatmap' ) === false, 'content: technology/engagement panels NOT in this view (lazy per-tab render)' );
ok( strpos( $html, 'wp-list-table widefat' ) !== false, 'content: dimension/path tables use native widefat class' );
ok( strpos( $html, 'postbox' ) !== false, 'content: panels wrapped in native postbox' );
ok( strpos( $html, 'class="sn-an-panel"' ) === false, 'content: old bare sn-an-panel wrapper gone (migrated to postbox)' );

echo "\nGroup: dashboard — Technology view\n";
$_GET['sn_view'] = 'technology';
$html = capture( 'snt_analytics_render_dashboard' );
foreach ( array( 'Browsers', 'Operating systems', 'Devices', 'Protocols', 'TLS' ) as $p ) {
	ok( strpos( $html, $p ) !== false, "technology: '$p' panel present" );
}
ok( strpos( $html, 'Top pages' ) === false && strpos( $html, 'Cities' ) === false, 'technology: content/geography panels NOT in this view' );
ok( substr_count( $html, 'sn-an-spark' ) >= 1, 'technology: sparkline column rendered on OS/devices tables' );
ok( strpos( $html, 'wp-list-table widefat' ) !== false, 'technology: dimension tables use native widefat class' );

echo "\nGroup: dashboard — Geography view\n";
$_GET['sn_view'] = 'geography';
$GLOBALS['__aa']['dim'] = array( array( 'value' => 'US', 'views' => 312, 'visits' => 98 ) );
$html = capture( 'snt_analytics_render_dashboard' );
foreach ( array( 'Countries', 'Cities', 'Regions', 'Networks', 'Edge locations' ) as $p ) {
	ok( strpos( $html, $p ) !== false, "geography: '$p' panel present" );
}
ok( strpos( $html, '>Countries<' ) < strpos( $html, '>Cities<' ), 'geography: Countries renders first (above Cities)' );
ok( strpos( $html, 'sn-an-choropleth' ) !== false, 'geography: choropleth panel rendered' );
ok( strpos( $html, 'sn-an-choropleth' ) < strpos( $html, '>Countries<' ), 'geography: choropleth renders before the Countries table' );
ok( strpos( $html, 'wp-list-table widefat' ) !== false, 'geography: country/city tables use native widefat class' );
ok( strpos( $html, 'sn-geo-split' ) !== false, 'geography: map+countries split layout wrapper present' );
ok( strpos( $html, 'sn-geo-tiles' ) !== false, 'geography: tiles grid (cities/regions/networks/edge) present' );
$GLOBALS['__aa']['dim'] = array( array( 'value' => 'news.ycombinator.com', 'views' => 312, 'visits' => 98 ) );

echo "\nGroup: dashboard — Engagement view\n";
$_GET['sn_view'] = 'engagement';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-heatmap' ) !== false, 'engagement: hour×dow heatmap rendered' );
ok( strpos( $html, 'Scroll depth' ) !== false && strpos( $html, 'Time on page' ) !== false, 'engagement: scroll + time distributions' );
ok( strpos( $html, 'postbox' ) !== false, 'engagement: panels wrapped in native postbox' );
ok( strpos( $html, 'Scroll depth — percentiles' ) !== false && strpos( $html, 'Time on page — percentiles' ) !== false, 'engagement: scroll + time percentile panels' );
ok( strpos( $html, 'sn-an-pctl-chip' ) !== false && strpos( $html, '63%' ) !== false, 'engagement: percentile chips render with values' );

echo "\nGroup: dashboard — drill-down\n";
$_GET['sn_view']  = 'geography';
$_GET['sn_drill'] = 'country:US';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages · Country = US' ) !== false, 'drill: panel renders on the view that owns the dim' );
ok( strpos( $html, 'sn_drill=country%3A' ) !== false, 'drill: Countries table values are drill links (colon URL-encoded by add_query_arg)' );
ok( strpos( $html, 'sn_view=technology' ) !== false && strpos( explode( '</nav>', $html )[0], 'sn_drill' ) === false, 'drill: tab links do NOT carry sn_drill (cleared on tab switch)' );
// View-gate: a valid drill whose dim is NOT on the active view shows NO panel.
$_GET['sn_view'] = 'technology';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-drill' ) === false, 'drill: no orphan panel on a view that does not own the dim (country on technology)' );
// Invalid drill tokens parse to null → no panel.
foreach ( array( 'martian:x', 'country:', 'garbage' ) as $bad ) {
	$_GET['sn_view'] = 'geography';
	$_GET['sn_drill'] = $bad;
	$h = capture( 'snt_analytics_render_dashboard' );
	ok( strpos( $h, 'sn-an-drill' ) === false, "drill: invalid token '$bad' renders no panel" );
}
unset( $_GET['sn_drill'] );

echo "\nGroup: dashboard — Quality view\n";
$_GET['sn_view'] = 'quality';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-botbreak' ) !== false && strpos( $html, 'Amazon.com, Inc.' ) !== false, 'quality: bot breakdown + top bot ASN rendered' );
ok( strpos( $html, 'sn-an-bot-trend' ) !== false, 'quality tab renders the bot-share trend when data present' );
ok( strpos( $html, 'Bot confidence' ) !== false, 'quality: bot-confidence distribution panel rendered' );
ok( strpos( $html, '1–30' ) !== false || strpos( $html, '61–99' ) !== false, 'quality: bot-confidence bands rendered' );
ok( strpos( $html, 'postbox' ) !== false, 'quality: panels wrapped in native postbox' );

echo "\nGroup: dashboard — Events view (new tab)\n";
$_GET['sn_view'] = 'events';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn_view=events' ) !== false, 'events: tab link present in nav' );
ok( strpos( $html, 'Custom events' ) !== false && strpos( $html, 'signup' ) !== false, 'events: leaderboard rendered' );
ok( strpos( $html, 'Event properties' ) !== false && strpos( $html, 'utm_source' ) !== false, 'events: property breakdown rendered' );
ok( stripos( $html, 'not segmented by' ) !== false, 'events: class-inert note present' );
ok( strpos( $html, 'Top pages' ) === false && strpos( $html, 'sn-an-heatmap' ) === false, 'events: other tabs panels NOT in this view' );
// Event-property drill-down via ?sn_event_prop reload.
$_GET['sn_event_prop'] = 'utm_source';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Property: <strong>utm_source</strong>' ) !== false, 'events: drill-down filters to one property' );
unset( $_GET['sn_event_prop'] );
$_GET['sn_view'] = 'content';

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

echo "\nGroup: dashboard — date-range presets + custom window\n";
aa_fill_data();
$_GET['sn_view'] = 'content';
$_GET['sn_range'] = 'ytd';
$h = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $h, 'sn-an-daterange' ) !== false, 'date-range: custom/preset disclosure present' );
ok( strpos( $h, 'Year to date' ) !== false && strpos( $h, 'Previous year' ) !== false, 'date-range: preset links rendered' );
ok( strpos( $h, 'sn_range=ytd' ) !== false, 'date-range: YTD preset is a GET link' );
$_GET['sn_range'] = 'custom';
$_GET['sn_from']  = '2026-05-20';
$_GET['sn_to']    = '2026-06-10';
$h = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $h, 'type="date"' ) !== false && strpos( $h, 'name="sn_from"' ) !== false, 'date-range: custom form has date inputs' );
ok( strpos( $h, 'value="custom"' ) !== false, 'date-range: custom form posts sn_range=custom' );
ok( strpos( $h, 'value="2026-05-20"' ) !== false, 'date-range: custom from prefilled into the date input' );
unset( $_GET['sn_from'], $_GET['sn_to'] );
$_GET['sn_range'] = '7';
$_GET['sn_view']  = 'content';

echo "\nGroup: login-defense view registration + owns-chrome predicate\n";
ok( snt_analytics_resolve_view( 'login-defense' ) === 'login-defense', 'resolve_view: login-defense is a registered view' );
ok( isset( SN_ANALYTICS_VIEWS['login-defense'] ) && SN_ANALYTICS_VIEWS['login-defense'] === 'Login defense', 'registry: login-defense => Login defense' );
ok( snt_analytics_view_owns_chrome( 'login-defense' ) === true, 'owns_chrome: login-defense owns its chrome' );
ok( snt_analytics_view_owns_chrome( 'content' ) === false, 'owns_chrome: content uses shared chrome' );
ok( snt_analytics_view_owns_chrome( 'edge' ) === false, 'owns_chrome: edge keeps shared chrome (no regression)' );
ok( snt_analytics_view_owns_chrome( 'martian' ) === false, 'owns_chrome: unknown view -> false' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
