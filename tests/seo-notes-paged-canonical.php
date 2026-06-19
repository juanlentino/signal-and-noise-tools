<?php
/**
 * Tests for the v5.1.0 paged self-canonical on /notes. Verifies
 * sn_seo_current_paged() (query var + $_GET fallback) and that the /notes
 * branch of sn_seo_meta_for_current_view() appends ?paged=N for N>1 while
 * page 1 stays bare /notes/.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

// ── Minimal WP stubs to drive the /notes branch ─────────────────────
$GLOBALS['__qv'] = array();             // query vars
function get_query_var( $k, $d = '' ) { return $GLOBALS['__qv'][ $k ] ?? $d; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function is_front_page() { return false; }
function is_page( $s = '' ) { return 'notes' === $s; }   // we are on /notes
function is_home() { return false; }
function is_singular() { return false; }
function sn_setting( $p, $d = null ) { return $d; }        // no curated copy → '' defaults
function add_query_arg( $k, $v, $url ) {
	$sep = ( strpos( $url, '?' ) === false ) ? '?' : '&';
	return $url . $sep . $k . '=' . $v;
}
function add_action() {}
function add_filter() {}
function remove_action() {}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// v6.24.0: seo.php now consults the sn_seo_route_meta / sn_seo_singular_description
// filters — passthrough so no theme route is matched (returns the default).
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) { return $value; }
}

require __DIR__ . '/../inc/seo.php';

// ── sn_seo_current_paged(): query var, then $_GET fallback, floor 1 ──
$GLOBALS['__qv'] = array(); unset( $_GET['paged'] );
ok( 1 === sn_seo_current_paged(), 'paged: defaults to 1 when nothing set' );
$GLOBALS['__qv']['paged'] = 3;
ok( 3 === sn_seo_current_paged(), 'paged: reads the query var' );
$GLOBALS['__qv'] = array(); $_GET['paged'] = '2';
ok( 2 === sn_seo_current_paged(), 'paged: $_GET fallback when query var is 0' );
$_GET['paged'] = '-5';
ok( 1 === sn_seo_current_paged(), 'paged: floored at 1' );
unset( $_GET['paged'] );

// ── /notes canonical $url is paged-aware ────────────────────────────
$GLOBALS['__qv'] = array(); // page 1
list( , , $url ) = sn_seo_meta_for_current_view();
ok( 'https://example.com/notes/' === $url, 'canonical: page 1 is bare /notes/' );
$GLOBALS['__qv']['paged'] = 2;
list( , , $url ) = sn_seo_meta_for_current_view();
ok( 'https://example.com/notes/?paged=2' === $url, 'canonical: page 2 self-canonicals to ?paged=2' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
