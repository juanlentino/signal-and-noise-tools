<?php
/**
 * Tests for the OG/Twitter image-alt helper (v4.8.1, T6).
 *
 * sn_seo_og_image_alt( $title ) resolves the alt text for og:image:alt /
 * twitter:image:alt with the fallback chain:
 *   featured-image _wp_attachment_image_alt → $title → site name.
 *
 * Pure-PHP CLI harness. inc/seo.php registers wp_head/template_redirect/etc.
 * actions at parse time, so we stub add_action/add_filter as no-ops and stub
 * the WP functions the helper itself calls. Mutable $GLOBALS drive each case.
 *
 * @since plugin v4.8.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Mutable test state ───────────────────────────────────────────────
$GLOBALS['__og'] = array(
	'is_singular' => false,
	'thumb_id'    => 0,
	'alt'         => '',
	'site_name'   => 'Signal & Noise',
);

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		return (bool) $GLOBALS['__og']['is_singular'];
	}
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		$o = new stdClass();
		$o->ID = 5;
		return $o;
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		return (int) $GLOBALS['__og']['thumb_id'];
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		if ( '_wp_attachment_image_alt' === $key ) {
			return $GLOBALS['__og']['alt'];
		}
		return '';
	}
}
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = '' ) {
		if ( 'identity.site_name' === $key ) {
			return $GLOBALS['__og']['site_name'];
		}
		return $default;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what ) {
		return 'Example';
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	// Mirror WP's esc_attr just enough to verify it escapes a double-quote.
	function esc_attr( $s ) {
		return str_replace(
			array( '&', '"', "'", '<', '>' ),
			array( '&amp;', '&quot;', '&#039;', '&lt;', '&gt;' ),
			(string) $s
		);
	}
}

// Functions seo.php references at parse/registration time but the helper
// under test does not exercise — declare no-op/passthrough stubs so the
// require doesn't fatal.
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return (string) $u; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return (string) $s; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed() { return false; }
}
if ( ! function_exists( 'the_seo_framework' ) ) {
	// Intentionally NOT defined so seo.php's TSF-dormant branches are live.
}

require_once __DIR__ . '/../inc/seo.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function og_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function og_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "seo-og image-alt helper suite — plugin v4.8.1\n";

og_true( function_exists( 'sn_seo_og_image_alt' ), 'sn_seo_og_image_alt() is defined' );

// 1. Featured-image alt present → returns the featured alt (highest priority).
$GLOBALS['__og']['is_singular'] = true;
$GLOBALS['__og']['thumb_id']    = 42;
$GLOBALS['__og']['alt']         = 'A photo of the studio';
og_eq( 'A photo of the studio', sn_seo_og_image_alt( 'Some Title' ), 'featured-image alt wins' );

// 2. No thumbnail → returns $title.
$GLOBALS['__og']['thumb_id'] = 0;
$GLOBALS['__og']['alt']      = '';
og_eq( 'Some Title', sn_seo_og_image_alt( 'Some Title' ), 'no thumbnail → falls back to $title' );

// 2b. Thumbnail with empty/whitespace alt → falls back to $title.
$GLOBALS['__og']['thumb_id'] = 42;
$GLOBALS['__og']['alt']      = '   ';
og_eq( 'Some Title', sn_seo_og_image_alt( 'Some Title' ), 'whitespace-only featured alt → falls back to $title' );

// 3. Empty title (and no usable featured alt) → returns site name.
$GLOBALS['__og']['thumb_id'] = 0;
$GLOBALS['__og']['alt']      = '';
og_eq( 'Signal & Noise', sn_seo_og_image_alt( '' ), 'empty title → falls back to site name' );

// 4. Non-singular view → skips featured lookup, uses $title.
$GLOBALS['__og']['is_singular'] = false;
$GLOBALS['__og']['thumb_id']    = 42;
$GLOBALS['__og']['alt']         = 'ignored on non-singular';
og_eq( 'Home Title', sn_seo_og_image_alt( 'Home Title' ), 'non-singular → featured lookup skipped, uses $title' );

// 5. esc_attr escapes a double-quote (the emitted <meta> content="…" attribute).
$alt_with_quote = sn_seo_og_image_alt( 'He said "hi"' );
og_eq( 'He said "hi"', $alt_with_quote, 'helper returns raw alt (escaping happens at emit)' );
og_true( false !== strpos( esc_attr( $alt_with_quote ), '&quot;' ), 'esc_attr( alt ) escapes the double-quote for the meta attribute' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
