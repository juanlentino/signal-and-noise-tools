<?php
/**
 * The legacy ?tab= 301 runs on load-{$hook}, before any output (2026-11-10).
 *
 * It used to run inside sn_theme_options_page(), the menu render callback, which
 * core calls after admin-header.php has printed. The Location header failed,
 * exit ran, and a legacy bookmark such as ?tab=rss opened an empty admin page
 * (seen live: 200, no redirect, empty #wpbody-content). This suite fires the
 * admin_menu registration, then the page's load-{$hook}, the way admin.php does.
 * CLI-only.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function add_menu_page( $pt, $mt, $cap, $slug ) { return 'toplevel_page_' . $slug; }
function add_submenu_page( $parent, $pt, $mt, $cap, $slug ) { return 'signal-noise_page_' . $slug; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
class SN_Redirected extends Exception {}
function wp_safe_redirect( $url, $status = 302 ) { throw new SN_Redirected( $status . ' ' . $url ); }

require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/admin-legacy-redirect.php';
require __DIR__ . '/../inc/admin-menu.php';

$pass = 0; $fail = 0;
function ok( $cond, $m ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// Fire the registration exactly once, as core does on admin_menu.
foreach ( $GLOBALS['__actions']['admin_menu'] ?? array() as $cb ) { $cb(); }
$hooks = sn_admin_page_hooks();

/** Run one page's load-{$hook} for a request; return the redirect, or '' if none. */
function sn_test_load( $hook, $get, $method = 'GET' ) {
	$_GET                      = $get;
	$_SERVER['REQUEST_METHOD'] = $method;
	try {
		foreach ( $GLOBALS['__actions'][ 'load-' . $hook ] ?? array() as $cb ) { $cb(); }
	} catch ( SN_Redirected $r ) {
		return $r->getMessage();
	}
	return '';
}

echo "Legacy admin redirect runs before output\n\n";

ok( in_array( 'toplevel_page_sn-theme-options', $hooks, true ) && count( $hooks ) > 1, 'the menu registers the top page and its submenu pages' );
$wired = array_filter( $hooks, static function ( $h ) {
	return in_array( 'sn_admin_maybe_redirect_legacy', $GLOBALS['__actions'][ 'load-' . $h ] ?? array(), true );
} );
ok( count( $wired ) === count( $hooks ), 'every registered S&N page runs the legacy redirect on its load-{$hook}, before admin-header.php prints' );

$top = 'toplevel_page_sn-theme-options';
ok( '301 https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=rss' === sn_test_load( $top, array( 'page' => 'sn-theme-options', 'tab' => 'rss' ) ),
	'GET ?tab=rss 301s to Monitoring > RSS (live, this request answered 200 with an empty page)' );
ok( '' === sn_test_load( $top, array( 'page' => 'sn-theme-options', 'tab' => 'monitoring', 'sub' => 'rss' ) ),
	'   ...and the canonical Monitoring > RSS URL does not redirect (no loop)' );
ok( '' === sn_test_load( $top, array( 'page' => 'sn-theme-options' ) ),
	'   ...and the bare page (the Dashboard) does not redirect' );
ok( 0 === strpos( sn_test_load( $top, array( 'page' => 'sn-theme-options', 'tab' => 'rss' ), 'HEAD' ), '301 ' ),
	'HEAD follows GET' );
ok( '' === sn_test_load( $top, array( 'page' => 'sn-theme-options', 'tab' => 'rss' ), 'POST' ),
	'POST to a legacy URL is NOT redirected: a 301 would turn it into a GET and drop the form body' );

$site = 'signal-noise_page_sn-site';
if ( in_array( $site, $hooks, true ) ) {
	ok( '' === sn_test_load( $site, array( 'page' => 'sn-site' ) ), 'a current submenu slug (sn-site) does not redirect' );
	ok( 0 === strpos( sn_test_load( $site, array( 'page' => 'sn-site', 'tab' => 'automation' ) ), '301 https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=connections' ),
		'a legacy ?tab= on a submenu page redirects too (bare ?tab=automation → connections)' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
