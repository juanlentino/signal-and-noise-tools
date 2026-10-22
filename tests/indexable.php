<?php
/**
 * The one indexable predicate (inc/indexable.php): what the sitemap, the URL
 * Inspection targets, IndexNow and the indexing report all ask.
 *
 * Run: php tests/indexable.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['meta']  = array( 3 => array( '_sn_noindex' => '1' ), 4 => array( '_sn_canonical_url' => 'https://elsewhere.test/x/' ) );
$GLOBALS['posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://example.test/notes/a/' ),
	2 => (object) array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://example.test/verify/' ),
	3 => (object) array( 'ID' => 3, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://example.test/call/' ),
	4 => (object) array( 'ID' => 4, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://example.test/notes/c/' ),
	5 => (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'draft', 'post_password' => '', 'url' => 'https://example.test/?p=5' ),
	6 => (object) array( 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'pw', 'url' => 'https://example.test/private/' ),
	7 => (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'url' => 'https://example.test/provenance/verify/' ),
);
function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ (int) $id ][ $k ] ?? ''; }
function get_permalink( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? $p->url : ''; }

require __DIR__ . '/../inc/indexable.php';

echo "indexable predicate\n\n";
ok( true === sn_post_is_indexable( 1 ), 'a published note is indexable' );
ok( false === sn_post_is_indexable( 2 ), '/verify is NOT indexable: the plugin serves it noindex through the virtual route, with no _sn_noindex meta' );
ok( false === sn_post_is_indexable( 3 ), 'a page marked _sn_noindex (as /call) is not indexable' );
ok( false === sn_post_is_indexable( 4 ), 'a canonical pointing elsewhere is not indexable' );
ok( false === sn_post_is_indexable( 5 ), 'a draft is not indexable' );
ok( false === sn_post_is_indexable( 6 ), 'a password-protected page is not indexable' );
ok( true === sn_post_is_indexable( 7 ), 'a deeper path ending in /verify is not the route: still indexable' );
ok( true === sn_post_is_indexable( $GLOBALS['posts'][1] ) && false === sn_post_is_indexable( 99 ), 'takes an object or an id; an unknown id is not indexable' );
ok( false === sn_indexable_decision( array( 'type' => 'attachment', 'status' => 'publish', 'path' => '/a' ) ), 'only posts and pages' );
ok( array( '/verify' ) === sn_noindex_route_paths(), 'the plugin-served noindex paths are one list: /verify' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
