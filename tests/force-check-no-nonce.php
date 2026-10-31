<?php
/**
 * Tests: "Check for updates" carries no expiring token (v14.0.3).
 *
 * An iOS standalone PWA keeps the last-rendered admin page in memory for
 * days without reloading. A wp_nonce_url() link in that page dies after 24h
 * (or on a session-token rotation) with "The link you followed has expired".
 * Core's own update-core.php?force-check=1 needs no nonce — it is gated by
 * the update_core capability — so the plugin's cache clear runs there
 * (load-update-core.php) and every "check updates" link points there.
 * The admin-post handler stays for bookmarks.
 *
 * Run: php tests/force-check-no-nonce.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$root = dirname( __DIR__ );

echo "Group 1: no link to the nonce door remains\n";
foreach ( array( 'inc/admin-tab-dashboard.php' ) as $f ) { // inc/dash-api-summary.php removed: no caller.
	// The module is a shell plus three parts files (split 2026-10-16), read whole.
	require_once __DIR__ . '/lib/inc-population.php';
	$src = 'inc/admin-tab-dashboard.php' === $f ? snt_test_admin_tab_dashboard_src() : (string) file_get_contents( $root . '/' . $f );
	// The handler's own registration and its check_admin_referer are allowed; a wp_nonce_url() LINK to it is not.
	ok( '' !== $src && 0 === preg_match( "/wp_nonce_url\\(\\s*admin_url\\(\\s*'admin-post\\.php\\?action=sn_force_update_check'/", $src ), "$f builds no nonce link to the force-check door" );
	ok( false !== strpos( $src, "update-core.php?force-check=1" ), "$f links to core's nonce-free force-check" );
}

echo "\nGroup 2: the cache clear runs on core's force-check screen\n";
$GLOBALS['__actions'] = array(); $GLOBALS['__deleted'] = array(); $GLOBALS['__can'] = true;
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; $GLOBALS['__prio'][ $h ][] = $p; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return true; }
function delete_site_transient( $k ) { $GLOBALS['__deleted'][] = $k; unset( $GLOBALS['__t'][ $k ] ); return true; }
function current_user_can( $c ) { return $GLOBALS['__can']; }
function esc_html__( $s, $d = null ) { return $s; }
function wp_die() {}
function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; }
function wp_safe_redirect( $u ) {}
function check_admin_referer() { return true; }
function esc_url( $u ) { return $u; }
function __( $s, $d = null ) { return $s; }
require_once $root . '/inc/desktop-mode-commands.php';
$before = count( $GLOBALS['__actions']['load-update-core.php'] ?? array() );
ok( $before >= 1, 'a load-update-core.php action is registered' );
$cb = $GLOBALS['__actions']['load-update-core.php'][0] ?? null;
$_GET = array();
$GLOBALS['__deleted'] = array(); if ( $cb ) { $cb(); }
ok( array() === $GLOBALS['__deleted'], 'without ?force-check nothing is cleared (a plain visit to Updates is not a force)' );
$_GET['force-check'] = '1';
$GLOBALS['__deleted'] = array(); if ( $cb ) { $cb(); }
ok( in_array( 'sn_gh_latest_theme', $GLOBALS['__deleted'], true ) && in_array( 'sn_gh_latest_plugin', $GLOBALS['__deleted'], true ) && in_array( 'update_plugins', $GLOBALS['__deleted'], true ), '?force-check=1 clears the plugin, theme and core update transients' );
$GLOBALS['__can'] = false; $GLOBALS['__deleted'] = array(); if ( $cb ) { $cb(); }
ok( array() === $GLOBALS['__deleted'], 'a user without update_core clears nothing (core would have refused the screen anyway)' );

echo "\nGroup 3: Check Again still lists the updates it just found (2026-10-31)\n";
// Core registers wp_update_plugins and wp_update_themes on load-update-core.php
// at priority 10 from wp-settings.php, BEFORE any plugin loads, so at equal
// priority they run first. The clear used to run after them: it deleted the
// records core had just written, and update-core.php, which reads them right
// after this action, listed no theme or plugin updates. Dispatched here the
// way WP_Hook does it: by priority, then registration order.
$GLOBALS['__t'] = array();
$queue = array(
	array( 10, 0, function () { $GLOBALS['__t']['update_plugins'] = 'fresh'; } ), // core, wp-includes/update.php
	array( 10, 1, function () { $GLOBALS['__t']['update_themes'] = 'fresh'; } ),  // core
);
foreach ( $GLOBALS['__actions']['load-update-core.php'] ?? array() as $i => $plugin_cb ) {
	$queue[] = array( $GLOBALS['__prio']['load-update-core.php'][ $i ], 2 + $i, $plugin_cb );
}
usort( $queue, function ( $a, $b ) { return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ); } );
$_GET['force-check'] = '1'; $GLOBALS['__can'] = true;
foreach ( $queue as $q ) { $q[2](); }
ok( 'fresh' === ( $GLOBALS['__t']['update_themes'] ?? null ), 'after Check Again the theme update record is there for the screen to list' );
ok( 'fresh' === ( $GLOBALS['__t']['update_plugins'] ?? null ), 'and so is the plugin update record' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
