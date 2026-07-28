<?php
/**
 * Tests for sn_seo_description_for_post() — the per-post mirror of
 * sn_seo_meta_for_current_view()'s description routing, keyed on post identity
 * so an arbitrary Page can be evaluated OUTSIDE its own request (the Analytics
 * descriptionless-Pages recommendation).
 *
 * The front page, /notes, and /provenance take their description from SEO
 * settings (seo_copy.*_description), NOT the Page excerpt; every other Page uses
 * sn_seo_resolve_singular_description(). This is the source of the v9.22.3 fix:
 * the recommendation used the excerpt path for ALL pages, which false-positived
 * the (setting-described) front page.
 *
 * @since plugin v9.22.3
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__settings'] = array(); // seo_copy.* settings map
$GLOBALS['__opts']     = array(); // get_option map (page_on_front)
$GLOBALS['__override'] = '';       // _sn_meta_description per-post override
$GLOBALS['__filter']   = '';       // sn_seo_singular_description theme-route filter

function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }
function get_option( $key, $default = false ) { return $GLOBALS['__opts'][ $key ] ?? $default; }
function sn_post_settings_get_description( $id ) { return $GLOBALS['__override']; }
function apply_filters( $tag, $value, $post = null ) {
	if ( 'sn_seo_singular_description' === $tag ) { return $GLOBALS['__filter']; }
	return $value;
}
// v9.84.0 ladder: sn_seo_route_meta moves to apply_filters_deprecated.
$GLOBALS['__deprecated_calls'] = array();
function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) {
	$GLOBALS['__deprecated_calls'][] = array( 'tag' => $tag, 'version' => $version, 'message' => $message );
	return apply_filters( $tag, ...$args );
}
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); }
// seo.php registers hooks on load — stub the hook API so the require is inert.
function add_action() {} function add_filter() {}

require __DIR__ . '/../inc/seo.php';

$mk = function ( $id, $slug, $excerpt = '' ) {
	return (object) array( 'ID' => $id, 'post_name' => $slug, 'post_excerpt' => $excerpt );
};

echo "Group: setting-driven routes read the SEO setting, not the excerpt\n";
$GLOBALS['__opts']     = array( 'page_on_front' => 383 );
$GLOBALS['__settings'] = array(
	'seo_copy.home_description'       => 'Home from setting.',
	'seo_copy.notes_description'      => 'Notes from setting.',
	'seo_copy.provenance_description' => 'Provenance from setting.',
);

// Front page: identified by ID === page_on_front, ignores the (empty) excerpt.
ok( 'Home from setting.' === sn_seo_description_for_post( $mk( 383, 'home', '' ) ), 'front page reads seo_copy.home_description' );
// Even if the front page has an excerpt, the setting still wins (mirrors the view).
ok( 'Home from setting.' === sn_seo_description_for_post( $mk( 383, 'home', 'Ignored excerpt.' ) ), 'front page setting wins over a page excerpt' );
// /notes and /provenance by slug.
ok( 'Notes from setting.' === sn_seo_description_for_post( $mk( 1489, 'notes', 'Ignored.' ) ), '/notes reads seo_copy.notes_description' );
ok( 'Provenance from setting.' === sn_seo_description_for_post( $mk( 1490, 'provenance', 'Ignored.' ) ), '/provenance reads seo_copy.provenance_description' );

echo "\nGroup: a setting-driven route with an EMPTY setting resolves empty (correctly flaggable)\n";
$GLOBALS['__settings']['seo_copy.home_description'] = '';
ok( '' === sn_seo_description_for_post( $mk( 383, 'home', 'Has an excerpt.' ) ), 'front page with empty home setting resolves empty (excerpt does NOT rescue it — matches the view)' );

echo "\nGroup: front-page identity takes precedence over slug\n";
$GLOBALS['__settings']['seo_copy.home_description'] = 'Home wins.';
ok( 'Home wins.' === sn_seo_description_for_post( $mk( 383, 'notes', '' ) ), 'a page that is BOTH the front page and slug=notes resolves as the front page' );

echo "\nGroup: generic pages fall through to the excerpt resolver\n";
$GLOBALS['__opts']     = array( 'page_on_front' => 383 );
$GLOBALS['__settings'] = array();
$GLOBALS['__override'] = '';
$GLOBALS['__filter']   = '';
// Generic page with an excerpt → excerpt (stripped).
ok( 'A real excerpt.' === sn_seo_description_for_post( $mk( 999, 'services', '<b>A real</b> excerpt.' ) ), 'generic page uses the excerpt resolver (stripped)' );
// Generic page, no excerpt, but a theme-route filter yields (e.g. /colophon).
$GLOBALS['__filter'] = 'Colophon route copy.';
ok( 'Colophon route copy.' === sn_seo_description_for_post( $mk( 1690, 'colophon', '' ) ), 'generic page with no excerpt falls to the theme-route filter' );
// Generic page with a per-post override wins.
$GLOBALS['__override'] = 'Per-post override.';
ok( 'Per-post override.' === sn_seo_description_for_post( $mk( 999, 'services', 'excerpt' ) ), 'generic page honors the per-post override' );

echo "\nGroup: no static front page + guards\n";
$GLOBALS['__opts']     = array(); // page_on_front unset -> 0
$GLOBALS['__settings'] = array();
$GLOBALS['__override'] = '';
$GLOBALS['__filter']   = '';
ok( '' === sn_seo_description_for_post( $mk( 383, 'home', '' ) ), 'with no static front page, a page named home is generic (excerpt path), empty here' );
ok( '' === sn_seo_description_for_post( null ), 'non-object returns empty string' );

echo "\nGroup: v10.0.0 — the sn_seo_route_meta seam is removed\n";
// The pages-to-CMS flip made every former virtual route a real Page, so the
// postless-route seam had nothing left to answer. Pinned by absence: a
// re-introduction would resurrect a seam with no producer.
ok( ! function_exists( 'sn_seo_route_meta' ), 'sn_seo_route_meta() no longer exists' );
$sn_seo_src = (string) file_get_contents( __DIR__ . '/../inc/seo.php' );
ok( false === strpos( $sn_seo_src, "apply_filters( 'sn_seo_route_meta'" ), 'and the filter is not applied anywhere in seo.php' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
