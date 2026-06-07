<?php
/**
 * Tests for the RSS feed conditional-GET decision helper (v4.8.1, T9).
 *
 * sn_seo_feed_conditional_response( $modified_gmt, $ims, $inm, $etag ) is a
 * pure function returning the response the template_redirect wrapper should
 * emit: 304 when the client's validator is still fresh, else 200 + the
 * Last-Modified/ETag headers. The wrapper (header emission, is_feed gate,
 * status_header/exit) can't run headlessly, so this locks the decision logic.
 *
 * Also asserts the new action is registered on template_redirect at a priority
 * > 1 (so it never pre-empts sn_rss_tracker_capture at priority 1).
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

// ─── Action registry (capture template_redirect registrations) ────────
$GLOBALS['__actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__actions'][ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

// ─── WP stubs seo.php references at parse/registration time ───────────
if ( ! function_exists( 'sn_setting' ) ) {
	function sn_setting( $key, $default = '' ) { return $default; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $what ) { return 'Example'; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return (string) $s; }
}
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
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $t = '' ) { return false; }
}
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() { return false; }
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home() { return false; }
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed() { return false; }
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() { return null; }
}

// Real class names so sn_seo_feed_etag()'s `instanceof WP_Term` /
// `instanceof WP_Post_Type` checks resolve. Minimal — only the props the
// helper reads (term_id/taxonomy, name).
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id;
		public $taxonomy;
	}
}
if ( ! class_exists( 'WP_Post_Type' ) ) {
	class WP_Post_Type {
		public $name;
	}
}

require_once __DIR__ . '/../inc/seo.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function fc_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function fc_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "feed conditional-GET helper suite — plugin v4.8.1\n";

fc_true( function_exists( 'sn_seo_feed_conditional_response' ), 'sn_seo_feed_conditional_response() is defined' );

$modified = strtotime( '2026-06-01 12:00:00 UTC' );
$etag     = '"' . md5( gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' ) . '"';
$expected_http = gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT';

// 1. IMS at-or-after modified → 304.
$r = sn_seo_feed_conditional_response( $modified, gmdate( 'D, d M Y H:i:s', $modified + 60 ) . ' GMT', '', $etag );
fc_eq( 304, $r['status'] ?? null, 'IMS >= modified → 304' );

// 1b. IMS exactly equal → 304 (>=).
$r = sn_seo_feed_conditional_response( $modified, gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT', '', $etag );
fc_eq( 304, $r['status'] ?? null, 'IMS == modified → 304' );

// 2. IMS before modified → 200 + correct Last-Modified.
$r = sn_seo_feed_conditional_response( $modified, gmdate( 'D, d M Y H:i:s', $modified - 3600 ) . ' GMT', '', $etag );
fc_eq( 200, $r['status'] ?? null, 'IMS < modified → 200' );
fc_eq( $expected_http, $r['headers']['Last-Modified'] ?? null, '200 carries correct Last-Modified header' );
fc_eq( $etag, $r['headers']['ETag'] ?? null, '200 carries the ETag header' );

// 3. Matching INM ETag → 304 (ETag wins regardless of IMS).
$r = sn_seo_feed_conditional_response( $modified, '', $etag, $etag );
fc_eq( 304, $r['status'] ?? null, 'matching INM ETag → 304' );

// 3b. Mismatched INM ETag, no IMS → 200.
$r = sn_seo_feed_conditional_response( $modified, '', '"different"', $etag );
fc_eq( 200, $r['status'] ?? null, 'mismatched INM ETag, no IMS → 200' );

// 4. No conditionals → 200 + both headers.
$r = sn_seo_feed_conditional_response( $modified, '', '', $etag );
fc_eq( 200, $r['status'] ?? null, 'no conditionals → 200' );
fc_eq( $expected_http, $r['headers']['Last-Modified'] ?? null, '200 (no conditionals) carries Last-Modified' );
fc_eq( $etag, $r['headers']['ETag'] ?? null, '200 (no conditionals) carries ETag' );

// 5. modified_gmt = 0 → null (can't compute a validator).
fc_eq( null, sn_seo_feed_conditional_response( 0, '', '', $etag ), 'modified_gmt = 0 → null' );

// ─── Per-feed ETag uniqueness (v4.8.1 adversarial fix 4) ──────────────
// The wrapper previously built $etag from md5($last_modified_http) ALONE, so
// two different feeds (e.g. /feed/ and /notes/feed/) that share a build
// timestamp collided on an identical ETag — a 304 false-positive across feeds.
// sn_seo_feed_etag() folds feed identity in: taxonomy:term_id for a WP_Term,
// post_type:name for a WP_Post_Type, '' for the main feed.
echo "\nPer-feed ETag uniqueness (sn_seo_feed_etag)\n";
fc_true( function_exists( 'sn_seo_feed_etag' ), 'sn_seo_feed_etag() is defined' );

$lm = gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT';

// Two distinct taxonomy-term feeds sharing the SAME build timestamp.
$term_a            = new WP_Term();
$term_a->term_id  = 7;
$term_a->taxonomy = 'category';
$term_b            = new WP_Term();
$term_b->term_id  = 12;
$term_b->taxonomy = 'category';

$etag_a = sn_seo_feed_etag( $lm, $term_a );
$etag_b = sn_seo_feed_etag( $lm, $term_b );
fc_true( $etag_a !== $etag_b, 'two different terms, same timestamp → DIFFERENT ETags' );

// A post-type feed and the main feed also differ from each other + the terms.
$pt        = new WP_Post_Type();
$pt->name  = 'post';
$etag_pt   = sn_seo_feed_etag( $lm, $pt );
$etag_main = sn_seo_feed_etag( $lm, null );
fc_true( $etag_pt !== $etag_main, 'post-type feed vs main feed → DIFFERENT ETags' );
fc_true( $etag_pt !== $etag_a && $etag_main !== $etag_a, 'post-type + main differ from a term feed' );

// Same identity + same timestamp → STABLE ETag (no request-varying input).
$term_a2            = new WP_Term();
$term_a2->term_id  = 7;
$term_a2->taxonomy = 'category';
fc_eq( $etag_a, sn_seo_feed_etag( $lm, $term_a2 ), 'same term + same timestamp → STABLE ETag across requests' );
fc_eq( $etag_main, sn_seo_feed_etag( $lm, null ), 'main feed ETag is stable across requests' );

// A different timestamp changes the ETag for the same feed (validator still tracks builds).
$lm2 = gmdate( 'D, d M Y H:i:s', $modified + 3600 ) . ' GMT';
fc_true( $etag_a !== sn_seo_feed_etag( $lm2, $term_a ), 'same term, different timestamp → different ETag (still validates builds)' );

// ETag is a quoted strong validator.
fc_true( '"' === $etag_a[0] && '"' === substr( $etag_a, -1 ), 'ETag is double-quoted (strong validator)' );

// ─── Action registration ──────────────────────────────────────────────
echo "\nAction registration: feed conditional-GET runs after RSS tracking (priority > 1)\n";
$found = false;
$priority = null;
foreach ( $GLOBALS['__actions']['template_redirect'] ?? array() as $entry ) {
	// The singular handler + the new feed handler are both closures; we can't
	// match by name. Assert there's at least one template_redirect handler at
	// priority > 1 (the feed handler), which never pre-empts the priority-1
	// sn_rss_tracker_capture.
	if ( $entry['priority'] > 1 ) {
		$found = true;
		$priority = $entry['priority'];
	}
}
fc_true( $found, 'a template_redirect handler is registered at priority > 1' );
fc_true( null !== $priority && $priority >= 10, 'feed handler priority >= 10 (runs after priority-1 RSS tracker)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
