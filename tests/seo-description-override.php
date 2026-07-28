<?php
/**
 * Tests for sn_seo_resolve_singular_description() — the meta-description chain
 * that drives <meta name="description">, og:description, and twitter:description.
 *
 * Proves (a) the extracted resolver returns the correct value for each branch —
 * per-post override, excerpt fallback (stripped), theme-route filter (e.g.
 * /about), and empty — and (b) that it is output-identical to the pre-v9.7.0
 * inline chain in sn_seo_meta_for_current_view(), and (c) that the view now
 * DELEGATES to the resolver (single source of truth). (plugin v9.7.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__override'] = ''; // _sn_meta_description per-post override
$GLOBALS['__filter']   = ''; // sn_seo_singular_description theme-route filter yield
$GLOBALS['__qo']       = null; // get_queried_object() for the wire-through group
function sn_post_settings_get_description( $id ) { return $GLOBALS['__override']; }
// v9.84.0: sn_seo_route_meta is applied via apply_filters_deprecated now —
// passthrough stub (no listener in this fixture, same as apply_filters).
function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) {
	return apply_filters( $tag, ...$args );
}
function apply_filters( $tag, $value, $post = null ) {
	if ( 'sn_seo_singular_description' === $tag ) { return $GLOBALS['__filter']; }
	return $value; // sn_seo_route_meta (null) + sn_seo_singular_title ('') fall through
}
// Real-ish tag strip so "excerpt is stripped" is an observable behavior.
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); }

// WP conditionals + helpers for the view-delegation group. Hoisted at compile
// time; only CALLED after the resolver groups, and never during the require
// (seo.php's hook registrations are no-op'd below).
function is_front_page() { return false; }
function is_page( $p = '' ) { return false; }
function is_home() { return false; }
function is_singular( $t = '' ) { return true; }
function get_queried_object() { return $GLOBALS['__qo']; }
function get_permalink( $p ) { return 'https://example.test/p/'; }
function get_the_title( $p ) { return 'Page Title'; }
function get_bloginfo( $k = '' ) { return 'Fallback Site'; }
function sn_setting( $k, $d = '' ) { return 'Signal & Noise'; }

// seo.php registers many hooks on load — stub the hook API so the require is inert.
function add_action() {} function add_filter() {}
require __DIR__ . '/../inc/seo.php';

/**
 * Faithful copy of the pre-extraction inline chain (old
 * sn_seo_meta_for_current_view() singular branch). The oracle the resolver must
 * match byte-for-byte — extraction is a refactor, not a behavior change.
 */
function __old_inline_description( $post ) {
	$description = '';
	$override    = function_exists( 'sn_post_settings_get_description' )
		? sn_post_settings_get_description( $post->ID )
		: '';
	if ( '' !== $override ) {
		$description = $override;
	} elseif ( ! empty( $post->post_excerpt ) ) {
		$description = wp_strip_all_tags( $post->post_excerpt );
	}
	if ( '' === $description ) {
		$description = (string) apply_filters( 'sn_seo_singular_description', '', $post );
	}
	return $description;
}

$mk = function ( $excerpt ) { return (object) array( 'ID' => 7, 'post_excerpt' => $excerpt ); };

echo "Group: precedence — resolver value per branch\n";
// override present wins over excerpt AND theme filter.
$GLOBALS['__override'] = 'Hand-written meta description.';
$GLOBALS['__filter']   = 'Theme route copy.';
ok( 'Hand-written meta description.' === sn_seo_resolve_singular_description( $mk( 'The auto excerpt.' ) ), 'override wins over excerpt + filter' );

// excerpt fallback (override empty) wins over the theme filter, and is stripped.
$GLOBALS['__override'] = '';
ok( 'Excerpt body.' === sn_seo_resolve_singular_description( $mk( '<b>Excerpt</b> body.' ) ), 'excerpt fallback (stripped) wins when no override' );

// theme-route filter fallback (e.g. /about — no override, no excerpt).
ok( 'Theme route copy.' === sn_seo_resolve_singular_description( $mk( '' ) ), 'theme-route filter fallback when no override + no excerpt' );

// empty — nothing anywhere yields ''.
$GLOBALS['__filter'] = '';
ok( '' === sn_seo_resolve_singular_description( $mk( '' ) ), 'empty when override, excerpt, and filter are all empty' );

echo "\nGroup: identical to the pre-v9.7.0 inline chain (oracle)\n";
$cases = array(
	array( 'ov' => 'Override', 'ex' => 'Excerpt',   'fl' => 'Filter' ),
	array( 'ov' => '',         'ex' => 'Excerpt',   'fl' => 'Filter' ),
	array( 'ov' => '',         'ex' => '',          'fl' => 'Filter' ),
	array( 'ov' => '',         'ex' => '',          'fl' => '' ),
	array( 'ov' => 'Override', 'ex' => '',          'fl' => '' ),
	array( 'ov' => '',         'ex' => '0',         'fl' => 'Filter' ), // empty('0')===true → skips excerpt, hits filter
	array( 'ov' => '<i>Raw</i>', 'ex' => 'Excerpt', 'fl' => 'Filter' ), // override returned RAW (unlike the title path, which strips)
	array( 'ov' => '',         'ex' => '<b>E</b>',  'fl' => 'Filter' ), // excerpt IS stripped, not the filter
);
foreach ( $cases as $c ) {
	$GLOBALS['__override'] = $c['ov'];
	$GLOBALS['__filter']   = $c['fl'];
	$post = $mk( $c['ex'] );
	ok(
		sn_seo_resolve_singular_description( $post ) === __old_inline_description( $post ),
		"resolver == old inline chain [ov='{$c['ov']}' ex='{$c['ex']}' fl='{$c['fl']}']"
	);
}

echo "\nGroup: the view delegates to the resolver (single source of truth)\n";
$GLOBALS['__override'] = 'Delegated description.';
$GLOBALS['__filter']   = '';
$post            = $mk( 'an excerpt the override outranks' );
$GLOBALS['__qo'] = $post;
list( , $view_desc, ) = sn_seo_meta_for_current_view();
ok( $view_desc === sn_seo_resolve_singular_description( $post ), 'view description === resolver output (delegation)' );
ok( 'Delegated description.' === $view_desc, 'view surfaces the resolved override description' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
