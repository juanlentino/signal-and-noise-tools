<?php
/**
 * CLI fixture for inc/redirects-store.php — the general redirect map (B1).
 * Pure data layer: normalize, upsert, delete, cap, and the exit-free resolver.
 * Run: php tests/redirects-store.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

require __DIR__ . '/../inc/redirects-store.php';

// ── normalize ──
ok( sn_redirects_normalize_path( '/foo/bar/' ) === '/foo/bar', 'normalize: strips trailing slash' );
ok( sn_redirects_normalize_path( '/foo/bar' ) === '/foo/bar', 'normalize: leaves no-trailing-slash path' );
ok( sn_redirects_normalize_path( 'foo/bar' ) === '/foo/bar', 'normalize: adds leading slash' );
ok( sn_redirects_normalize_path( '/foo?x=1#frag' ) === '/foo', 'normalize: drops query + fragment' );
ok( sn_redirects_normalize_path( '/' ) === '/', 'normalize: root stays root' );
ok( sn_redirects_normalize_path( '' ) === '/', 'normalize: empty becomes root' );

// ── save (upsert) + validation ──
ok( sn_redirect_save( '/old-page/', '/new-page', 301 ) === true, 'save: valid internal redirect stored' );
$all = sn_redirects_all();
ok( isset( $all['/old-page'] ), 'save: source key normalized (trailing slash stripped)' );
ok( $all['/old-page']['to'] === '/new-page' && $all['/old-page']['status'] === 301, 'save: stores target + status' );
ok( isset( $all['/old-page']['created_at'] ), 'save: stamps created_at' );

ok( sn_redirect_save( '/old-page', '/newer-page', 302 ) === true, 'save: upsert same source' );
$all = sn_redirects_all();
ok( count( $all ) === 1, 'save: upsert does not duplicate the source' );
ok( $all['/old-page']['to'] === '/newer-page' && $all['/old-page']['status'] === 302, 'save: upsert overwrites target + status' );

ok( sn_redirect_save( '/loop', '/loop', 301 ) === false, 'save: rejects self-redirect (source === target)' );
ok( sn_redirect_save( '', '/x', 301 ) === false, 'save: rejects empty source' );
ok( sn_redirect_save( '/x', '', 301 ) === false, 'save: rejects empty target' );

// status coercion: anything not 302 becomes 301
sn_redirect_save( '/s', '/t', 307 );
$all = sn_redirects_all();
ok( $all['/s']['status'] === 301, 'save: coerces unsupported status to 301' );

// ── external target ──
ok( sn_redirect_save( '/ext', 'https://example.com/page', 301 ) === true, 'save: accepts absolute external target' );

// ── resolver (exit-free) ──
$GLOBALS['__opts'] = array(); // reset
sn_redirect_save( '/old', '/new', 301 );
sn_redirect_save( '/gone', 'https://example.com/there', 302 );
$t = sn_redirect_target( '/old/' );
ok( $t['to'] === 'https://x.test/new' && $t['status'] === 301, 'resolve: internal target → absolute home_url, matches ignoring trailing slash' );
$t = sn_redirect_target( '/old?ref=twitter' );
ok( $t['to'] === 'https://x.test/new', 'resolve: matches ignoring query string' );
$t = sn_redirect_target( '/gone' );
ok( $t['to'] === 'https://example.com/there' && $t['status'] === 302, 'resolve: external target passed through verbatim' );
ok( sn_redirect_target( '/not-mapped' ) === array(), 'resolve: unmapped path returns empty array' );

// ── delete ──
ok( sn_redirect_delete( '/old/' ) === true, 'delete: returns true for an existing source' );
ok( sn_redirect_target( '/old' ) === array(), 'delete: removed source no longer resolves' );
ok( sn_redirect_delete( '/never' ) === false, 'delete: returns false for a missing source' );

// ── cap ──
$GLOBALS['__opts'] = array();
for ( $i = 0; $i < SN_REDIRECTS_MAX + 25; $i++ ) {
	sn_redirect_save( '/p' . $i, '/t' . $i, 301 );
}
$all = sn_redirects_all();
ok( count( $all ) === SN_REDIRECTS_MAX, 'cap: map is bounded at SN_REDIRECTS_MAX' );
ok( ! isset( $all['/p0'] ) && isset( $all['/p' . ( SN_REDIRECTS_MAX + 24 )] ), 'cap: FIFO drops the oldest entries' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
