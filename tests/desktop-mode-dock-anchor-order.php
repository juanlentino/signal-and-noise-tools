<?php
/**
 * Regression test (#1228): snt_desktop_admin_url() must append the anchor
 * (a URL fragment) LAST — after the trailing &sub= param, not before it.
 * An anchor appended before &sub= puts the '&sub=...' text after the '#',
 * where a browser treats it as part of the fragment: it never reaches the
 * server as a query parameter.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {}
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function snt_os_compat_add_filter( $h, $cb, $p = 10, $a = 1 ) {}

// Force the destination to carry BOTH an anchor AND (via the fallback $sub
// arg) a trailing &sub= — the exact combination that exposes the ordering bug.
function sn_admin_page_tab_for_slug( $slug ) { return 'legacy-tab'; }
function sn_admin_canonical_destination( $tab ) {
	return array( 'tab' => 'new-tab', 'anchor' => 'my-anchor' ); // no 'sub' key: the trailing $sub branch fires.
}

require __DIR__ . '/../inc/desktop-mode-dock.php';

$url = snt_desktop_admin_url( 'legacy-slug', 'my-sub' );
echo "URL: $url\n";

$frag_pos = strpos( $url, '#' );
ok( false !== $frag_pos, 'fixture: the URL really does carry a fragment (so this pin cannot be vacuous)' );
$sub_pos = strpos( $url, '&sub=' );
ok( false !== $sub_pos, 'fixture: the URL really does carry a &sub= param (so this pin cannot be vacuous)' );
ok( $sub_pos < $frag_pos, '#1228: &sub= appears BEFORE the # fragment, so it reaches the server as a real query param' );
ok( '#' === substr( $url, $frag_pos, 1 ) && false === strpos( substr( $url, $frag_pos ), '&sub=' ), 'the fragment itself carries no stray &sub= text' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
