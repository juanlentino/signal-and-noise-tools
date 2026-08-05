<?php
/**
 * Tests for sn_seo_resolve_singular_title() — the title chain that drives
 * <title>, og:title, and twitter:title. (plugin v9.3.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__override'] = ''; // _sn_seo_title
$GLOBALS['__filter']   = ''; // sn_seo_singular_title filter yield
function sn_post_settings_get_seo_title( $id ) { return $GLOBALS['__override']; }
function apply_filters( $tag, $value, $post = null ) {
	return ( 'sn_seo_singular_title' === $tag ) ? $GLOBALS['__filter'] : $value;
}
function get_the_title( $post ) { return 'Real Page Title'; }
function wp_strip_all_tags( $s ) { return $s; }
function get_bloginfo( $k ) { return 'Fallback Site'; }
function sn_setting( $k, $d = '' ) { return 'Signal & Noise'; }

// seo.php registers many hooks on load — stub the hook API so the require is inert.
function add_action() {} function add_filter() {}
require __DIR__ . '/../inc/seo.php';

$post   = (object) array( 'ID' => 3 );
$suffix = ' — Signal & Noise';

echo "Group: precedence\n";
$GLOBALS['__override'] = 'Manual SEO Title';
$GLOBALS['__filter']   = 'Theme Title';
ok( 'Manual SEO Title' . $suffix === sn_seo_resolve_singular_title( $post ), 'override wins' );

$GLOBALS['__override'] = '';
ok( 'Theme Title' . $suffix === sn_seo_resolve_singular_title( $post ), 'theme fallback wins when no override' );

$GLOBALS['__filter'] = '';
ok( 'Real Page Title' . $suffix === sn_seo_resolve_singular_title( $post ), 'derived title when neither set' );

echo "\nGroup: suffix always applied\n";
$GLOBALS['__override'] = 'X';
ok( false !== strpos( sn_seo_resolve_singular_title( $post ), $suffix ), 'site-name suffix present with an override' );

// ── The 404 title uses the SAME separator as every other page ──
// The em-dash in a document title is the site-wide TITLE SEPARATOR, not prose, and is
// exempt from the no-em-dash house style for exactly that reason: it moves on every page
// or on none. The 404 branch exists specifically to stop WP's default hyphen fallback
// from making /404 the one page with a different shape (see the comment above it in
// inc/seo.php). The v10.48.2 admin-copy sweep changed it to "Page not found. Site" while
// leaving sn_seo_title()'s ' — ' intact, producing exactly the divergence that branch was
// written to prevent. Source assertions, per tests/keyboard-nav.php. Nowdoc needles so
// the $base/$site in the pattern are literal, never interpolated.
$sn_seo_src = file_get_contents( __DIR__ . '/../inc/seo.php' );

$sn_site_needle = <<<'SNSEP'
$base . ' — ' . $site
SNSEP;
$sn_404_needle = <<<'SN404'
'Page not found — ' . get_bloginfo
SN404;
$sn_404_swept = <<<'SNBAD'
'Page not found. ' . get_bloginfo
SNBAD;

ok( false !== strpos( $sn_seo_src, $sn_site_needle ), 'sn_seo_title() still joins with the site-wide " — " separator' );
ok( false !== strpos( $sn_seo_src, $sn_404_needle ), 'the 404 title uses that SAME separator, so /404 is not the one page with a different shape' );
ok( false === strpos( $sn_seo_src, $sn_404_swept ), 'the 404 title is not the swept "Page not found. " period form' );


echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
