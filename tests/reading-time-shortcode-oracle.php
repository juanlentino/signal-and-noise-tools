<?php
/**
 * Standalone fixture tests for the `[sn_reading_time slug="..."]` existence
 * oracle hardening (plugin v4.14.5).
 *
 * THREAT (T2-oracle cluster — plugin side):
 *   The theme exposes a REST-reachable ability
 *   `signal-and-noise/get-reading-time-for-slug` gated only by the blanket
 *   `read` cap (every subscriber-and-up). It forwards an attacker-controlled
 *   `slug` into `do_shortcode('[sn_reading_time slug="..."]')`, whose handler
 *   lives in this plugin (inc/reading-time.php).
 *
 *   The handler resolves the slug with `get_page_by_path()`, which has NO
 *   post_status filter — it returns drafts, private, pending, future, and
 *   trashed posts by name. Pre-fix the handler then returned a real
 *   "X min read" for ANY resolvable slug, but '' for a slug that resolves to
 *   nothing. The theme turns that into a binary (real minutes vs. its 5-min
 *   fallback), so a logged-in subscriber could distinguish
 *   "slug exists as a non-public post" from "slug does not exist" — a weak
 *   length-proxy oracle that still leaks existence/private-content metadata.
 *
 * FIX:
 *   Gate the slug-resolved post behind `is_post_publicly_viewable()` before
 *   computing a reading time, collapsing the non-public case onto the same
 *   ''-return path as a non-existent slug so the two are indistinguishable.
 *   Mirrors the theme-side get-active-template-structure oracle hardening.
 *
 * COVERAGE:
 *   - A draft slug returns the SAME result ('') as a non-existent slug.
 *   - A private slug returns the SAME result ('') as a non-existent slug.
 *   - A pending and a future slug likewise collapse to '' (status sweep).
 *   - A genuinely published slug STILL returns its real "N min read" (the
 *     guard must not over-block legitimate public reads).
 *
 * Faithfulness note: the `get_page_by_path()` stub returns posts of ANY
 * status by slug, exactly as WordPress core does — without that, the test
 * could not exercise the oracle. The `is_post_publicly_viewable()` stub
 * treats only `publish` as publicly viewable, matching core for these
 * default statuses.
 *
 * @since plugin v4.14.5
 */

// SECURITY: CLI / WP-CLI only. Direct HTTP GET to a test fixture would leak
// internal structure; allow only command-line invocations (matches the guard
// in every other tests/*.php file plus the tests/.htaccess deny rule).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Fake post store ──────────────────────────────────────────────────
// Slug → post object, plus an ID index for get_post( int ). Mirrors the
// minimal shape inc/reading-time.php touches: ID, post_status, post_type,
// post_content.
$GLOBALS['__rt_posts_by_slug'] = array();
$GLOBALS['__rt_posts_by_id']   = array();

function __rt_make_post( $slug, $id, $status, $content ) {
	$p               = new stdClass();
	$p->ID           = $id;
	$p->post_name    = $slug;
	$p->post_status  = $status;
	$p->post_type    = 'page';
	$p->post_content = $content;
	$GLOBALS['__rt_posts_by_slug'][ $slug ] = $p;
	$GLOBALS['__rt_posts_by_id'][ $id ]     = $p;
	return $p;
}

// A public page (~900 words → 4 min) and a set of non-public ones sized so a
// pre-fix leak would surface a clearly non-empty "N min read".
__rt_make_post( 'provenance/over-detection', 101, 'publish', str_repeat( 'alpha ', 900 ) );
__rt_make_post( 'secret/unreleased-expose', 201, 'draft',   str_repeat( 'word ', 1600 ) );
__rt_make_post( 'members/private-note',     202, 'private', str_repeat( 'word ', 1600 ) );
__rt_make_post( 'queue/pending-review',     203, 'pending', str_repeat( 'word ', 1600 ) );
__rt_make_post( 'calendar/scheduled-drop',  204, 'future',  str_repeat( 'word ', 1600 ) );

// ─── WP stubs ─────────────────────────────────────────────────────────
// Capture add_shortcode() callbacks so the test can invoke the handler.
$GLOBALS['__rt_shortcodes'] = array();
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['__rt_shortcodes'][ $tag ] = $callback;
		return true;
	}
}
// Hooks are registered at require time but never fired here — no-op.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { return true; }
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $default;
		}
		return $out;
	}
}

// CORE BEHAVIOUR UNDER TEST: get_page_by_path() resolves by slug regardless
// of post_status — drafts/private/pending/future all come back. This is the
// gap the fix must close downstream.
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
		return $GLOBALS['__rt_posts_by_slug'][ $path ] ?? null;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null ) {
		if ( is_object( $post ) ) {
			return $post;
		}
		if ( is_int( $post ) || ctype_digit( (string) $post ) ) {
			return $GLOBALS['__rt_posts_by_id'][ (int) $post ] ?? null;
		}
		return $GLOBALS['__rt_current_post'] ?? null;
	}
}

// The fix's guard. Only `publish` is publicly viewable among the statuses
// exercised here (matches WP core: draft/private/pending/future are not).
if ( ! function_exists( 'is_post_publicly_viewable' ) ) {
	function is_post_publicly_viewable( $post = null ) {
		$post = get_post( $post );
		return $post && 'publish' === $post->post_status;
	}
}

// Reading-time plumbing: force a cache miss so the real calculator runs.
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta() { return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; } // identity — keep defaults
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $content ) { return $content; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) { return trim( strip_tags( $string ) ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}

// ─── Load the code under test ─────────────────────────────────────────
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
require_once __DIR__ . '/../inc/word-count.php'; // v10.24.0: reading-time's counter dependency (pure module).
require_once __DIR__ . '/../inc/reading-time.php';

$handler = $GLOBALS['__rt_shortcodes']['sn_reading_time'] ?? null;

// ─── Assertion helpers ────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function rt_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n";
	}
}
function rt_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

echo "Reading-time shortcode existence-oracle suite — plugin v4.14.5\n";

rt_true( is_callable( $handler ), 'sn_reading_time shortcode handler registered' );

$render = function ( $slug ) use ( $handler ) {
	return $handler( array( 'slug' => $slug ) );
};

// Baseline: a slug resolving to nothing yields '' (the indistinguishable
// "not found" response the non-public cases must match).
$nonexistent = $render( 'does/not/exist' );
echo "\nTest 1: non-existent slug baseline\n";
rt_eq( '', $nonexistent, 'non-existent slug returns empty string' );

// THE ORACLE REGRESSION: each non-public status must be indistinguishable
// from a non-existent slug. Pre-fix these returned a real "N min read".
echo "\nTest 2: non-public slugs are indistinguishable from non-existent\n";
rt_eq( $nonexistent, $render( 'secret/unreleased-expose' ), 'draft slug == non-existent slug' );
rt_eq( $nonexistent, $render( 'members/private-note' ),     'private slug == non-existent slug' );
rt_eq( $nonexistent, $render( 'queue/pending-review' ),     'pending slug == non-existent slug' );
rt_eq( $nonexistent, $render( 'calendar/scheduled-drop' ),  'future slug == non-existent slug' );

// Guard must NOT over-block: a genuinely public slug still leaks nothing it
// shouldn't, but DOES return its real reading time (legitimate use preserved).
echo "\nTest 3: published slug still returns a real reading time\n";
$published = $render( 'provenance/over-detection' );
rt_true( '' !== $published, 'published slug returns a non-empty string' );
rt_true( false !== strpos( (string) $published, 'min read' ), 'published slug returns "N min read"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
