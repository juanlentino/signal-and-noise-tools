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
foreach ( array( 'inc/admin-tab-dashboard.php', 'inc/dash-api-summary.php' ) as $f ) {
	$src = (string) file_get_contents( $root . '/' . $f );
	// The handler's own registration and its check_admin_referer are allowed; a wp_nonce_url() LINK to it is not.
	ok( 0 === preg_match( "/wp_nonce_url\\(\\s*admin_url\\(\\s*'admin-post\\.php\\?action=sn_force_update_check'/", $src ), "$f builds no nonce link to the force-check door" );
	ok( false !== strpos( $src, "update-core.php?force-check=1" ), "$f links to core's nonce-free force-check" );
}

echo "\nGroup 2: the cache clear runs on core's force-check screen\n";
$GLOBALS['__actions'] = array(); $GLOBALS['__deleted'] = array(); $GLOBALS['__can'] = true;
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return true; }
function delete_site_transient( $k ) { $GLOBALS['__deleted'][] = $k; return true; }
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
