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
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function esc_url( $t ) { return $t; }
function __( $t, $d = null ) { return $t; }
function _e( $t, $d = null ) { echo $t; }

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

echo "\n── TEMPLATE IS A SKELETON (data would freeze at shell render) ──\n";
ob_start();
call_user_func( $w['template'] );
$tpl = (string) ob_get_clean();

ok( strpos( $tpl, 'data-sn-hud="realtime"' ) !== false, 'template declares the realtime mount point' );
ok( strpos( $tpl, 'data-sn-hud="kpis"' ) !== false, 'template declares the kpis mount point' );
ok( strpos( $tpl, 'data-sn-hud="top-content"' ) !== false, 'template declares the top-content mount point' );
ok( strpos( $tpl, 'data-sn-hud="top-sources"' ) !== false, 'template declares the top-sources mount point' );

// THE property: desktop-mode prints this template into the DOM ONCE at shell
// render and clones it per open. Any number echoed here is frozen at page load
// — realtime would be a lie with a fresh-looking label. So: no digits at all.
ok( preg_match( '/\d/', strip_tags( $tpl ) ) !== 1,
	'template body contains NO digits — it is a skeleton, not a snapshot' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
