<?php
/**
 * CLI fixture for inc/tag-consolidation-redirects.php — the 301 map + handler.
 * Run: php tests/tag-consolidation-redirects.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

$GLOBALS['__opts']       = array();
$GLOBALS['__redirect']   = null; // [url, status]
$GLOBALS['__live_slugs'] = array(); // slugs that still resolve to a live term
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_safe_redirect( $u, $s = 302 ) { $GLOBALS['__redirect'] = array( $u, $s ); }
function term_exists( $slug, $tax = '' ) { return in_array( $slug, $GLOBALS['__live_slugs'], true ) ? array( 'term_id' => 1 ) : null; }
function wp_unslash( $s ) { return $s; }
function add_action() {}

require __DIR__ . '/../inc/tag-consolidation-redirects.php';

// record + chain collapse
sn_tag_redirects_record( array( 'old-a', 'old-b' ), 'canon' );
$m = get_option( 'sn_tag_redirects' );
ok( $m['old-a'] === 'canon' && $m['old-b'] === 'canon', 'record: writes old->canonical' );
sn_tag_redirects_record( array( 'canon' ), 'final' ); // canon now merged away
$m = get_option( 'sn_tag_redirects' );
ok( $m['old-a'] === 'final' && $m['canon'] === 'final', 'record: collapses chains (old-a -> final, not -> canon)' );

// handler — drive the pure target resolver (no exit), like the humans-txt pattern.
ok( sn_tag_redirect_target( '/notes/tag/old-a/' ) === 'https://x.test/notes/tag/final/',
	'handler: 301 target for a mapped dead slug is the canonical archive' );
$GLOBALS['__live_slugs'] = array( 'old-a' ); // slug got re-created as a live term
ok( sn_tag_redirect_target( '/notes/tag/old-a/' ) === '', 'handler: ignores a slug that resolves to a live term' );
$GLOBALS['__live_slugs'] = array();
ok( sn_tag_redirect_target( '/notes/tag/unknown/' ) === '', 'handler: ignores an unmapped slug' );
ok( sn_tag_redirect_target( '/notes/' ) === '', 'handler: ignores non tag-archive URLs' );
ok( sn_tag_redirect_target( '/about/' ) === '', 'handler: ignores unrelated URLs' );
ok( sn_tag_redirect_target( '/notes/tag/old-a/?x=1' ) === 'https://x.test/notes/tag/final/', 'handler: matches with a query string' );

// Retired tags (the 83-to-23 pass): the canonical /tag/ form, survivor or index.
$GLOBALS['__live_slugs'] = array( 'music-production', 'provenance' );
ok( sn_tag_redirect_target( '/tag/ai-tools/' ) === 'https://x.test/tag/music-production/', 'retired: /tag/ai-tools/ goes to its survivor' );
ok( sn_tag_redirect_target( '/notes/tag/audio-engineering/' ) === 'https://x.test/notes/tag/music-production/', 'retired: the /notes/tag/ form keeps its form' );
ok( sn_tag_redirect_target( '/tag/evidence/' ) === 'https://x.test/notes/', 'retired: a deleted tag goes to the notes index' );
$GLOBALS['__live_slugs'] = array( 'provenance' );
ok( sn_tag_redirect_target( '/tag/ai-tools/' ) === 'https://x.test/notes/', 'retired: a survivor that no longer lives sends to the index, never a 404' );
$GLOBALS['__live_slugs'] = array( 'ai-tools', 'music-production' );
ok( sn_tag_redirect_target( '/tag/ai-tools/' ) === '', 'retired: a re-created live tag wins' );
ok( sn_tag_redirect_target( '/tag/music-production/' ) === '' && sn_tag_redirect_target( '/tagged/ai-tools/' ) === '', 'a survivor and a lookalike path are left alone' );

$GLOBALS['__live_slugs'] = array();
ok( sn_tag_redirect_target( '/tag/music-provenance/' ) === 'https://x.test/provenance/' && sn_tag_redirect_target( '/tag/provenance/' ) === 'https://x.test/provenance/', 'provenance tags go to the /provenance/ hub' );
$GLOBALS['__live_slugs'] = array( 'provenance' );
ok( sn_tag_redirect_target( '/tag/provenance/' ) === '', 'a re-created provenance tag serves its own archive' );

$GLOBALS['__live_slugs'] = array( 'cryptographic-signatures', 'music-metadata' );
ok( sn_tag_redirect_target( '/tag/cryptography/' ) === 'https://x.test/tag/cryptographic-signatures/' && sn_tag_redirect_target( '/tag/music-identification/' ) === 'https://x.test/tag/music-metadata/', "the theme's two retirements live in the one map" );

$GLOBALS['__live_slugs'] = array( 'music-production' );
ok( sn_tag_redirect_target( '/tag/provenance/page/2/' ) === 'https://x.test/provenance/' && sn_tag_redirect_target( '/tag/ai-tools/page/3' ) === 'https://x.test/tag/music-production/', 'a retired tag\'s /page/N/ lands on the target\'s first page' );
ok( sn_tag_redirect_target( '/tag/ai-tools/page/x/' ) === '' && sn_tag_redirect_target( '/tag/music-production/page/2/' ) === '', 'a non-numeric page and a live tag\'s pages are left alone' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
