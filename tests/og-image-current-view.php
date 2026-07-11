<?php
/**
 * Standalone regression test: the og:image filter must resolve a post's
 * generated card ONLY on a singular view.
 *
 * Bug (pre-v9.25.4): the `sn_og_image_url` filter body called
 * sn_resolve_og_image_url( $default, get_post() ) on every view. On a
 * NON-singular view (the /notes blog index, /notes tag archives, and search)
 * get_post() returns the loop's first post, which is the sticky "Start here"
 * Note (post 1746). Its 1200x630 card was therefore emitted as the shared
 * og:image for the Notes index and every search result, contradicting the
 * "Notes" og:title/alt printed on those same views.
 *
 * The guarded body sn_og_image_url_for_current_view() returns the site default
 * on a non-singular view and only resolves the queried post's image when
 * is_singular() (single Note/Page, or a static front page). /colophon and the
 * other template Pages are real Pages, so is_singular() keeps their own card.
 *
 * @since plugin v9.25.4
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }

require __DIR__ . '/../inc/og-card-generator.php';

// --- WP + cross-module stubs. Only exercised when we call the guard in. ---
$GLOBALS['__is_singular']    = false;
$GLOBALS['__get_post_calls'] = 0;
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() { return ! empty( $GLOBALS['__is_singular'] ); }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post() { $GLOBALS['__get_post_calls']++; return (object) array( 'ID' => 1746 ); }
}
if ( ! function_exists( 'sn_post_settings_get_og_image_url' ) ) {
	// Per-post override wins first inside sn_resolve_og_image_url(); returning a
	// URL for the sticky Note proves the singular path resolves the queried
	// post's image (and short-circuits before any GD/card work).
	function sn_post_settings_get_og_image_url( $id ) {
		return 1746 === (int) $id ? 'https://site/uploads/sn-og/post-1746.png' : '';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value = '' ) { return $value; }
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "og:image current-view guard - v9.25.4\n\n";

$default = 'https://site/uploads/og-default.png';
$has     = function_exists( 'sn_og_image_url_for_current_view' );
ok( $has, 'the guarded filter body sn_og_image_url_for_current_view() exists' );

// 1) Non-singular view (blog index / tag archive / search): return the site
//    default; NEVER borrow get_post()'s (sticky) card.
$GLOBALS['__is_singular']    = false;
$GLOBALS['__get_post_calls'] = 0;
$out = $has ? sn_og_image_url_for_current_view( $default ) : null;
ok( $has && $default === $out, 'a non-singular view returns the site default (no sticky-card leak onto /notes + search)' );
ok( $has && 0 === $GLOBALS['__get_post_calls'], 'a non-singular view never calls get_post()' );

// 2) Singular view (single Note/Page or static front page): resolve the queried
//    post's image (behavior unchanged from before the guard).
$GLOBALS['__is_singular']    = true;
$GLOBALS['__get_post_calls'] = 0;
$out = $has ? sn_og_image_url_for_current_view( $default ) : null;
ok( $has && 'https://site/uploads/sn-og/post-1746.png' === $out, 'a singular view resolves the queried post image (unchanged)' );
ok( $has && 1 === $GLOBALS['__get_post_calls'], 'a singular view resolves via get_post()' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
