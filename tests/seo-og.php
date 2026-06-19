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
	'post_title'  => 'Some Title', // bare queried-object title (no site suffix)
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
if ( ! function_exists( 'get_the_title' ) ) {
	// Returns the BARE post title (no " — Site Name" suffix), as WP does.
	function get_the_title( $post = null ) {
		return (string) $GLOBALS['__og']['post_title'];
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
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

// ─── Article-meta stubs (sn_seo_article_meta) ─────────────────────────
$GLOBALS['__art'] = array(
	'published' => '2026-06-10T09:00:00+00:00',
	'modified'  => '2026-06-12T10:00:00+00:00',
	'terms'     => array( 'category' => array( 'Analysis', 'Secondary' ), 'post_tag' => array( 'provenance', 'audio' ) ),
);
if ( ! class_exists( 'WP_Error_Stub' ) ) {
	class WP_Error_Stub {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) { return $x instanceof WP_Error_Stub; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( $format, $gmt, $post ) { return $GLOBALS['__art']['published']; }
}
if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( $format, $gmt, $post ) { return $GLOBALS['__art']['modified']; }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
		return $GLOBALS['__art']['terms'][ $taxonomy ] ?? array();
	}
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

// 3. Empty title + empty bare post title (and no usable featured alt) →
// returns site name. On singular the helper now reads the bare queried-object
// title, so we blank that too to exercise the final site-name fallback.
$GLOBALS['__og']['thumb_id']   = 0;
$GLOBALS['__og']['alt']        = '';
$GLOBALS['__og']['post_title'] = '';
og_eq( 'Signal & Noise', sn_seo_og_image_alt( '' ), 'empty title + empty bare post title → falls back to site name' );
$GLOBALS['__og']['post_title'] = 'Some Title'; // restore

// 3b. REGRESSION (v4.8.1 adversarial fix 3): on a singular view with an empty
// featured alt, the helper must fall back to the BARE post title — NOT the
// passed $title, which carries the " — Site Name" suffix from
// sn_seo_meta_for_current_view(). Image alt should describe the image/subject,
// not the SERP title string.
$GLOBALS['__og']['is_singular'] = true;
$GLOBALS['__og']['thumb_id']    = 0;
$GLOBALS['__og']['alt']         = '';
$GLOBALS['__og']['post_title']  = 'Some Title';
og_eq( 'Some Title', sn_seo_og_image_alt( 'Some Title — Site' ), 'singular empty alt → bare post title, suffix stripped (NOT "Some Title — Site")' );

// 4. Non-singular view → skips featured lookup, uses $title.
$GLOBALS['__og']['is_singular'] = false;
$GLOBALS['__og']['thumb_id']    = 42;
$GLOBALS['__og']['alt']         = 'ignored on non-singular';
og_eq( 'Home Title', sn_seo_og_image_alt( 'Home Title' ), 'non-singular → featured lookup skipped, uses $title' );

// 5. esc_attr escapes a double-quote (the emitted <meta> content="…" attribute).
$alt_with_quote = sn_seo_og_image_alt( 'He said "hi"' );
og_eq( 'He said "hi"', $alt_with_quote, 'helper returns raw alt (escaping happens at emit)' );
og_true( false !== strpos( esc_attr( $alt_with_quote ), '&quot;' ), 'esc_attr( alt ) escapes the double-quote for the meta attribute' );

// ─── sn_seo_article_meta() — A3 article:author + richer article:section/tag ───
og_true( function_exists( 'sn_seo_article_meta' ), 'sn_seo_article_meta() is defined' );

$post = (object) array( 'ID' => 7 );

// Full set: published + modified + author + section + tags.
ob_start();
sn_seo_article_meta( $post );
$out = ob_get_clean();
og_true( strpos( $out, '<meta property="article:published_time" content="2026-06-10T09:00:00+00:00">' ) !== false, 'emits article:published_time' );
og_true( strpos( $out, '<meta property="article:modified_time" content="2026-06-12T10:00:00+00:00">' ) !== false, 'emits article:modified_time' );
og_true( strpos( $out, '<meta property="article:author" content="https://example.test/">' ) !== false, 'A3: article:author = home_url("/") (matches JSON-LD Person.url/@id)' );
og_true( strpos( $out, '<meta property="article:section" content="Analysis">' ) !== false, 'article:section = FIRST category only (mirrors JSON-LD articleSection)' );
og_true( strpos( $out, 'content="Secondary"' ) === false, 'article:section does NOT emit the second category' );
og_eq( 2, substr_count( $out, '<meta property="article:tag"' ), 'article:tag = one repeated meta per tag (not comma-joined)' );
og_true( strpos( $out, '<meta property="article:tag" content="provenance">' ) !== false && strpos( $out, '<meta property="article:tag" content="audio">' ) !== false, 'both tags emitted' );

// Robustness: WP_Error from wp_get_post_terms → no section/tag, no fatal (triple guard).
$GLOBALS['__art']['terms'] = array( 'category' => new WP_Error_Stub(), 'post_tag' => new WP_Error_Stub() );
ob_start();
sn_seo_article_meta( $post );
$out2 = ob_get_clean();
og_true( strpos( $out2, 'article:section' ) === false && strpos( $out2, 'article:tag' ) === false, 'WP_Error terms degrade to no section/tag (no fatal)' );
og_true( strpos( $out2, '<meta property="article:author"' ) !== false, 'author still emits when terms error (independent)' );

// Empty terms → no section/tag.
$GLOBALS['__art']['terms'] = array( 'category' => array(), 'post_tag' => array() );
ob_start();
sn_seo_article_meta( $post );
$out3 = ob_get_clean();
og_true( strpos( $out3, 'article:section' ) === false && strpos( $out3, 'article:tag' ) === false, 'empty terms → no section/tag' );

// ─── v6.24.0: sn_seo_image_dimensions() — declare the image's ACTUAL size ──
// Generated /sn-og/ cards resolve to the generator's constant with NO filesystem
// read (the live bug: cards are 1200 wide but the stored setting declared 1000).
if ( ! defined( 'SN_OG_DIRNAME' ) ) { define( 'SN_OG_DIRNAME', 'sn-og' ); }
if ( ! defined( 'SN_OG_WIDTH' ) )   { define( 'SN_OG_WIDTH', 1200 ); }
if ( ! defined( 'SN_OG_HEIGHT' ) )  { define( 'SN_OG_HEIGHT', 630 ); }
if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) { return 'https://example.test/wp-content' . $path; }
}
og_eq( array( 1200, 630 ), sn_seo_image_dimensions( 'https://example.test/wp-content/uploads/sn-og/post-383.png?v=9' ), 'generated /sn-og/ card → generator constant 1200x630 (cache-buster ignored)' );
og_eq( null, sn_seo_image_dimensions( 'https://images.example.org/remote.png' ), 'remote (non-local) image → null (caller falls back, never guesses a size)' );
og_eq( '', sn_seo_local_image_path( 'https://images.example.org/remote.png' ), 'off-site URL maps to no local path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
