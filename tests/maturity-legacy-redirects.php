<?php
/**
 * Tests for inc/maturity-legacy-redirects.php — the narrow 301 map for the
 * family's dead top-level URLs. Pins: fires only on 404, only for mapped
 * single-segment paths, resolves through get_permalink (hierarchy-proof),
 * and never becomes generic slug-guessing or an existence oracle.
 * Run: php tests/maturity-legacy-redirects.php
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
$GLOBALS['__pages'] = array( 'analytics', 'proof-of-origin', 'ai-maturity', 'machine-readability', 'ops-maturity', 'a11y-maturity', 'private-page', 'verify', 'inicio', 'sobre-mi' );
function get_posts( $args ) {
	$name = isset( $args['name'] ) ? (string) $args['name'] : '';
	return in_array( $name, $GLOBALS['__pages'], true ) ? array( (object) array( 'post_name' => $name ) ) : array();
}
function get_permalink( $post ) {
	$top = array( 'verify', 'inicio', 'sobre-mi' );
	return 'https://example.com/' . ( in_array( $post->post_name, $top, true ) ? '' : 'maturity/' ) . $post->post_name . '/';
}
function is_post_publicly_viewable( $post ) { return 'private-page' !== $post->post_name; }

require __DIR__ . '/../inc/maturity-legacy-redirects.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: decision predicate\n";
ok( 'https://example.com/maturity/analytics/' === sn_maturity_legacy_redirect_decision( '/analytics/', true ), 'dead /analytics/ 301s to its child-of-/maturity/ permalink' );
ok( 'https://example.com/maturity/proof-of-origin/' === sn_maturity_legacy_redirect_decision( '/proof-of-origin/?utm_source=x', true ), 'query strings do not defeat the segment match' );
ok( '' === sn_maturity_legacy_redirect_decision( '/analytics/', false ), 'NOT a 404 → never redirects (a real page at that path always wins)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/some-random-post/', true ), 'unmapped segment → no redirect (this is a fixed map, not slug-guessing)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/maturity/analytics/extra/', true ), 'an unmapped multi-segment path never matches (the map is exact, not a prefix)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/', true ), 'empty path → no redirect' );

echo "\nGroup: resolution + gates\n";
$GLOBALS['__filters']['sn_maturity_legacy_redirects'] = function ( $map ) {
	$map['old-thing']  = 'no-such-page';
	$map['old-secret'] = 'private-page';
	return $map;
};
ok( '' === sn_maturity_legacy_redirect_decision( '/old-thing/', true ), 'mapped-but-unresolvable target → no redirect (silent 404, no oracle)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/old-secret/', true ), 'a non-publicly-viewable target never receives a redirect' );
$GLOBALS['__filters'] = array();
ok( 9 === count( sn_maturity_legacy_redirect_map() ), 'default map covers the six family slugs and the three 2026-10 Search Console paths' );

echo "\nGroup: the 2026-10 Search Console 404s (old page paths)\n";
ok( 'https://example.com/verify/' === sn_maturity_legacy_redirect_decision( '/provenance/verify', true ), '/provenance/verify 301s to /verify/' );
ok( 'https://example.com/verify/' === sn_maturity_legacy_redirect_decision( '/provenance/verify/', true ), '/provenance/verify/ 301s to /verify/' );
ok( 'https://example.com/inicio/' === sn_maturity_legacy_redirect_decision( '/es/', true ), '/es/ 301s to /inicio/ (the home page\'s hreflang es)' );
ok( 'https://example.com/sobre-mi/' === sn_maturity_legacy_redirect_decision( '/es/about/', true ), '/es/about/ 301s to /sobre-mi/ (about\'s hreflang es)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/es/notes/', true ), '/es/notes/ stays a 404: /notes/ has no Spanish page' );
ok( '' === sn_maturity_legacy_redirect_decision( '/verify/', false ), '/verify?note=&v= is a live page, never redirected' );
ok( '' === sn_maturity_legacy_redirect_decision( '/wp-content/plugins/x/readme.txt', true ), 'a scanner 404 stays a 404' );
ok( '' === sn_maturity_legacy_redirect_decision( '/provenance/verify/extra/', true ), 'a deeper path under a mapped one is not a match' );

// The Spanish targets are the slugs the bilingual pairing seeds for the home
// page ('' English path) and /about/, so the redirect and hreflang agree.
require __DIR__ . '/../inc/bilingual-seed.php';
$seed = sn_lang_seed_map();
$map  = sn_maturity_legacy_redirect_map();
ok( isset( $map['es'], $seed[ $map['es'] ] ) && '' === $seed[ $map['es'] ][1], "the /es/ target is the Spanish page paired with the home page" );
ok( isset( $map['es/about'], $seed[ $map['es/about'] ] ) && 'about' === $seed[ $map['es/about'] ][1], 'the /es/about/ target is the Spanish page paired with /about/' );
ok( isset( $GLOBALS['__actions']['template_redirect'] ), 'handler is hooked on template_redirect' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
