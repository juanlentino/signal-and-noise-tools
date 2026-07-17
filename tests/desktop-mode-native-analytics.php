<?php
/**
 * Standalone fixture tests for inc/desktop-mode-native-analytics.php — the
 * `sn-analytics-hud` native desktop-mode window.
 *
 * Contract read from the desktop-mode v0.9.5 TAG (includes/registries/
 * native-windows.php:153-233), NOT from docs/native-windows-proposal.md,
 * which is a historical RFC whose argument surface is wrong.
 *
 * Run: php tests/desktop-mode-native-analytics.php
 *
 * @since plugin v9.56.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', '9.56.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; }
	else { $fail++; echo "  FAIL— $label\n"; }
}

// ── WP stubs ─────────────────────────────────────────────────────────
// This add_action stub HONOURS priority. The sibling suite's stub
// (tests/desktop-mode-integration.php:47) drops $p on the floor and replays in
// registration order, which makes any ordering assertion vacuous. The
// self-test below is what proves this one bites.
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][ $p ][] = $cb; }

// inc/desktop-mode-integration.php registers filters at file-load time (the
// dock-placement/dock-items/plugins-icon/living-tree/ai-tools hooks) — a
// dependency of REQUIRING that file for its tab-slug truth, not of anything
// this suite exercises. Stubbed as inert no-ops: this suite never fires a
// filter, only the 'init' action via fire().
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {}
function apply_filters( $hook, $value ) { return $value; }

/** Fire every callback on a hook, priority ascending, registration order within a priority. */
function fire( $hook ) {
	$by_priority = $GLOBALS['__actions'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) { $cb(); }
	}
}

$GLOBALS['__dm_windows'] = array();
function desktop_mode_register_window( $id, $args = array() ) {
	$GLOBALS['__dm_windows'][ $id ] = $args;
	return true;
}

$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; }

$GLOBALS['__scripts'] = array();
function wp_register_script( $h, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $h ] = $src; }

function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); }
function wp_create_nonce( $action = -1 ) { return 'nonce-' . $action; }
function current_user_can( $cap ) { return true; }
function current_time( $type, $gmt = 0 ) { return '2026-07-16 12:00:00'; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_url( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function _e( $t, $d = null ) { echo $t; }

$GLOBALS['__t'] = array();
function get_transient( $k ) { return $GLOBALS['__t'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }

// ── ANALYTICS ACCESSOR STUBS ─────────────────────────────────────────
// These shapes MIRROR THE REAL ACCESSORS — each was copied from the source
// below, not invented. A stub that returns a shape the real callee never
// produces makes every green assertion prove nothing; that is exactly how this
// HUD once emitted `avg_scroll`/`avg_time` (keys that exist NOWHERE in the
// codebase) and rendered a fabricated 0% forever. Copy from reality:
//
//   sn_analytics_realtime      inc/analytics-realtime.php:137
//                              → int|null  (null = transient never warmed;
//                                 a warmed class with no hits is a REAL 0)
//   sn_analytics_range_totals  inc/analytics-read.php:82
//                              → {views:int, visits:int, scroll_avg:float, time_avg:float}
//   sn_analytics_top_paths     inc/analytics-read.php:26
//                              → rows of {path, views, visits, scroll_avg, time_avg}
//   sn_analytics_top_sources   inc/analytics-sources.php:211
//                              → rows of {value, views, visits, hosts[]}  — `value`, NOT `source`
//   sn_analytics_period_deltas inc/analytics-derived.php:275
//                              → per metric (views/visits/scroll_avg/time_avg):
//                                {current, previous, pct, dir}  — nested, not flat
//   sn_analytics_engaged_rate  inc/analytics-derived.php:220
//                              → int|null  (null = no timed pageviews to divide by)

// __rt increments per call so the cache tests can prove realtime is recomputed
// every request. __rt_null flips it to its documented null return (PHP cannot
// redeclare a function, so the null path rides a global switch).
$GLOBALS['__rt']      = 0;
$GLOBALS['__rt_null'] = false;
function sn_analytics_realtime( $class = 'human' ) {
	$GLOBALS['__rt']++;
	return $GLOBALS['__rt_null'] ? null : $GLOBALS['__rt'];
}

// __totals_calls counts invocations: the KPI stubs are deterministic, so
// comparing two payloads' seven_day would pass whether or not the cache works.
// Counting the calls is what actually pins the get_transient() short-circuit.
$GLOBALS['__totals_calls'] = 0;
function sn_analytics_range_totals( $from, $to, $class = 'human', $refresh = false ) {
	$GLOBALS['__totals_calls']++;
	return array( 'views' => 1200, 'visits' => 800, 'scroll_avg' => 61.5, 'time_avg' => 74.2 );
}

function sn_analytics_period_deltas( $from, $to, $class = 'human', $cwin = null ) {
	return array(
		'views'      => array( 'current' => 1200, 'previous' => 1067, 'pct' => 12, 'dir' => 'up' ),
		'visits'     => array( 'current' => 800, 'previous' => 826, 'pct' => -3, 'dir' => 'down' ),
		'scroll_avg' => array( 'current' => 61.5, 'previous' => 60.0, 'pct' => 3, 'dir' => 'up' ),
		'time_avg'   => array( 'current' => 74.2, 'previous' => 74.2, 'pct' => 0, 'dir' => 'flat' ),
	);
}

$GLOBALS['__engaged_null'] = false;
function sn_analytics_engaged_rate( $from, $to, $class = 'human' ) {
	return $GLOBALS['__engaged_null'] ? null : 42;
}

function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
	return array_slice( array(
		array( 'path' => '/a', 'views' => 90, 'visits' => 70, 'scroll_avg' => 66.0, 'time_avg' => 81.0 ),
		array( 'path' => '/b', 'views' => 80, 'visits' => 62, 'scroll_avg' => 64.0, 'time_avg' => 79.0 ),
		array( 'path' => '/c', 'views' => 70, 'visits' => 55, 'scroll_avg' => 62.0, 'time_avg' => 77.0 ),
		array( 'path' => '/d', 'views' => 60, 'visits' => 48, 'scroll_avg' => 60.0, 'time_avg' => 75.0 ),
		array( 'path' => '/e', 'views' => 50, 'visits' => 40, 'scroll_avg' => 58.0, 'time_avg' => 73.0 ),
		array( 'path' => '/f', 'views' => 40, 'visits' => 32, 'scroll_avg' => 56.0, 'time_avg' => 71.0 ),
	), 0, $limit );
}

function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	return array_slice( array(
		// '(direct)' aggregates but is never drillable → always an empty hosts[].
		array( 'value' => '(direct)', 'views' => 40, 'visits' => 33, 'hosts' => array() ),
		array( 'value' => 'Hacker News', 'views' => 30, 'visits' => 25, 'hosts' => array( 'news.ycombinator.com' ) ),
		array( 'value' => 'RSS', 'views' => 20, 'visits' => 16, 'hosts' => array( 'rss.example.test' ) ),
		array( 'value' => 'X', 'views' => 10, 'visits' => 8, 'hosts' => array( 't.co', 'x.com' ) ),
		array( 'value' => 'Google', 'views' => 5, 'visits' => 4, 'hosts' => array( 'google.com' ) ),
		array( 'value' => 'Bing', 'views' => 2, 'visits' => 2, 'hosts' => array( 'bing.com' ) ),
	), 0, $limit );
}

class WP_REST_Response {
	public $data;
	public function __construct( $data, $status = 200 ) { $this->data = $data; }
	public function get_data() { return $this->data; }
}

// REQUIRE these, don't stub them — they own the only truth about which admin
// page slugs actually exist. A stubbed tab list is precisely how the v9.55.0
// dead-links rot survived CI.
require_once __DIR__ . '/../inc/admin-tabs-data.php';
require_once __DIR__ . '/../inc/admin-legacy-redirect.php';
require_once __DIR__ . '/../inc/desktop-mode-integration.php';

require_once __DIR__ . '/../inc/desktop-mode-native-analytics.php';

echo "\n── HARNESS SELF-TEST (proves the init:6 assertion below is not vacuous) ──\n";
$order = array();
add_action( 'sn_selftest', function () use ( &$order ) { $order[] = 'late'; }, 99 );
add_action( 'sn_selftest', function () use ( &$order ) { $order[] = 'early'; }, 5 );
fire( 'sn_selftest' );
ok( $order === array( 'early', 'late' ),
	'harness: add_action honours priority (5 fires before 99 despite reverse registration)' );

echo "\n── REGISTRATION ──\n";
fire( 'init' );
$w = $GLOBALS['__dm_windows']['sn-analytics-hud'] ?? null;
ok( is_array( $w ), 'sn-analytics-hud is registered on init' );

// The v0.9.5 contract: native-windows.php validates these and returns WP_Error otherwise.
ok( ! empty( $w['title'] ), 'v0.9.5 contract: title is non-empty (else desktop_mode_missing_title)' );
ok( isset( $w['template'] ) && is_callable( $w['template'] ),
	'v0.9.5 contract: template is callable (else desktop_mode_invalid_template)' );
ok( isset( $w['capabilities'] ) && is_array( $w['capabilities'] ) && in_array( 'manage_options', $w['capabilities'], true ),
	'v0.9.5 contract: capabilities is an ARRAY containing manage_options (not the RFC singular `capability`)' );
ok( ( $w['placement'] ?? '' ) === 'dock', 'placement is dock (additive; iframe icon untouched)' );

// Guard against the historical-RFC surface leaking in.
foreach ( array( 'custom_element', 'render', 'module', 'scripts', 'styles', 'capability', 'show_in_dock' ) as $ghost ) {
	ok( ! array_key_exists( $ghost, $w ), "does not use the historical-RFC arg `$ghost`" );
}

echo "\n── The gate: no desktop-mode, no registration ──\n";
// Re-running the hook with the registry fn absent must be a no-op. We can't
// un-define a function, so assert the guard is present in the source instead.
// Every assertion above defines the stub, so without this the guard could be
// deleted and the suite would stay green — while every site WITHOUT
// desktop-mode fataled on each request. This module is optional, additive.
$src = file_get_contents( __DIR__ . '/../inc/desktop-mode-native-analytics.php' );
ok( strpos( $src, "function_exists( 'desktop_mode_register_window' )" ) !== false, 'window block is function_exists-gated' );

echo "\n── TEMPLATE IS A SKELETON (data would freeze at shell render) ──\n";
ob_start();
call_user_func( $w['template'] );
$tpl = (string) ob_get_clean();

ok( strpos( $tpl, 'data-sn-hud="realtime"' ) !== false, 'template declares the realtime mount point' );
ok( strpos( $tpl, 'data-sn-hud="kpis"' ) !== false, 'template declares the kpis mount point' );
ok( strpos( $tpl, 'data-sn-hud="top-content"' ) !== false, 'template declares the top-content mount point' );
ok( strpos( $tpl, 'data-sn-hud="top-sources"' ) !== false, 'template declares the top-sources mount point' );
ok( strpos( $tpl, 'data-sn-hud="root"' ) !== false, 'template declares the root mount point (the JS renders error states into it)' );
ok( strpos( $tpl, 'data-sn-hud="full-link"' ) !== false, 'template declares the full-link mount point (the JS sets its href from config.fullUrl)' );

// THE property: desktop-mode prints this template into the DOM ONCE at shell
// render and clones it per open. Any number echoed here is frozen at page load
// — realtime would be a lie with a fresh-looking label. So: no digits at all.
ok( preg_match( '/\d/', strip_tags( $tpl ) ) !== 1,
	'template body contains NO digits — it is a skeleton, not a snapshot' );

echo "\n── DESTINATION (the v9.55.0 lesson: 'it loads' is not the property) ──\n";
$cfg = $w['config'] ?? array();

// Assert the EXACT destination. Asserting merely "page= is a registered slug"
// would pass if this link dumped the user on the SN Dashboard — which is
// exactly the wrong test that went green in v9.55.0.
ok( ( $cfg['fullUrl'] ?? '' ) === 'https://example.test/wp-admin/index.php?page=sn-analytics',
	'config.fullUrl is EXACTLY index.php?page=sn-analytics (add_dashboard_page home)' );

// Mutation check: the resolver's generic path must NOT produce this URL, or the
// assertion above would pass for the wrong reason.
ok( snt_desktop_admin_url( 'sn-identity' ) !== ( $cfg['fullUrl'] ?? '' ),
	'mutation: a non-analytics slug resolves somewhere else (fullUrl is not a constant)' );
ok( strpos( (string) ( $cfg['fullUrl'] ?? '' ), 'tab=dashboard' ) === false,
	'fullUrl did NOT fall through to the tab=dashboard default' );

ok( ( $cfg['endpoint'] ?? '' ) === 'https://example.test/wp-json/signal-noise/v1/desktop/analytics-hud',
	'config.endpoint points at the HUD route' );
ok( ! empty( $cfg['nonce'] ), 'config.nonce is present' );

echo "\n── REST ROUTE ──\n";
fire( 'rest_api_init' );
$route = $GLOBALS['__routes']['signal-noise/v1/desktop/analytics-hud'] ?? null;
ok( is_array( $route ), 'GET signal-noise/v1/desktop/analytics-hud is registered' );
ok( ( $route['methods'] ?? '' ) === 'GET', 'route is GET' );
ok( isset( $route['permission_callback'] ) && is_callable( $route['permission_callback'] ),
	'route has a callable permission_callback (never __return_true)' );
ok( isset( $route['callback'] ) && $route['callback'] === 'snt_desktop_analytics_hud_payload',
	'route callback is snt_desktop_analytics_hud_payload' );

echo "\n── PAYLOAD SHAPE (the keys the JS actually reads) ──\n";
$payload = snt_desktop_analytics_hud_payload();
$body    = is_object( $payload ) && method_exists( $payload, 'get_data' ) ? $payload->get_data() : $payload;
foreach ( array( 'realtime', 'seven_day', 'top_content', 'top_sources' ) as $key ) {
	ok( array_key_exists( $key, $body ), "payload exposes `$key`" );
}
// The link URL rides `config`, not the payload — the window already has it at
// mount time, so shipping it again per-poll would be dead weight.
ok( ! array_key_exists( 'full_url', $body ),
	'payload does NOT duplicate fullUrl (config already carries it)' );
ok( is_array( $body['seven_day'] ), 'seven_day is an array of KPIs' );
ok( count( $body['top_content'] ) <= 5, 'top_content is capped at 5' );
ok( count( $body['top_sources'] ) <= 5, 'top_sources is capped at 5' );

echo "\n── seven_day's INNER KEYS (asserting only is_array() is what let the bug ship) ──\n";
foreach ( array( 'views', 'visits', 'scroll_avg', 'time_avg', 'engaged_rate', 'deltas' ) as $k ) {
	ok( array_key_exists( $k, $body['seven_day'] ), "seven_day exposes `$k`" );
}
// The regression that shipped green: the accessor emits scroll_avg (14 usages
// across inc/) and time_avg (13). Reading avg_scroll let `?? 0` swallow the
// miss and render a fabricated 0% — indistinguishable from real "no engagement".
ok( ! array_key_exists( 'avg_scroll', $body['seven_day'] ),
	'seven_day does NOT use avg_scroll — the real accessor emits scroll_avg (14 usages) and this HUD once got it wrong' );
ok( ! array_key_exists( 'avg_time', $body['seven_day'] ),
	'seven_day does NOT use avg_time — the real accessor emits time_avg (13 usages)' );
// Pin the VALUES too: a key that exists but reads 0 IS the failure mode above.
// The stub returns non-zero, so a swallowed miss cannot hide behind `?? 0`.
ok( 61.5 === $body['seven_day']['scroll_avg'], 'scroll_avg carries the accessor value (61.5), not a swallowed 0' );
ok( 74.2 === $body['seven_day']['time_avg'], 'time_avg carries the accessor value (74.2), not a swallowed 0' );

echo "\n── NULL IS NOT ZERO (never-measured ≠ measured-zero) ──\n";
// Both accessors document int|null. Casting null to 0 fabricates a confident
// number, and once cast the distinction is unrecoverable downstream.
$GLOBALS['__rt_null'] = true;
$null_rt = snt_desktop_analytics_hud_payload()->get_data();
ok( null === $null_rt['realtime'],
	'realtime preserves null when the realtime transient was never warmed (not cast to 0)' );
$GLOBALS['__rt_null'] = false;

// engaged_rate lives INSIDE the cached block, so the cache must be cleared or
// the recompute is not observable at all.
$GLOBALS['__t']            = array();
$GLOBALS['__engaged_null'] = true;
$null_eng = snt_desktop_analytics_hud_payload()->get_data();
ok( null === $null_eng['seven_day']['engaged_rate'],
	'engaged_rate preserves null when there are no timed pageviews (not cast to 0)' );
$GLOBALS['__engaged_null'] = false;

echo "\n── CACHE BOUNDARY ──\n";
// Start clean: earlier assertions already warmed the cache and moved the counters.
$GLOBALS['__t']            = array();
$GLOBALS['__totals_calls'] = 0;
$GLOBALS['__rt']           = 0;

$a = snt_desktop_analytics_hud_payload()->get_data(); // warms the 7d cache
$b = snt_desktop_analytics_hud_payload()->get_data(); // must hit the 7d cache

// THE assertion that pins the short-circuit. The two below it compare payload
// values, and the KPI stubs are deterministic — so a regression that kept
// set_transient() but dropped the get_transient() short-circuit (recomputing
// every request, defeating the cache entirely) would leave them both green.
// Only the call count can tell "cached" from "recomputed to the same answer".
ok( 1 === $GLOBALS['__totals_calls'],
	'seven_day is cached: sn_analytics_range_totals() ran EXACTLY once across two payload calls (got ' . $GLOBALS['__totals_calls'] . ')' );
ok( 2 === $GLOBALS['__rt'],
	'realtime is NOT cached: sn_analytics_realtime() ran on BOTH payload calls (got ' . $GLOBALS['__rt'] . ')' );

ok( $a['realtime'] !== $b['realtime'], 'realtime is recomputed every request (never day-cached)' );
ok( $a['seven_day'] === $b['seven_day'], 'seven_day is served from the day-stamped cache' );
ok( array_key_exists( 'sn_desktop_analytics_hud_2026-07-16', $GLOBALS['__t'] ),
	'cache key is stamped with the LOCAL day (not a flat key)' );

echo "\n── JS CONTRACT (v0.9.5: window.desktopModeNativeWindows[id] = ( body ) => teardown) ──\n";
$js_path = __DIR__ . '/../assets/desktop-mode-window-analytics.js';
ok( file_exists( $js_path ), 'assets/desktop-mode-window-analytics.js exists' );
$js = file_exists( $js_path ) ? (string) file_get_contents( $js_path ) : '';

ok( strpos( $js, 'desktopModeNativeWindows' ) !== false,
	'uses the NATIVE-WINDOW global desktopModeNativeWindows (not desktopModeWidgets)' );

// GUARD-RAILS, not contract proof: both absence assertions below pass trivially
// against an empty file, so they cannot demonstrate the file does the right
// thing — only that it hasn't started doing a specific wrong thing. The
// positive file_exists + regex assertions carry the real weight. Kept because
// they earned their place: the first one caught a doc-comment that merely NAMED
// the widget global in prose.
ok( strpos( $js, 'desktopModeWidgets' ) === false,
	'does NOT use the widget global (wrong path for a native window)' );
ok( strpos( $js, 'wp.desktop.registerWindow' ) === false,
	'does NOT use the JS-runtime path (the window is PHP-declared)' );
ok( preg_match( '/desktopModeNativeWindows\[\s*[\'"]sn-analytics-hud[\'"]\s*\]\s*=/', $js ) === 1,
	'assigns the callback at the registered id' );
ok( preg_match( '/=\s*(async\s+)?function\s*\(\s*body\s*\)|=\s*(async\s+)?\(\s*body\s*\)\s*=>/', $js ) === 1,
	'callback takes a single ( body ) arg (NOT the widgets\' (container, ctx))' );
ok( strpos( $js, 'desktopModeWindowConfig' ) !== false,
	'reads config from window.desktopModeWindowConfig[id]' );
ok( strpos( $js, '30000' ) !== false, 'polls on a 30s interval' );
ok( strpos( $js, 'clearInterval' ) !== false,
	'returns a teardown that clears the interval (poll is open-window-only)' );

// The accessor-shape guard. The HUD shipped `avg_scroll`/`avg_time` and
// `source` once — invented keys the real accessors never emit — and 41 green
// assertions sailed straight past it because the STUBS invented the same
// shapes. Pin the real keys on the JS side too.
ok( preg_match( "/data\.top_sources,\s*'value'/", $js ) === 1,
	"top-sources rows read the REAL key `value` (inc/analytics-sources.php), not the invented `source`" );
ok( preg_match( "/data\.top_content,\s*'path'/", $js ) === 1,
	"top-content rows read the REAL key `path` (inc/analytics-read.php)" );
ok( strpos( $js, "'—'" ) !== false,
	'null renders as an em dash — never as 0 (never-measured is not zero)' );

// ── A RESOLVED FAILURE MUST NOT POSE AS AN ONGOING ONE ──
//
// fail() paints a persistent [data-sn-hud="error"] row. A render() that never
// retires it pins "Analytics unavailable: HTTP 500" to the window forever after
// ONE transient blip — beside numbers that are updating correctly. On a HUD
// designed to be left open (~120 polls/hour) that blip is a matter of when, not
// if. Same class of lying UI as the fabricated 0%, just inverted.
//
// WHAT THIS ASSERTION PROVES — AND DOES NOT. It is a REGEX OVER SOURCE. This
// repo has no JS runtime harness (no node test runner, no jsdom), so nothing
// here executes the DOM. It proves only that render()'s FIRST statement is a
// call to the clearing helper — i.e. that the call has not been deleted or
// reordered below the data writes. It does NOT prove the node is actually
// removed on a recovery poll; clearFailure()'s own body could be gutted and
// this would stay green. The behaviour itself rides the owner-observed check at
// release. Recorded as a limitation rather than papered over.
ok( preg_match( '/function clearFailure\(\)\s*\{/', $js ) === 1,
	'a failure-clearing helper exists' );
ok( preg_match( '/function render\(\s*data\s*\)\s*\{\s*clearFailure\(\);/', $js ) === 1,
	'render() calls clearFailure() FIRST — a successful poll retires a stale error row (source-level only; see note)' );

ok( preg_match( '/data\.seven_day\s*\|\|\s*\{\}/', $js ) === 1,
	'seven_day is guarded like every other field — renderRows already defends its arrays with ( rows || [] )' );

ok( strpos( $js, 'missing configuration' ) !== false,
	'a failed config injection REPORTS instead of no-opping on the skeleton forever (silent failure)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
