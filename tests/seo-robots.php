<?php
/**
 * Tests: the robots-meta directive builder + its cross-package filter seam.
 *
 * v9.88.0. The plugin removes core's wp_robots (inc/seo.php) and emits its
 * own <meta name="robots">, so a theme-side wp_robots filter is DEAD CODE —
 * exactly the trap the theme's v10.51.0 search-noindex fell into (live-verified
 * inert). This fixture pins the seam that fixes it: sn_seo_robots_directives.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__singular'] = false;
$GLOBALS['__queried']  = null;
$GLOBALS['__meta']     = array();
$GLOBALS['__filters']  = array();

function is_singular( $t = '' ) { return (bool) $GLOBALS['__singular']; }
function get_queried_object() { return $GLOBALS['__queried']; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
		return call_user_func( $GLOBALS['__filters'][ $tag ], $value );
	}
	return $value;
}
function add_action() {}
function add_filter() {}
function remove_action() {}
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function is_front_page() { return false; }
function is_home() { return false; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function get_option( $k, $d = false ) { return $d; }
function sn_setting( $k, $d = null ) { return $d; }
function wp_strip_all_tags( $s ) { return $s; }
function get_the_title() { return ''; }
function get_permalink() { return ''; }

require __DIR__ . '/../inc/seo.php';

echo "Group: defaults\n";
$d = sn_seo_robots_directives();
ok( in_array( 'max-snippet:-1', $d, true ), 'permissive default max-snippet present' );
ok( in_array( 'max-image-preview:large', $d, true ), 'permissive default max-image-preview present' );
ok( in_array( 'max-video-preview:-1', $d, true ), 'permissive default max-video-preview present' );
ok( ! in_array( 'noindex', $d, true ), 'nothing is noindexed by default' );

echo "\nGroup: per-post overrides (v12.12.0: noindex and nofollow are independent)\n";
$GLOBALS['__singular'] = true;
$GLOBALS['__queried']  = (object) array( 'ID' => 7 );
$GLOBALS['__meta']     = array( 7 => array( '_sn_noindex' => '1' ) );
$d = sn_seo_robots_directives();
ok( in_array( 'noindex', $d, true ), 'post noindex flag adds noindex' );
ok( ! in_array( 'nofollow', $d, true ), 'noindex alone does NOT add nofollow (the v12.12.0 decoupling)' );
$GLOBALS['__meta'] = array( 7 => array( '_sn_nofollow' => '1' ) );
$d = sn_seo_robots_directives();
ok( in_array( 'nofollow', $d, true ), 'the standalone nofollow flag adds nofollow' );
ok( ! in_array( 'noindex', $d, true ), 'nofollow alone does NOT add noindex' );
$GLOBALS['__meta'] = array( 7 => array( '_sn_noindex' => '1', '_sn_nofollow' => '1' ) );
$d = sn_seo_robots_directives();
ok( in_array( 'noindex', $d, true ) && in_array( 'nofollow', $d, true ), 'both flags together still reach noindex,nofollow' );
$GLOBALS['__meta'] = array( 7 => array( '_sn_noarchive' => '1', '_sn_noimageindex' => '1' ) );
$d = sn_seo_robots_directives();
ok( in_array( 'noarchive', $d, true ) && in_array( 'noimageindex', $d, true ), 'noarchive + noimageindex flags honored' );
$GLOBALS['__singular'] = false;
$GLOBALS['__queried']  = null;
$GLOBALS['__meta']     = array();

echo "\nGroup: the sn_seo_robots_directives seam (the theme's search noindex rides this)\n";
$GLOBALS['__filters']['sn_seo_robots_directives'] = function ( $dirs ) {
	$dirs[] = 'noindex';
	$dirs[] = 'follow';
	return $dirs;
};
$d = sn_seo_robots_directives();
ok( in_array( 'noindex', $d, true ) && in_array( 'follow', $d, true ), 'a listener can add directives' );
ok( in_array( 'max-snippet:-1', $d, true ), 'listener additions do not drop the defaults' );

$GLOBALS['__filters']['sn_seo_robots_directives'] = function ( $dirs ) {
	$dirs[] = 'max-snippet:-1';
	$dirs[] = 'noindex';
	$dirs[] = 'noindex';
	return $dirs;
};
$d = sn_seo_robots_directives();
ok( 1 === count( array_keys( $d, 'noindex', true ) ), 'duplicate directives are collapsed' );
ok( 1 === count( array_keys( $d, 'max-snippet:-1', true ) ), 'a listener re-adding a default does not double it' );
ok( array_values( $d ) === $d, 'the returned array is list-shaped (no key gaps from dedupe)' );

$GLOBALS['__filters']['sn_seo_robots_directives'] = function () { return 'not-an-array'; };
$d = sn_seo_robots_directives();
ok( is_array( $d ) && in_array( 'max-snippet:-1', $d, true ), 'a listener returning a non-array cannot break the emitter' );
unset( $GLOBALS['__filters']['sn_seo_robots_directives'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
