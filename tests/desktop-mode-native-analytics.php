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

// Increments per call so a later test can prove realtime is never cached.
$GLOBALS['__rt'] = 0;
function sn_analytics_realtime( $class = 'human' ) { return ++$GLOBALS['__rt']; }

function sn_analytics_range_totals( $from, $to, $class = 'human', $refresh = false ) {
	return array( 'views' => 1200, 'visits' => 800, 'avg_scroll' => 61.5, 'avg_time' => 74.2 );
}
function sn_analytics_period_deltas( $from, $to, $class = 'human', $cwin = null ) {
	return array( 'views' => 12.5, 'visits' => -3.1 );
}
function sn_analytics_engaged_rate( $from, $to, $class = 'human' ) { return 42.0; }
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) {
	return array_slice( array(
		array( 'path' => '/a', 'views' => 90 ), array( 'path' => '/b', 'views' => 80 ),
		array( 'path' => '/c', 'views' => 70 ), array( 'path' => '/d', 'views' => 60 ),
		array( 'path' => '/e', 'views' => 50 ), array( 'path' => '/f', 'views' => 40 ),
	), 0, $limit );
}
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	return array_slice( array(
		array( 'source' => 'direct', 'visits' => 40 ), array( 'source' => 'rss', 'visits' => 30 ),
		array( 'source' => 'hn', 'visits' => 20 ), array( 'source' => 'x', 'visits' => 10 ),
		array( 'source' => 'g', 'visits' => 5 ), array( 'source' => 'b', 'visits' => 2 ),
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

echo "\n── CACHE BOUNDARY ──\n";
// Start clean: earlier assertions already warmed the cache.
$GLOBALS['__t'] = array();

$a = snt_desktop_analytics_hud_payload()->get_data(); // warms the 7d cache
$b = snt_desktop_analytics_hud_payload()->get_data(); // must hit the 7d cache

ok( $a['realtime'] !== $b['realtime'], 'realtime is recomputed every request (never day-cached)' );
ok( $a['seven_day'] === $b['seven_day'], 'seven_day is served from the day-stamped cache' );
ok( array_key_exists( 'sn_desktop_analytics_hud_2026-07-16', $GLOBALS['__t'] ),
	'cache key is stamped with the LOCAL day (not a flat key)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
