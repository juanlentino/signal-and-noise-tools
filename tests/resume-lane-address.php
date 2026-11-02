<?php
/**
 * The operations lane is its own address (2026-11-01): on /resume/?lane=operations
 * the canonical and og:url keep the lane, so a phone's share sheet (which copies
 * the canonical) and a link preview (og:url) do not drop it. No hreflang pair
 * there: the Spanish CV has no operations lane.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

$GLOBALS['__f']    = array();
$GLOBALS['__page'] = 'resume';
$GLOBALS['__ops']  = true;
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__f'][ $tag ][ $p ][] = array( $cb, $a ); return true; }
function add_action( $tag, $cb, $p = 10, $a = 1 ) { return add_filter( $tag, $cb, $p, $a ); }
function remove_action() {}
function apply_filters( $tag, $value, ...$args ) {
	$hooks = $GLOBALS['__f'][ $tag ] ?? array();
	ksort( $hooks );
	foreach ( $hooks as $cbs ) {
		foreach ( $cbs as list( $cb, $n ) ) {
			$value = $cb( ...array_slice( array_merge( array( $value ), $args ), 0, max( 1, $n ) ) );
		}
	}
	return $value;
}
function apply_filters_deprecated( $tag, $args ) { return apply_filters( $tag, ...$args ); }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_tag() { return false; }
function is_singular( $t = '' ) { return '' === $t || 'page' === $t; }
function is_page( $s = '' ) { return '' === $s || $GLOBALS['__page'] === $s; }
function sn_setting( $p, $d = null ) { return $d; }
function get_queried_object() { return $GLOBALS['__post']; }
function get_queried_object_id() { return $GLOBALS['__post']->ID; }
function get_permalink( $p ) { return 'https://example.com/' . $GLOBALS['__page'] . '/'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_post_meta( $id, $k = '', $single = false ) { return ''; }
function get_bloginfo( $k = '' ) { return 'Site'; }
function get_the_title( $p = 0 ) { return 'Resume'; }
function sn_post_settings_get_canonical_url( $id ) { return $GLOBALS['__override'] ?? ''; }
function sn_jsonresume_page( $slug ) { return 'resume' === $slug ? $GLOBALS['__post'] : null; }
function sn_resume_doc_get_raw() { return array(); }
function sn_resume_variant_config( $raw, $lane ) { return $GLOBALS['__ops'] ? array( 'pdf_file' => 'x.pdf' ) : null; }
$GLOBALS['__post'] = (object) array( 'ID' => 1184, 'post_type' => 'page' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
require $root . '/inc/seo.php';
require $root . '/inc/resume-page-lanes.php'; // sn_resume_lane_param(), the allowlist
require $root . '/inc/resume-page-lane-render.php';
require $root . '/inc/resume-lane-address.php';
if ( ! function_exists( 'sn_lang_pair_urls' ) ) { function sn_lang_pair_urls() { return array( 'en' => 'https://example.com/resume/', 'es' => 'https://example.com/cv/' ); } }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
$before = $GLOBALS['__f']['wp_head'][2] ?? array();
require $root . '/inc/bilingual-head.php';
$hreflang_hooks = array_slice( $GLOBALS['__f']['wp_head'][2] ?? array(), count( $before ) ); // the hreflang printer bilingual-head.php adds
$head = static function () use ( $hreflang_hooks ) { ob_start(); foreach ( $hreflang_hooks as list( $cb ) ) { $cb(); } return (string) ob_get_clean(); };

$canon = static fn() => apply_filters( 'get_canonical_url', get_permalink( $GLOBALS['__post'] ), $GLOBALS['__post'] );
$og    = static function () { list( , , $url ) = sn_seo_meta_for_current_view(); return $url; };
$pair  = array( 'en' => 'https://example.com/resume/', 'es' => 'https://example.com/cv/' );

$_GET['lane'] = 'operations';
ok( 'https://example.com/resume/?lane=operations' === $canon(), 'the operations lane: the canonical keeps the lane (a share sheet copies it)' );
ok( 'https://example.com/resume/?lane=operations' === $og(), '...and so does og:url (a link preview)' );
ok( array() === apply_filters( 'sn_lang_hreflang_pair', $pair ) && false === strpos( $head(), 'hreflang' ), '...and no hreflang pair in the head: the Spanish CV has no operations lane' );
$_GET['lane'] = 'Operations';
ok( 'https://example.com/resume/?lane=operations' === $canon(), 'the lane is read as the page reads it (sanitize_key), written once, lowercase' );

$_GET['lane'] = 'music';
ok( 'https://example.com/resume/' === $canon() && 'https://example.com/resume/' === $og() && 3 === substr_count( $head(), 'hreflang=' ), 'the music lane: /resume/ and its hreflang trio, as before' );
unset( $_GET['lane'] );
ok( 'https://example.com/resume/' === $canon() && 'https://example.com/resume/' === $og(), 'no lane: /resume/' );
$_GET['lane'] = 'admin" onload="x';
ok( 'https://example.com/resume/' === $canon(), 'an unknown lane is the music lane: nothing a visitor typed reaches the head' );

$_GET['lane']      = 'operations';
$GLOBALS['__ops']  = false;
ok( 'https://example.com/resume/' === $canon() && $pair === apply_filters( 'sn_lang_hreflang_pair', $pair ), 'no operations résumé set up: /resume/, as the page then shows music' );
$GLOBALS['__ops']  = true;
$GLOBALS['__page'] = 'about';
ok( 'https://example.com/about/' === $canon() && 'https://example.com/about/' === $og(), 'another page with ?lane=operations: untouched' );
$GLOBALS['__page']     = 'resume';
$GLOBALS['__override'] = 'https://example.com/elsewhere/';
ok( 'https://example.com/elsewhere/' === $canon(), 'an owner canonical override wins, untouched' );
unset( $GLOBALS['__override'], $_GET['lane'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
