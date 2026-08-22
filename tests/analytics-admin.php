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

// v12.10.0 seam: the Analytics screen moved to its own top-level menu and its
// URL is now an accessor owned by inc/analytics-dashboard-page.php. Stubbed
// here rather than guarded with function_exists() in the producer — a guard
// there would silently emit an empty href and every link assertion would still
// pass.
if ( ! function_exists( 'snt_analytics_page_url' ) ) {
	function snt_analytics_page_url( $args = array() ) {
		$url = 'https://example.test/wp-admin/admin.php?page=sn-analytics';
		if ( is_array( $args ) && array() !== $args ) {
			foreach ( $args as $k => $v ) { $url .= '&' . $k . '=' . $v; }
		}
		return $url;
	}
}


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
// S2 §3 (v9.42.0 arc): the funnels card's textarea (inc/analytics-render-settings.php).
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
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
// v9.36.0: the settings hub renders the engine-tuning radios (checked() at radio render).
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$r = ( (string) $a === (string) $b ) ? ' checked' : '';
		if ( $echo ) { echo $r; }
		return $r;
	}
}
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
// Task 5 (S2 §5): call counters so the unconfigured-render group can pin ZERO
// accessor reads — the dashboard must not touch any of these five behind the
// config gate. Reset via aa_reset_call_counts() before the assertion window.
$GLOBALS['__aa_calls'] = array( 'realtime' => 0, 'range_totals' => 0, 'class_totals' => 0, 'daily_series' => 0, 'top_paths' => 0 );
function aa_reset_call_counts() { $GLOBALS['__aa_calls'] = array( 'realtime' => 0, 'range_totals' => 0, 'class_totals' => 0, 'daily_series' => 0, 'top_paths' => 0 ); }
function sn_analytics_realtime( $class = 'human' ) { ++$GLOBALS['__aa_calls']['realtime']; return $GLOBALS['__aa']['realtime']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { ++$GLOBALS['__aa_calls']['range_totals']; return $GLOBALS['__aa']['totals']; }
function sn_analytics_class_totals( $from, $to ) { ++$GLOBALS['__aa_calls']['class_totals']; return $GLOBALS['__aa']['class_totals']; }
function sn_analytics_daily_series( $from, $to, $class = 'human', $granularity = 'day' ) { ++$GLOBALS['__aa_calls']['daily_series']; return $GLOBALS['__aa']['series']; }
function sn_analytics_granularity( $days ) { return ( (int) $days > 90 ) ? 'week' : 'day'; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { ++$GLOBALS['__aa_calls']['top_paths']; return $GLOBALS['__aa']['paths']; }
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__aa']['dim']; }

// Derived + buckets seams (the dashboard composes these; isolated here).
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	return array(
		'views'               => array( 'current' => 1204, 'previous' => 600, 'pct' => 101, 'dir' => 'up' ),
		'visits'              => array( 'current' => 389,  'previous' => 400, 'pct' => -3,  'dir' => 'down' ),
		'scroll_avg'          => array( 'current' => 62.0, 'previous' => 62.0, 'pct' => 0,  'dir' => 'flat' ),
		'time_avg'            => array( 'current' => 108.0, 'previous' => 90.0, 'pct' => 20, 'dir' => 'up' ),
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
	if ( 'scroll' === $metric ) { return array( array( 'label' => '0–25%', 'views' => 4 ), array( 'label' => '75–100%', 'views' => 40 ) ); }
	if ( 'botscore' === $metric ) { return array( array( 'label' => '1–30', 'views' => 5 ), array( 'label' => '31–60', 'views' => 2 ), array( 'label' => '61–99', 'views' => 20 ) ); }
	return array( array( 'label' => '0–10s', 'views' => 7 ), array( 'label' => '3m+', 'views' => 2 ) );
}
function sn_analytics_percentiles( $metric, $from, $to, $class = 'human' ) {
	if ( 'scroll' === $metric ) { return array( array( 'label' => 'p50', 'value' => 63.0 ), array( 'label' => 'p75', 'value' => 84.0 ), array( 'label' => 'p90', 'value' => 95.0 ) ); }
	return array( array( 'label' => 'p50', 'value' => 38000.0 ), array( 'label' => 'p75', 'value' => 72000.0 ), array( 'label' => 'p90', 'value' => 220000.0 ) );
}
function sn_analytics_engagement_anomalies( $from, $to, $class = 'human' ) {
	return array( 'divergence' => array(), 'outliers' => array() );
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
$GLOBALS['__insights_html'] = '<details class="sn-an-headline">MOUNTED</details>';
if ( ! function_exists( 'snt_analytics_render_insights_band' ) ) {
	function snt_analytics_render_insights_band( $from, $to, $class, $g ) { echo $GLOBALS['__insights_html']; }
}
require_once __DIR__ . '/../inc/analytics-sources.php';
// v8.5.0 header-region dependencies: the movers tile + panel primitive run
// REAL over the stubbed accessors; uptime surfaces stay absent (the region's
// function_exists guards skip them, matching an unconfigured install).
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__aa_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__aa_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'sn_analytics_prior_window' ) ) { function sn_analytics_prior_window( $f, $t ) { return array( $f, $t ); } }
// S2 §3 (v9.42.0 arc): snt_analytics_render_funnels() (inc/analytics-render-settings.php,
// pulled in via analytics-admin-render.php below) calls sn_analytics_funnels_to_text()
// — mirrors production load order (signal-and-noise-tools.php requires
// analytics-sessions.php before analytics-admin-render.php/analytics-admin.php).
require_once __DIR__ . '/../inc/analytics-sessions.php';
require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-movers.php';
require_once __DIR__ . '/../inc/analytics-header-region.php';
require_once __DIR__ . '/../inc/analytics-view-content.php';
require_once __DIR__ . '/../inc/analytics-view-overview.php'; // v9.68.0: the default landing view
require_once __DIR__ . '/../inc/analytics-view-technology.php';
require_once __DIR__ . '/../inc/analytics-view-geography.php';
require_once __DIR__ . '/../inc/analytics-view-engagement.php';
require_once __DIR__ . '/../inc/analytics-view-quality.php';
require_once __DIR__ . '/../inc/analytics-view-events.php';
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
echo "Group: styles. CSS contract (external stylesheet)\n";
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
ok( snt_analytics_resolve_view( 'martian' ) === 'overview', 'resolve_view: unknown → overview default (v9.68.0 promotion)' );
ok( snt_analytics_resolve_view( '' ) === 'overview', 'resolve_view: empty → overview default' );
ok( snt_analytics_resolve_view( 'content' ) === 'content', 'resolve_view: content still resolves (a normal tab now)' );

echo "\nGroup: date math\n";
list( $from, $to ) = snt_analytics_range_dates( 7, gmmktime( 0, 0, 0, 6, 11, 2026 ) );
ok( $to === '2026-06-11', 'range_dates: $to is the anchor day (UTC)' );
ok( $from === '2026-06-05', 'range_dates: 7-day window is inclusive (anchor-6)' );

// Fixtures for a fully-configured dashboard render.
function aa_fill_data() {
	$GLOBALS['__aa_config']          = true;
	$GLOBALS['__aa_error']           = null;
	$GLOBALS['__aa']['realtime']     = 7;
	// The REAL sn_analytics_range_totals() contract since v9.63.0: legacy
	// quartet + the spec-§4 derived fields + exact_metrics_since (a legacy-only
	// stub here is exactly the stub-drift trap — the honest Overview reads the
	// derived keys).
	$GLOBALS['__aa']['totals']       = array(
		'views' => 1204, 'visits' => 389, 'scroll_avg' => 62.0, 'time_avg' => 108.0,
		'unique_visitor_days' => 389, 'pageview_visits' => 340, 'viewless_visits' => 49,
		'view_visit_ratio' => 1204 / 340, 'pageviews_per_visitor_day' => 1204 / 389,
		'scroll_avg_per_view' => 55.0, 'time_avg_per_view' => 98000.0,
		'scroll_avg_per_visit' => 48.0, 'time_avg_per_visit' => 85000.0,
		'integrity_violation' => false, 'exact_metrics_since' => '2026-04-18',
	);
	$GLOBALS['__aa']['class_totals'] = array( 'human' => array( 'views' => 1204, 'visits' => 389 ), 'bot' => array( 'views' => 268, 'visits' => 12 ), 'suspect' => array( 'views' => 44, 'visits' => 9 ) );
	$GLOBALS['__aa']['series']       = array( array( 'day' => '2026-06-10', 'views' => 100, 'visits' => 40 ), array( 'day' => '2026-06-11', 'views' => 300, 'visits' => 90 ) );
	$GLOBALS['__aa']['paths']        = array( array( 'path' => '/notes/x', 'views' => 412, 'visits' => 158, 'scroll_avg' => 71.0, 'time_avg' => 150.0 ) );
	$GLOBALS['__aa']['dim']          = array( array( 'value' => 'news.ycombinator.com', 'views' => 312, 'visits' => 98 ) );
	$GLOBALS['__aa']['events']      = array( array( 'name' => 'signup', 'events' => 120, 'visitors' => 90 ) );
	$GLOBALS['__aa']['event_props'] = array( array( 'property' => 'utm_source', 'value' => 'hn', 'events' => 50, 'visitors' => 40 ) );
}

echo "\nGroup: dashboard: core render\n";
aa_fill_data();
// v9.68.0: the default landing is now the Overview view, so this group (the
// CONTENT view's shared-header pins, e.g. the /notes/x top-paths row) selects
// its view explicitly. The overview-default render has its own group below.
$_GET['sn_view'] = 'content';
// Raw (unstripped) body: the content view renders no choropleth SVG, so
// ANY <style> here would be the regressed inline echo. The CSS must come from the
// enqueued external stylesheet, never the body.
ob_start(); snt_analytics_render_dashboard(); $raw_body = (string) ob_get_clean();
ok( strpos( $raw_body, '<style' ) === false, 'dashboard: no inline <style> echoed in body (CSS is enqueued externally)' );
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, '1,204' ) !== false, 'dashboard: views stat card formatted' );
ok( strpos( $html, 'sn-kpi-value">7<' ) !== false, 'cards: Now card value (7) rendered in sn-kpi-value element' );
ok( strpos( $html, '312 automated filtered (268 bot · 44 suspect)' ) !== false, 'dashboard: separation meta (counts survive the notice retirement)' );
ok( strpos( $html, 'sn-an-sep-meta' ) !== false, 'controls: separation folded into the toolbar as muted meta' );
ok( strpos( $html, 'notice notice-info inline' ) === false, 'controls: the permanent notice-info block is GONE (notices are transient, not chrome)' );
$tb_open  = strpos( $html, '<div class="sn-toolbar">' );
$tb_meta  = strpos( $html, 'sn-an-sep-meta' );
// D3: the old trailing daterange disclosure is gone; the ONE range control now
// lives INSIDE the toolbar, so the after-toolbar marker is the per-view content
// wrapper (<div class="sn-an-view">) that follows the whole header region. A
// bare strpos() would match the EARLIER 'sn-an-view-tabs' nav (tabs lead, D1) —
// search from $tb_meta forward so the match is the wrapper, not the tab strip.
$tb_close = strpos( $html, 'sn-an-view', $tb_meta );
ok( false !== $tb_open && false !== $tb_meta && false !== $tb_close && $tb_open < $tb_meta && $tb_meta < $tb_close, 'controls: meta sits INSIDE the .sn-toolbar row' );
ok( strpos( $html, 'sn-toolbar' ) !== false, 'controls: native toolbar wrapper present' );
ok( strpos( $html, 'button-group' ) !== false, 'controls: button-group pill rows present' );
ok( strpos( $html, 'button button-small' ) !== false, 'controls: pills use button button-small class' );
ok( strpos( $html, '/notes/x' ) !== false, 'dashboard: top path row present' );
ok( strpos( $html, 'sn-kpi-row' ) !== false, 'cards: fused KPI strip rendered' );
ok( substr_count( $html, 'sn-kpi-promoted' ) === 2, 'cards: Views + Visits promoted' );
ok( strpos( $html, '<div class="n">7</div>' ) === false, 'cards: old .n markup gone' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) === false, 'dashboard: read-only. NO settings form embedded (split)' );
ok( strpos( $html, 'value="analytics_save"' ) === false, 'dashboard: read-only. NO save button (split)' );

echo "\nGroup: D1: separation meta only when automated traffic exists\n";
ob_start(); snt_analytics_render_controls( '7', 'human', '', '', 'off', array( 'human' => array( 'views' => 900 ), 'bot' => array( 'views' => 0 ), 'suspect' => array( 'views' => 0 ) ) ); $ctl0 = (string) ob_get_clean();
ok( strpos( $ctl0, 'sn-an-sep-meta' ) === false, 'meta: hidden when auto=0 (nothing to disclose)' );
ob_start(); snt_analytics_render_controls( '7', 'human', '', '', 'off', array( 'human' => array( 'views' => 1204 ), 'bot' => array( 'views' => 268 ), 'suspect' => array( 'views' => 44 ) ) ); $ctl1 = (string) ob_get_clean();
ok( strpos( $ctl1, '312 automated filtered (268 bot · 44 suspect)' ) !== false && strpos( $ctl1, '21% of all traffic' ) !== false, 'meta: counts + share (312/1516 → 21%)' );
ok( strpos( $ctl1, 'sn-an-sep-meta">312' ) !== false, 'meta: starts with the count: no leading orphan bullet (flex gap separates)' );
ok( strpos( $ctl1, 'Showing' ) === false, 'meta: the "Showing human traffic" clause is dropped (the active class pill already says it)' );

echo "\nGroup: dashboard: period-over-period deltas on cards\n";
ok( strpos( $html, 'sn-delta-down' ) !== false && strpos( $html, 'sn-delta-up' ) !== false, 'cards: up + down deltas' );

echo "\nGroup: dashboard: view tab nav\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'nav-tab-wrapper' ) !== false, 'tabs: WP-native nav-tab-wrapper present' );
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality', 'events' ) as $v ) {
	ok( strpos( $html, 'sn_view=' . $v ) !== false, "tabs: link to '$v' view present" );
}
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'tabs: exactly one active tab' );
ok( strpos( $html, 'page=sn-analytics' ) !== false, 'tabs: links target the current page (sn-analytics)' );
// v9.29.0: the dedicated UTM Campaigns view is a first-class tab in the strip.
ok( strpos( $html, 'sn_view=campaigns' ) !== false && strpos( $html, '>Campaigns<' ) !== false, 'tabs: the Campaigns view is registered and rendered in the tab strip' );
// v9.37.0 (D1): tabs lead the page; the headline band sits between the tabs
// and the toolbar; Overview follows.
$tabs = strpos( $html, 'sn-an-view-tabs' );
$band = strpos( $html, 'sn-an-headline' );
$tool = strpos( $html, 'sn-toolbar' );
// v9.68.0: 'Overview' now also appears as a tab LABEL in the strip above, so
// anchor on the Overview panel's own postbox class, not the bare word.
$ov   = strpos( $html, 'postbox sn-overview' );
ok( false !== $tabs && false !== $band && $tabs < $band, 'D1 order: tabs above the headline band' );
ok( false !== $tool && $band < $tool, 'D1 order: headline band above the toolbar' );
ok( false !== $ov && $tool < $ov, 'D1 order: toolbar above the Overview' );

echo "\nGroup: v9.68.0: the Overview is the DEFAULT landing (promoted, wired, shared chrome)\n";
// Registry: the permanent 'overview' slug leads the registry; the flag-gated
// preview machinery is GONE (the experiment graduated).
ok( 'overview' === array_key_first( SN_ANALYTICS_VIEWS ), 'registry: overview is FIRST' );
ok( 'Overview' === ( SN_ANALYTICS_VIEWS['overview'] ?? '' ), 'registry: labeled "Overview"' );
ok( 13 === count( SN_ANALYTICS_VIEWS ), 'registry: 13 views (R6b: +search, the pre-click half with no first-party counterpart)' );
ok( snt_analytics_views() === SN_ANALYTICS_VIEWS, 'effective registry: identical to the const: no flag branch left' );
ok( ! function_exists( 'snt_analytics_landing_preview_enabled' ), 'flag: the sn_analytics_landing_preview helper no longer exists' );
ok( 'overview' === snt_analytics_resolve_view( 'overview' ), 'resolve_view: overview resolves' );
ok( 'overview' === snt_analytics_resolve_view( 'overview-lab' ), 'resolve_view: the retired lab slug falls back to the new default' );
ok( false === snt_analytics_view_owns_chrome( 'overview' ), 'owns_chrome: overview INHERITS the shared header (the mock owned its chrome; the wired tab does not)' );
// Render with NO ?sn_view: the overview tab is active, the shared chrome
// renders above it, and the wired body renders below.
aa_fill_data();
unset( $_GET['sn_view'] );
$html_ovd = capture( 'snt_analytics_render_dashboard' );
ok( preg_match( '/nav-tab nav-tab-active" href="[^"]*sn_view=overview(&|")/', $html_ovd ) === 1, 'default: the Overview tab is the active tab with no ?sn_view' );
ok( substr_count( $html_ovd, 'nav-tab-active' ) === 1, 'default: exactly one active tab' );
ok( strpos( $html_ovd, 'sn-toolbar' ) !== false, 'default: inherits the shared range/class/compare controls' );
ok( strpos( $html_ovd, 'sn-an-headline' ) !== false, 'default: inherits the insights band' );
ok( strpos( $html_ovd, 'postbox sn-overview' ) !== false, 'default: inherits the shared Overview KPI card (the headline: no duplicate in the body)' );
ok( strpos( $html_ovd, 'sn-an-movers' ) !== false, 'default: inherits the movers rail' );
ok( strpos( $html_ovd, 'Right now' ) !== false, 'default: the wired body renders below the shared header' );
ok( strpos( $html_ovd, 'Hacker News' ) !== false, 'default: the sources mini is wired through the canonical mapper (news.ycombinator.com → Hacker News)' );
ok( strpos( $html_ovd, 'sn-an-lab-badge' ) === false && strpos( $html_ovd, 'PREVIEW' ) === false, 'default: no preview badge anywhere (graduated)' );
ok( strpos( $html_ovd, 'sn_view=content' ) !== false, 'default: Content remains a normal tab in the strip' );

echo "\nGroup: D1: headline band gating\n";
if ( ! function_exists( 'snt_edge_render_view' ) ) {
	function snt_edge_render_view( $f, $t ) { echo '<!--EDGE-VIEW-->'; }
}
$_GET['sn_view'] = 'edge';
$html_edge = capture( 'snt_analytics_render_dashboard' );
ok( false === strpos( $html_edge, 'sn-an-headline' ), 'gating: edge (not class-segmented) renders NO headline band' );
ok( false !== strpos( $html_edge, 'sn-toolbar' ), 'gating: edge keeps the shared header (regression guard: deliberate)' );
$_GET['sn_view'] = 'content';

echo "\nGroup: dashboard: trend: smooth SVG area chart (v6.5.2)\n";
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

echo "\nGroup: dashboard: fused Overview panel (KPIs + trend in ONE postbox)\n";
ok( strpos( $html_trend, 'postbox sn-overview' ) !== false, 'overview: single fused .sn-overview postbox present' );
ok( strpos( $html_trend, 'sn-overview-trend' ) !== false, 'overview: trend band rendered inside the panel (no separate box)' );
// The KPI strip and the trend band share one .inside — the trend follows the KPI row.
$kpi_pos   = strpos( $html_trend, 'sn-kpi-row' );
$trend_pos = strpos( $html_trend, 'sn-overview-trend' );
ok( false !== $kpi_pos && false !== $trend_pos && $kpi_pos < $trend_pos,
	'overview: KPI strip precedes the trend band within the fused panel' );
ok( strpos( $html_trend, '>Daily views<' ) === false, 'overview: redundant standalone "Daily views" header removed' );

echo "\nGroup: D1: pulse footer inside the Overview (content only)\n";
$p_pos = strpos( $html_trend, 'sn-an-pulse' );
$r_pos = strpos( $html_trend, 'sn-an-header-rail' );
ok( false !== $p_pos && false !== $r_pos && $p_pos < $r_pos, 'pulse: footer renders inside the Overview panel on Content' );
// Adaptation: the plan's 'visits' view isn't wired into this fixture (no
// inc/analytics-view-sessions.php require + no snt_analytics_render_view_sessions
// stub — it would fatal). 'technology' is already exercised in this suite
// (Group: dashboard — Technology view, above) and is an equally valid
// non-Content, shared-chrome view for the gating check.
$_GET['sn_view'] = 'technology';
$html_technology = capture( 'snt_analytics_render_dashboard' );
ok( false === strpos( $html_technology, 'sn-an-pulse' ), 'pulse: absent on non-Content views' );
$_GET['sn_view'] = 'content';

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

echo "\nGroup: dashboard: persistent header on every tab\n";
foreach ( array( 'content', 'technology', 'geography', 'engagement', 'quality', 'events' ) as $v ) {
	$_GET['sn_view'] = $v;
	$h = capture( 'snt_analytics_render_dashboard' );
	ok(
		strpos( $h, 'sn-kpi-row' ) !== false && strpos( $h, 'sn-toolbar' ) !== false && strpos( $h, 'sn-spark' ) !== false,
		"header: controls + KPI strip + trend persist on the '$v' tab"
	);
}

echo "\nGroup: dashboard. Content view (default)\n";
$_GET['sn_view'] = 'content';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages' ) !== false && strpos( $html, 'Top sources' ) !== false, 'content: pages/sources panels' );
ok( strpos( $html, '>Countries<' ) === false, 'content: Countries panel relocated OUT of Content' );
ok( strpos( $html, 'sn-an-refcats' ) !== false && strpos( $html, 'Search' ) !== false, 'content: referrer categories' );
ok( strpos( $html, '>Browsers<' ) === false && strpos( $html, 'sn-an-heatmap' ) === false, 'content: technology/engagement panels NOT in this view (lazy per-tab render)' );
ok( strpos( $html, 'wp-list-table widefat' ) !== false, 'content: dimension/path tables use native widefat class' );
ok( strpos( $html, 'postbox' ) !== false, 'content: panels wrapped in native postbox' );
ok( strpos( $html, 'class="sn-an-panel"' ) === false, 'content: old bare sn-an-panel wrapper gone (migrated to postbox)' );

echo "\nGroup: dashboard. Technology view\n";
$_GET['sn_view'] = 'technology';
$html = capture( 'snt_analytics_render_dashboard' );
foreach ( array( 'Browsers', 'Operating systems', 'Devices', 'Protocols', 'TLS' ) as $p ) {
	ok( strpos( $html, $p ) !== false, "technology: '$p' panel present" );
}
ok( strpos( $html, 'Top pages' ) === false && strpos( $html, 'Cities' ) === false, 'technology: content/geography panels NOT in this view' );
ok( substr_count( $html, 'sn-an-spark' ) >= 1, 'technology: sparkline column rendered on OS/devices tables' );
ok( strpos( $html, 'wp-list-table widefat' ) !== false, 'technology: dimension tables use native widefat class' );

echo "\nGroup: dashboard. Geography view\n";
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

echo "\nGroup: dashboard. Engagement view\n";
$_GET['sn_view'] = 'engagement';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-heatmap' ) !== false, 'engagement: hour×dow heatmap rendered' );
ok( strpos( $html, 'Scroll depth' ) !== false && strpos( $html, 'Time on page' ) !== false, 'engagement: scroll + time distributions' );
ok( strpos( $html, 'postbox' ) !== false, 'engagement: panels wrapped in native postbox' );
ok( strpos( $html, 'Scroll depth: percentiles' ) !== false && strpos( $html, 'Time on page: percentiles' ) !== false, 'engagement: scroll + time percentile panels' );
ok( strpos( $html, 'sn-an-pctl-chip' ) !== false && strpos( $html, '63%' ) !== false, 'engagement: percentile chips render with values' );

echo "\nGroup: dashboard: drill-down\n";
$_GET['sn_view']  = 'geography';
$_GET['sn_drill'] = 'country:US';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Top pages · Country = US' ) !== false, 'drill: panel renders on the view that owns the dim' );
ok( strpos( $html, 'sn_drill=country%3A' ) !== false, 'drill: Countries table values are drill links (colon URL-encoded by add_query_arg)' );
ok( strpos( $html, 'sn_view=technology' ) !== false, 'drill: tab links present on the drilled view' );
// (BOTH view-local tab-drops — sn_drill AND sn_event_prop — are pinned in
// tests/analytics-param-carry.php, whose URL stubs have REAL query semantics.
// This suite's add_query_arg stub ignores $_GET, so carry assertions here pass
// vacuously; the old sn_drill pin was removed for exactly that reason (D4 rider).)
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

echo "\nGroup: dashboard. Quality view\n";
$_GET['sn_view'] = 'quality';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-botbreak' ) !== false && strpos( $html, 'Amazon.com, Inc.' ) !== false, 'quality: bot breakdown + top bot ASN rendered' );
ok( strpos( $html, 'sn-an-bot-trend' ) !== false, 'quality tab renders the bot-share trend when data present' );
ok( strpos( $html, 'Bot confidence' ) !== false, 'quality: bot-confidence distribution panel rendered' );
ok( strpos( $html, '1–30' ) !== false || strpos( $html, '61–99' ) !== false, 'quality: bot-confidence bands rendered' );
ok( strpos( $html, 'postbox' ) !== false, 'quality: panels wrapped in native postbox' );
// D5 §3: the shared header trend AND the bot-trend now both route through
// snt_an_trend_svg() and co-render on this same page (header above the tabs,
// bot-trend inside the Quality body) — their gradient ids MUST differ, or the
// second <path fill="url(#id)"> silently resolves to the FIRST matching id in
// the DOM (duplicate-SVG-id behavior), stealing the wrong gradient.
preg_match_all( '/id="(snSparkFill[A-Za-z0-9]*)"/', $html, $m_grad );
ok( count( $m_grad[1] ) >= 2 && count( $m_grad[1] ) === count( array_unique( $m_grad[1] ) ), 'quality: BOTH trend gradients on the page and no duplicate ids (>=2 guards against a fixture change making this vacuous)' );

echo "\nGroup: dashboard. Events view (new tab)\n";
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

echo "\nGroup: dashboard: view param whitelist + escaping\n";
$_GET['sn_view'] = '../../etc/passwd';
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'Right now' ) !== false, 'view: junk sn_view falls back to the overview default (v9.68.0)' );
$_GET['sn_view'] = 'content';
$GLOBALS['__aa']['paths'] = array( array( 'path' => '/x"<script>', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0.0, 'time_avg' => 0.0 ) );
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, '<script>' ) === false, 'dashboard: path output escaped (no raw <script>)' );
unset( $_GET['sn_view'] );

echo "\nGroup: dashboard: unconfigured shows empty + Configure link, NOT the form\n";
$GLOBALS['__aa_config'] = false;
$html = capture( 'snt_analytics_render_dashboard' );
ok( stripos( $html, 'not receiving data' ) !== false || stripos( $html, 'isn' ) !== false, 'dashboard(unconfigured): shows the empty/config notice' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) === false, 'dashboard(unconfigured): does NOT embed the settings form' );
ok( stripos( $html, 'Configure' ) !== false, 'dashboard(unconfigured): links to the settings page' );
// v9.40.0 D4: snt_analytics_render_empty() adopts the unified snt_an_gate() idiom
// (was a raw .notice div) and now folds the "Configure analytics →" CTA into the
// gate call itself, so the caller no longer renders a separate <p><a> line.
ok( strpos( $html, 'sn-an-gate' ) !== false, 'dashboard(unconfigured): unified gate marker present' );
ok( strpos( $html, '<span>Analytics</span>' ) !== false, 'dashboard(unconfigured): gate title is "Analytics"' );
ok( substr_count( $html, 'Configure analytics' ) === 1, 'dashboard(unconfigured): the CTA renders exactly once (folded into the gate, not duplicated by the caller)' );
ok( strpos( $html, 'href="https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=analytics"' ) !== false,
	'dashboard(unconfigured): CTA points at the analytics settings URL' );

echo "\nGroup: dashboard: unconfigured: tabs lead, ZERO accessor reads (S2 §5, resolves PR #275)\n";
// The dashboard's SHAPE (which views exist) is now visible before Cloudflare
// creds are set; only the DATA below the gate stays withheld. $_GET['sn_view']
// is unset here (previous group left it that way), so the tab strip's active
// tab is the 'content' default.
$GLOBALS['__aa_config'] = false;
aa_reset_call_counts();
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-view-tabs' ) !== false, 'unconfigured: the view-tabs strip renders' );
ok( strpos( $html, 'nav-tab-wrapper' ) !== false, 'unconfigured: WP-native nav-tab-wrapper present' );
$tab_count = 0;
foreach ( SN_ANALYTICS_VIEWS as $slug => $label ) {
	ok( strpos( $html, 'sn_view=' . $slug ) !== false, "unconfigured: tab link to '$slug' present" );
	++$tab_count;
}
ok( 13 === $tab_count, 'unconfigured: sanity: the registry has all 13 views incl. search (test isn\'t vacuous)' );
// v9.65.0 units-collision fix: the tab LABEL says what its number counts
// (within-day sessions from the live session engine), while the SLUG stays
// 'visits' — cache keys, ?sn_view= links, and the dispatch switch all key on
// the slug, so renaming it would break them. Label free, slug frozen.
ok( array_key_exists( 'visits', SN_ANALYTICS_VIEWS ), 'views registry: the \'visits\' SLUG is unchanged (drilldown links + dispatch key on it)' );
ok( 'Sessions' === SN_ANALYTICS_VIEWS['visits'], 'views registry: the visits tab is LABELED "Sessions" (units fix: the Overview headline\'s "Visits" counts visitor-days)' );
ok( strpos( $html, 'nav-tab" href="' ) !== false, 'unconfigured: tab links carry a real, working href (not a bare <a>)' );
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'unconfigured: exactly one active tab (the overview default)' );
ok( strpos( $html, 'sn-an-gate' ) !== false, 'unconfigured: the gate card still renders alongside the tabs' );
ok( strpos( $html, 'button button-primary' ) !== false, 'unconfigured: the gate CTA keeps its cta_primary weight' );
ok( strpos( $html, 'sn-toolbar' ) === false, 'unconfigured: NO .sn-toolbar (controls stay gated behind config)' );
ok( strpos( $html, 'sn-an-header-grid' ) === false, 'unconfigured: NO header-region markup' );
ok( strpos( $html, 'postbox sn-overview' ) === false, 'unconfigured: NO Overview panel' );
ok( strpos( $html, 'sn-an-headline' ) === false, 'unconfigured: NO insights band' );
ok( 0 === $GLOBALS['__aa_calls']['realtime'], 'unconfigured: sn_analytics_realtime() never called' );
ok( 0 === $GLOBALS['__aa_calls']['range_totals'], 'unconfigured: sn_analytics_range_totals() never called' );
ok( 0 === $GLOBALS['__aa_calls']['class_totals'], 'unconfigured: sn_analytics_class_totals() never called' );
ok( 0 === $GLOBALS['__aa_calls']['daily_series'], 'unconfigured: sn_analytics_daily_series() never called' );
ok( 0 === $GLOBALS['__aa_calls']['top_paths'], 'unconfigured: sn_analytics_top_paths() never called' );
// T5 review: 'all' is the ONE range token whose pre-gate resolution reads a local
// accessor (sn_analytics_min_day — rollup MIN, transient-cached, empty-table-safe).
// Pin that documented contract: exactly one min_day read, still zero AE accessors.
if ( ! function_exists( 'sn_analytics_min_day' ) ) {
	function sn_analytics_min_day() { ++$GLOBALS['__aa_calls_min_day']; return '2026-01-01'; }
}
$GLOBALS['__aa_calls_min_day'] = 0;
aa_reset_call_counts();
$_GET['sn_range'] = 'all';
$html_all = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_all, 'sn-an-view-tabs' ) !== false && strpos( $html_all, 'sn_range=all' ) !== false, 'unconfigured+all: tabs render and carry the all token' );
ok( 1 === $GLOBALS['__aa_calls_min_day'], 'unconfigured+all: exactly ONE min_day read (the documented pre-gate exception)' );
ok( 0 === array_sum( $GLOBALS['__aa_calls'] ), 'unconfigured+all: the five AE accessors still never called' );
unset( $_GET['sn_range'] );
$GLOBALS['__aa_config'] = true; // restore for the groups that follow.

echo "\nGroup: settings section: the creds form + dashboard backlink\n";
$GLOBALS['__aa_config'] = false;
$GLOBALS['__aa_opts']   = array();
$html = capture( 'snt_analytics_render_settings_section' );
ok( strpos( $html, 'name="sn_cf_account_id"' ) !== false, 'settings: account_id input present' );
ok( strpos( $html, 'name="sn_cf_analytics_token"' ) !== false, 'settings: token input present' );
ok( strpos( $html, 'value="analytics_save"' ) !== false, 'settings: analytics_save submit present' );
ok( strpos( $html, 'value="analytics_test"' ) !== false, 'settings: analytics_test submit present' );
ok( strpos( $html, 'wrangler' ) !== false && strpos( $html, 'SN_PX_TOKEN' ) !== false, 'settings: Worker-setup console present' );
ok( stripos( $html, 'View dashboard' ) !== false, 'settings: links back to the read-only dashboard' );
ok( strpos( $html, '<details class="sn-an-form-fold" open>' ) !== false, 'settings: unconfigured pipeline -> the credentials fold carries open (v9.45.0 wiring pin)' );
ok( strpos( $html, 'admin.php?page=sn-analytics' ) !== false, 'settings: dashboard link targets the S&N Analytics menu (v12.10.0; was the WP Dashboard submenu)' );
ok( false === strpos( $html, 'Dashboard &rarr; Analytics' ), 'settings: the help text no longer names a home the page left — copy that describes a move it did not make is how a stale claim outlives its code' );

echo "\nGroup: settings section: escaping of stored values\n";
$GLOBALS['__aa_opts'] = array( SN_CF_ACCOUNT_ID_OPT => 'acct"<script>' );
$html = capture( 'snt_analytics_render_settings_section' );
ok( strpos( $html, '<script>' ) === false, 'settings: stored account_id with <script> is escaped' );
$GLOBALS['__aa_opts'] = array();

echo "\nGroup: settings section: open-and-wide 2-column layout (Phase 2, v6.44.0)\n";
$GLOBALS['__aa_config'] = false;
$GLOBALS['__aa_opts']   = array();
$html = capture( 'snt_analytics_render_settings_section' );
ok( strpos( $html, '<div class="sn-2up">' ) !== false, 'settings: lays out as a .sn-2up two-column grid' );
// Class-token-boundary count (survives modifier classes on any card): the two
// bare column wrappers + the pipeline strip's combined "sn-fieldset sn-an-pipeline".
// sn-fieldset-h headings don't match (the lookahead rejects the trailing hyphen).
$fieldset_cards = preg_match_all( '~class="[^"]*(?<![\w-])sn-fieldset(?![\w-])~', $html );
ok( 3 === $fieldset_cards, 'settings: exactly three .sn-fieldset surfaces: pipeline strip + the two columns (wide leaf owns its own chrome)' );
ok( strpos( $html, 'class="sn-fieldset sn-an-pipeline"' ) !== false, 'settings: the strip is the modifier-carrying fieldset (the columns stay bare)' );
// S2 §6: the whole settings section is wrapped in the D4-leaf marker so the
// leaf-scoped token-card CSS (analytics-admin.css) has something to hang off.
ok( strpos( $html, 'class="sn-an-settings-leaf"' ) !== false, 'settings: wrapped in the sn-an-settings-leaf marker (S2 §6)' );
ok( strpos( $html, 'sn-an-settings-leaf' ) < strpos( $html, 'sn-an-pipeline' ), 'settings: the leaf wrapper opens before the pipeline strip' );
$acct_at = strpos( $html, 'name="sn_cf_account_id"' );
// 'Cloudflare Worker setup' (the <summary> text), NOT 'wrangler': the pipeline
// strip's warn note can also say "wrangler" once the worker stub lands, and the
// strip renders ABOVE the columns — a 'wrangler' marker would false-fail order.
$wrng_at = strpos( $html, 'Cloudflare Worker setup' );
ok( false !== $acct_at && false !== $wrng_at && $acct_at < $wrng_at, 'settings: credentials (left card) precede the edge-worker reference (right card)' );
$reg = file_get_contents( __DIR__ . '/../inc/admin-tabs-data.php' );
ok( (bool) preg_match( "/'analytics'\\s*=>\\s*array\\([^\\n]*'wide'\\s*=>\\s*true/", $reg ), 'registry: the analytics leaf is marked wide (opts out of the wrapper cap)' );

echo "\nGroup: settings hub composition (v9.36.0, layout A): status strip + operate|reference columns\n";
// The real partials render here (analytics-render-settings.php is loaded via
// analytics-admin-render.php), so order is asserted on each subsection's own
// distinctive marker. sn_worker_version_render_card (inc/worker-version.php) is
// NOT loaded in this harness, so its guarded call is skipped — the reference
// column's first marker is the mirrors card.
$pipe    = strpos( $html, 'sn-an-pipeline' );
$twoup   = strpos( $html, '<div class="sn-2up">' );
$creds   = strpos( $html, 'name="sn_cf_account_id"' );
$excl    = strpos( $html, 'sn-an-exclude' );
$tune    = strpos( $html, 'sn-an-tuning' );
$funnels = strpos( $html, 'sn-an-funnels' ); // S2 §3 (v9.42.0 arc): funnels card, after engine tuning
$mirrors = strpos( $html, 'sn-an-mirrors' );
$filters = strpos( $html, 'sn-an-filters' );
$setup   = strpos( $html, 'Cloudflare Worker setup' ); // collision-free: the strip's warn note can also contain 'wrangler'
ok( false !== $pipe && false !== $twoup && $pipe < $twoup, 'hub: pipeline status strip renders ABOVE the .sn-2up columns' );
ok( false !== $creds && false !== $excl && false !== $tune && false !== $funnels && $creds < $excl && $excl < $tune && $tune < $funnels,
	'hub(left): credentials → exclusion → engine tuning → funnels (writable column order)' );
ok( strpos( $html, 'name="sn_funnels"' ) !== false, 'hub(left): the funnels card\'s textarea is present' );
ok( false !== $mirrors && false !== $filters && false !== $setup && $mirrors < $filters && $filters < $setup,
	'hub(right): mirrors → filter reference → worker setup (reference column order)' );
ok( $funnels < $mirrors, 'hub: the writable column (funnels last) precedes the reference column\'s first marker' );

echo "\nGroup: the v5.3.0 Dashboard-tab hook is reverted (no auto-render on the plugin Dashboard tab)\n";
ok( strpos( file_get_contents( __DIR__ . '/../inc/analytics-admin.php' ), "add_action( 'sn_admin_dashboard_extras', 'snt_analytics_render" ) === false, 'revert: analytics no longer hooks sn_admin_dashboard_extras' );

echo "\nGroup: dashboard: date-range presets + custom window\n";
aa_fill_data();
$_GET['sn_view'] = 'content';
$_GET['sn_range'] = 'ytd';
$h = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $h, 'sn-an-range' ) !== false, 'date-range: the ONE range control present' );
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

// v9.5.0 (annotations R2): the Intelligence tab / weekly-digest surface was retired.
// The view is gone from the registry, resolves to the default, and no longer owns chrome.
ok( ! array_key_exists( 'intelligence', SN_ANALYTICS_VIEWS ), 'R2: intelligence view removed from the registry' );
ok( snt_analytics_resolve_view( 'intelligence' ) === 'overview', 'R2: retired intelligence resolves to the default (overview since v9.68.0)' );
ok( snt_analytics_view_owns_chrome( 'intelligence' ) === false, 'R2: retired intelligence no longer owns chrome' );

echo "\nGroup: login-defense dashboard dispatch + chrome suppression\n";
// Stub the login renderer so this isolates the DASHBOARD's routing + chrome
// suppression (the code that changed) from the login renderer (covered by
// tests/login-defense-analytics.php) and avoids loading its AE query layer.
if ( ! function_exists( 'sn_login_defense_render_header' ) ) {
	function sn_login_defense_render_header() { echo '<div class="sn-lg-header">LOGIN-DEFENSE-HEADER</div>'; }
}
if ( ! function_exists( 'sn_login_defense_render_body' ) ) {
	function sn_login_defense_render_body() { echo '<div class="sn-lg-body">LOGIN-DEFENSE-BODY</div>'; }
}
aa_fill_data();
$_GET['sn_view'] = 'login-defense';
$html = capture( 'snt_analytics_render_dashboard' );
$pos_tabs   = strpos( $html, 'nav-tab-wrapper' );
$pos_header = strpos( $html, 'LOGIN-DEFENSE-HEADER' );
$pos_body   = strpos( $html, 'LOGIN-DEFENSE-BODY' );
ok( $pos_tabs !== false && $pos_header !== false && $pos_tabs < $pos_header,
	'frame: tabs lead (v9.37.0 D1): login header renders BELOW the tab bar (same slot as the pageview chrome)' );
ok( $pos_body !== false && $pos_tabs !== false && $pos_body > $pos_tabs,
	'frame: login body renders BELOW the tab bar' );
ok( false === strpos( $html, 'sn-an-headline' ), 'chrome: no headline band on the chrome-owning login view' );
ok( strpos( $html, 'sn-an-sep-meta' ) === false,
	'chrome: separation meta SUPPRESSED on the login view (its own toolbar carries no class totals)' );
ok( strpos( $html, 'sn_view=login-defense' ) !== false, 'tabs: login-defense tab present in nav' );
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'tabs: exactly one active tab (login-defense)' );
// Sanity: content view still shows the shared Overview chrome.
$_GET['sn_view'] = 'content';
ok( strpos( capture( 'snt_analytics_render_dashboard' ), 'sn-overview' ) !== false, 'chrome: content view still renders the shared Overview' );
// render_error stays always-on even for a chrome-owning view (note: $err must be an array to fire).
$_GET['sn_view']       = 'login-defense';
$GLOBALS['__aa_error'] = array( 'code' => 500, 'message' => 'boom' );
ok( strpos( capture( 'snt_analytics_render_dashboard' ), 'Analytics read failed.' ) !== false, 'render_error: AE diagnostic still fires on the login view' );
$GLOBALS['__aa_error'] = null;
$_GET['sn_view']       = 'content';

echo "\nGroup: dashboard: unconfigured + sn_view=login-defense: the SAME generic gate (S2 §5 decision)\n";
// DECISION: login-defense gates on the exact same sn_analytics_config() flag as
// every other view (verified by reading inc/login-defense-analytics.php — it is
// NOT a separate credential/"Connect" card). So the unconfigured branch renders
// ONE generic "Analytics" gate for every tab rather than dispatching into
// login-defense's own header/body renderers, which would just show an
// identical message under a different label and cost two extra function
// calls for no visible difference. sn_login_defense_render_header()/_body()
// are stubbed above (LOGIN-DEFENSE-HEADER/BODY markers) — their absence here
// proves the unconfigured path never reaches the view switch.
$_GET['sn_view'] = 'login-defense';
$GLOBALS['__aa_config'] = false;
aa_reset_call_counts();
$html = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html, 'sn-an-view-tabs' ) !== false, 'unconfigured(login-defense): tab strip still renders' );
ok( strpos( $html, 'sn_view=login-defense' ) !== false, "unconfigured(login-defense): its own tab link present" );
ok( substr_count( $html, 'nav-tab-active' ) === 1, 'unconfigured(login-defense): exactly one active tab (login-defense)' );
ok( strpos( $html, '<span>Analytics</span>' ) !== false, 'unconfigured(login-defense): shows the GENERIC "Analytics" gate title, not a login-defense-specific one' );
ok( strpos( $html, 'sn-an-gate' ) !== false, 'unconfigured(login-defense): the shared gate marker is present' );
ok( strpos( $html, 'LOGIN-DEFENSE-HEADER' ) === false && strpos( $html, 'LOGIN-DEFENSE-BODY' ) === false,
	"unconfigured(login-defense): does NOT dispatch into login-defense's own header/body renderer" );
ok( 0 === $GLOBALS['__aa_calls']['realtime'] && 0 === $GLOBALS['__aa_calls']['range_totals']
	&& 0 === $GLOBALS['__aa_calls']['class_totals'] && 0 === $GLOBALS['__aa_calls']['daily_series']
	&& 0 === $GLOBALS['__aa_calls']['top_paths'],
	'unconfigured(login-defense): ZERO accessor reads' );
$GLOBALS['__aa_config'] = true;
$_GET['sn_view']        = 'content';

echo "\nGroup: tab-URL hygiene\n";
$prev_uri = $_SERVER['REQUEST_URI'];
$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=sn-analytics&sn_lg_range=30';
$_GET['sn_view'] = 'content';
// Isolate just the view-tab <nav> element: the shared controls (range/class pills)
// also preserve URL params and render before it, so "everything before </nav>" is
// too broad. Only the tab links matter here.
$full   = capture( 'snt_analytics_render_dashboard' );
$navpos = strpos( $full, 'sn-an-view-tabs' );
$navend = strpos( $full, '</nav>', $navpos );
$nav    = substr( $full, $navpos, $navend - $navpos );
ok( strpos( $nav, 'sn_lg_range' ) === false, 'tabs: sn_lg_range stripped from tab links (login range does not leak onto sibling tabs)' );
$_SERVER['REQUEST_URI'] = $prev_uri;

echo "\nGroup: v9.34.0: first-class comparison (overlay + note)\n";
aa_fill_data();
$_GET['sn_view']    = 'content';
$_GET['sn_compare'] = 'prev';
$html_cmp = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_cmp, 'sn-an-compare-note' ) !== false && strpos( $html_cmp, 'previous period' ) !== false, 'compare: the note names the comparison window' );
ok( strpos( $html_cmp, 'stroke="#a7aaad"' ) !== false && strpos( $html_cmp, 'stroke-dasharray' ) !== false, 'compare: the trend overlays a muted dashed comparison path' );
unset( $_GET['sn_compare'] );
$html_off = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_off, 'sn-an-compare-note' ) === false && strpos( $html_off, 'stroke-dasharray' ) === false, 'compare off (default): no note, no overlay' );

// D2 (v9.38.0): the header region resolves ONE basis from sn_compare and threads
// it to every surface — the KPI badge tooltip AND the Movers meta must flip
// together on yoy, and 'off' keeps the quiet prev basis (no note/overlay, but
// the badge tooltip still reads "previous period").
$_GET['sn_compare'] = 'yoy';
$html_yoy = capture( 'snt_analytics_render_dashboard' );
ok( false !== strpos( $html_yoy, 'same period last year:' ), 'D2 frame: yoy flips the KPI badge tooltip basis' );
// Armor: the compare-NOTE also contains the substring "vs same period last
// year" (its own label), so a bare strpos() would pass even if the Movers
// tile never received the mode. Anchor to the Movers panel's OWN head-meta
// span (sn-an-head-meta, uniquely emitted by the movers tile with this text)
// so this can only pass when the mode actually reached snt_analytics_render_movers_tile().
ok( false !== strpos( $html_yoy, 'sn-an-head-meta">vs same period last year<' ), 'D2 frame: yoy flips the movers meta' );
unset( $_GET['sn_compare'] );
$html_off = capture( 'snt_analytics_render_dashboard' );
ok( false !== strpos( $html_off, 'previous period:' ), 'D2 frame: off keeps the quiet previous-period tooltip' );
ok( false === strpos( $html_off, 'sn-an-compare-note' ), 'D2 frame: off still renders no note (v9.34.0 invariant)' );

echo "\nGroup: v9.34.0: brush data attributes on the trend\n";
$_GET['sn_view'] = 'content';
$html_br = capture( 'snt_analytics_render_dashboard' );
$brush_at = strpos( $html_br, 'data-brush-from="' );
ok( false !== $brush_at && strpos( $html_br, 'data-brush-days="' ) !== false, 'brush: the day-granularity trend carries the brush data attributes' );
ok( preg_match( '/data-brush-from="\d{4}-\d{2}-\d{2}"/', $html_br ) === 1, 'brush: data-brush-from is a real date (the window start the JS maps fractions onto)' );

echo "\nGroup: v9.35.0: tier badges (I6)\n";
$_GET['sn_view'] = 'content';
$html_tb = capture( 'snt_analytics_render_dashboard' );
ok( strpos( $html_tb, 'sn-an-tier--descriptive' ) !== false, 'dashboard: the Overview postbox names its tier via the shared badge' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
