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
$GLOBALS['__pages'] = array( 'analytics', 'proof-of-origin', 'ai-maturity', 'machine-readability', 'ops-maturity', 'a11y-maturity', 'private-page' );
function get_posts( $args ) {
	$name = isset( $args['name'] ) ? (string) $args['name'] : '';
	return in_array( $name, $GLOBALS['__pages'], true ) ? array( (object) array( 'post_name' => $name ) ) : array();
}
function get_permalink( $post ) { return 'https://example.com/maturity/' . $post->post_name . '/'; }
function is_post_publicly_viewable( $post ) { return 'private-page' !== $post->post_name; }

require __DIR__ . '/../inc/maturity-legacy-redirects.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: decision predicate\n";
ok( 'https://example.com/maturity/analytics/' === sn_maturity_legacy_redirect_decision( '/analytics/', true ), 'dead /analytics/ 301s to its child-of-/maturity/ permalink' );
ok( 'https://example.com/maturity/proof-of-origin/' === sn_maturity_legacy_redirect_decision( '/proof-of-origin/?utm_source=x', true ), 'query strings do not defeat the segment match' );
ok( '' === sn_maturity_legacy_redirect_decision( '/analytics/', false ), 'NOT a 404 → never redirects (a real page at that path always wins)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/some-random-post/', true ), 'unmapped segment → no redirect (this is a fixed map, not slug-guessing)' );
ok( '' === sn_maturity_legacy_redirect_decision( '/maturity/analytics/extra/', true ), 'multi-segment paths never match — only the old TOP-LEVEL urls' );
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
ok( 6 === count( sn_maturity_legacy_redirect_map() ), 'default map covers exactly the six family slugs' );
ok( isset( $GLOBALS['__actions']['template_redirect'] ), 'handler is hooked on template_redirect' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
