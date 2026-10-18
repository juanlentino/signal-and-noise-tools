<?php
/**
 * Tests for inc/legacy-page-redirects.php: the narrow 301 map for the
 * family's dead top-level URLs. Pins: fires only on 404, only for mapped
 * single-segment paths, resolves through get_permalink (hierarchy-proof),
 * and never becomes generic slug-guessing or an existence oracle.
 * Run: php tests/legacy-page-redirects.php
 * @since plugin v10.12.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $s, $d = null ) { return (string) $s; }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function add_action( $tag, $cb ) { $GLOBALS['__actions'][ $tag ][] = $cb; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
// Page store models the live hierarchy: children of /maturity/; one
// non-public page proves the viewability gate.
$GLOBALS['__pages'] = array( 'analytics', 'proof-of-origin', 'ai-maturity', 'machine-readability', 'ops-maturity', 'a11y-maturity', 'private-page', 'verify', 'inicio', 'sobre-mi', 'notes' );
function get_posts( $args ) {
	$name = isset( $args['name'] ) ? (string) $args['name'] : '';
	return in_array( $name, $GLOBALS['__pages'], true ) ? array( (object) array( 'post_name' => $name ) ) : array();
}
function get_permalink( $post ) {
	$top = array( 'verify', 'inicio', 'sobre-mi', 'notes' );
	return 'https://example.com/' . ( in_array( $post->post_name, $top, true ) ? '' : 'maturity/' ) . $post->post_name . '/';
}
function is_post_publicly_viewable( $post ) { return 'private-page' !== $post->post_name; }
// Core's shapes: urlencode_deep encodes every value, add_query_arg appends.
function urlencode_deep( $v ) { return is_array( $v ) ? array_map( 'urlencode_deep', $v ) : urlencode( (string) $v ); }
function add_query_arg( $args, $url ) {
	$pairs = array();
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}

require __DIR__ . '/../inc/legacy-page-redirects.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: decision predicate\n";
ok( 'https://example.com/maturity/analytics/' === sn_legacy_page_redirect_decision( '/analytics/', true ), 'dead /analytics/ 301s to its child-of-/maturity/ permalink' );
ok( 'https://example.com/maturity/proof-of-origin/?utm_source=x' === sn_legacy_page_redirect_decision( '/proof-of-origin/?utm_source=x', true ), 'query strings do not defeat the path match, and ride along' );
ok( '' === sn_legacy_page_redirect_decision( '/analytics/', false ), 'NOT a 404 → never redirects (a real page at that path always wins)' );
ok( '' === sn_legacy_page_redirect_decision( '/some-random-post/', true ), 'unmapped segment → no redirect (this is a fixed map, not slug-guessing)' );
ok( '' === sn_legacy_page_redirect_decision( '/maturity/analytics/extra/', true ), 'an unmapped multi-segment path never matches (the map is exact, not a prefix)' );
ok( '' === sn_legacy_page_redirect_decision( '/', true ), 'empty path → no redirect' );

echo "\nGroup: resolution + gates\n";
$GLOBALS['__filters']['sn_legacy_page_redirects'] = function ( $map ) {
	$map['old-thing']  = 'no-such-page';
	$map['old-secret'] = 'private-page';
	return $map;
};
ok( '' === sn_legacy_page_redirect_decision( '/old-thing/', true ), 'mapped-but-unresolvable target → no redirect (silent 404, no oracle)' );
ok( '' === sn_legacy_page_redirect_decision( '/old-secret/', true ), 'a non-publicly-viewable target never receives a redirect' );
$GLOBALS['__filters'] = array();
ok( 9 === count( sn_legacy_page_redirect_map() ), 'default map covers the six family slugs and the three 2026-10 Search Console paths' );

echo "\nGroup: the deprecated filter name still works\n";
$GLOBALS['__filters']['sn_maturity_legacy_redirects'] = function ( $map ) {
	$map['old-alias'] = 'verify';
	return $map;
};
ok( 'https://example.com/verify/' === sn_legacy_page_redirect_decision( '/old-alias/', true ), 'a callback on the deprecated sn_maturity_legacy_redirects still edits the map' );
$GLOBALS['__filters']['sn_legacy_page_redirects'] = function ( $map ) {
	unset( $map['old-alias'] );
	return $map;
};
ok( '' === sn_legacy_page_redirect_decision( '/old-alias/', true ), 'sn_legacy_page_redirects runs after the alias, so it has the last word' );
$GLOBALS['__filters'] = array();

echo "\nGroup: the 2026-10 Search Console 404s (old page paths)\n";
ok( 'https://example.com/verify/' === sn_legacy_page_redirect_decision( '/provenance/verify', true ), '/provenance/verify 301s to /verify/' );
ok( 'https://example.com/verify/' === sn_legacy_page_redirect_decision( '/provenance/verify/', true ), '/provenance/verify/ 301s to /verify/' );
ok( 'https://example.com/inicio/' === sn_legacy_page_redirect_decision( '/es/', true ), '/es/ 301s to /inicio/ (the home page\'s hreflang es)' );
ok( 'https://example.com/sobre-mi/' === sn_legacy_page_redirect_decision( '/es/about/', true ), '/es/about/ 301s to /sobre-mi/ (about\'s hreflang es)' );
ok( 'https://example.com/verify/?note=0b7c1d2e-3f40-4a5b-8c6d-7e8f90a1b2c3&v=2' === sn_legacy_page_redirect_decision( '/provenance/verify?note=0b7c1d2e-3f40-4a5b-8c6d-7e8f90a1b2c3&v=2', true ), 'an old verify link keeps its note and version' );
ok( 'https://example.com/verify/?note=%3Cscript%3E%0D%0ALocation%3A+x' === sn_legacy_page_redirect_decision( '/provenance/verify/?note=%3Cscript%3E%0D%0ALocation:%20x', true ), 'a hostile query value is re-encoded, never passed through raw' );
ok( 'https://example.com/verify/?a%26b=1' === sn_legacy_page_redirect_decision( '/provenance/verify/?a%26b=1', true ), 'a key carrying an encoded ampersand stays one key, never splits into two parameters' );
ok( 'https://example.com/verify/' === sn_legacy_page_redirect_decision( '/provenance/verify/?', true ), 'an empty query adds nothing' );
ok( 'https://example.com/notes/' === sn_legacy_page_redirect_decision( '/notas/', true ), '/notas/ 301s to /notes/: the notes are only in English, and a Spanish reader goes there anyway (owner, 2026-10-18)' );
ok( 'https://example.com/notes/' === sn_legacy_page_redirect_decision( '/es/notes/', true ), '/es/notes/ 301s to /notes/ too' );
ok( 'https://example.com/notes/' === sn_legacy_page_redirect_decision( '/es/notas/', true ), '/es/notas/ 301s to /notes/ too' );
ok( '' === sn_legacy_page_redirect_decision( '/notas/some-note/', true ), 'a deeper /notas/ path is still a 404 (exact paths only)' );
ok( '' === sn_legacy_page_redirect_decision( '/notas/', false ), 'a real /notas/ page, if one is ever published, wins (only 404s redirect)' );
ok( '' === sn_legacy_page_redirect_decision( '/verify/', false ), '/verify?note=&v= is a live page, never redirected' );
ok( '' === sn_legacy_page_redirect_decision( '/wp-content/plugins/x/readme.txt', true ), 'a scanner 404 stays a 404' );
ok( '' === sn_legacy_page_redirect_decision( '/provenance/verify/extra/', true ), 'a deeper path under a mapped one is not a match' );

// The Spanish targets are the slugs the bilingual pairing seeds for the home
// page ('' English path) and /about/, so the redirect and hreflang agree.
require __DIR__ . '/../inc/bilingual-seed.php';
$seed = sn_lang_seed_map();
$map  = sn_legacy_page_redirect_map();
ok( isset( $map['es'], $seed[ $map['es'] ] ) && '' === $seed[ $map['es'] ][1], "the /es/ target is the Spanish page paired with the home page" );
ok( isset( $map['es/about'], $seed[ $map['es/about'] ] ) && 'about' === $seed[ $map['es/about'] ][1], 'the /es/about/ target is the Spanish page paired with /about/' );
ok( isset( $GLOBALS['__actions']['template_redirect'] ), 'handler is hooked on template_redirect' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
