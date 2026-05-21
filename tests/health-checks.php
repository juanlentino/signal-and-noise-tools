<?php
/**
 * Standalone fixture tests for inc/health-checks.php (v3.5.0).
 *
 * The DB-touching paths (4 main check functions) are NOT covered
 * here — they require a live wpdb fixture and are exercised via the
 * "Run scan" button in the admin. We test the pure logic that
 * underpins them: the regex extractors + the link-status caching
 * behavior.
 *
 * @since plugin v3.5.0
 */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) { return 'https://juanlentino.com' . $path; }
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path ) { return basename( $path ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p ) { return 'https://juanlentino.com/wp-admin/' . $p; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return "https://juanlentino.com/?p=$id"; }
}

// Transient stubs for link-status cache.
$GLOBALS['__test_transients'] = array();
function get_transient( $key ) {
	return isset( $GLOBALS['__test_transients'][ $key ] ) ? $GLOBALS['__test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['__test_transients'][ $key ] = $value;
	return true;
}

// wp_remote_head + wp_remote_get + helpers — tests inject responses via globals.
$GLOBALS['__test_http_responses']     = array();
$GLOBALS['__test_http_responses_get'] = array();
function wp_remote_head( $url, $args = array() ) { return sn_test_remote( $url, 'HEAD' ); }
function wp_remote_get( $url, $args = array() )  { return sn_test_remote( $url, 'GET' ); }
function sn_test_remote( $url, $method ) {
	if ( 'GET' === $method && isset( $GLOBALS['__test_http_responses_get'][ $url ] ) ) {
		return $GLOBALS['__test_http_responses_get'][ $url ];
	}
	if ( isset( $GLOBALS['__test_http_responses'][ $url ] ) ) {
		return $GLOBALS['__test_http_responses'][ $url ];
	}
	return array( 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_response_code( $resp ) {
	if ( is_array( $resp ) && isset( $resp['response']['code'] ) ) {
		return $resp['response']['code'];
	}
	return 0;
}

class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require_once __DIR__ . '/../inc/health-checks.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function hc_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function hc_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Test 1: extract_inline_imgs_without_alt — basic cases ───────────
echo "\nTest 1: sn_health_extract_inline_imgs_without_alt\n";
hc_eq( array(), sn_health_extract_inline_imgs_without_alt( '' ), 'empty content → empty array' );
hc_eq( array(), sn_health_extract_inline_imgs_without_alt( '<p>no images here</p>' ), 'no img tags → empty array' );

$with_alt = '<p><img src="https://x/y.jpg" alt="A nice photo"></p>';
hc_eq( array(), sn_health_extract_inline_imgs_without_alt( $with_alt ), 'img with alt is not flagged' );

$missing = '<p><img src="https://x/y.jpg"></p>';
hc_eq( array( 'https://x/y.jpg' ), sn_health_extract_inline_imgs_without_alt( $missing ), 'img without alt is captured' );

$mixed = '<p><img src="a.jpg" alt="ok"><img src="b.jpg"></p>';
hc_eq( array( 'b.jpg' ), sn_health_extract_inline_imgs_without_alt( $mixed ), 'only the alt-less src is captured (a.jpg skipped)' );

// Single-quote variant
$single = "<img src='c.jpg'>";
hc_eq( array( 'c.jpg' ), sn_health_extract_inline_imgs_without_alt( $single ), 'single-quoted src parsed' );

// Empty-string alt is still "has alt" per HTML spec (decorative image)
$empty_alt = '<img src="d.jpg" alt="">';
hc_eq( array(), sn_health_extract_inline_imgs_without_alt( $empty_alt ), 'alt="" is valid (decorative) and not flagged' );

// Case-insensitive attribute matching
$upper = '<IMG SRC="e.jpg">';
hc_eq( array( 'e.jpg' ), sn_health_extract_inline_imgs_without_alt( $upper ), 'uppercase tag + attribute names handled' );

// ─── Test 2: extract_internal_links ──────────────────────────────────
echo "\nTest 2: sn_health_extract_internal_links\n";
$host = 'juanlentino.com';
hc_eq( array(), sn_health_extract_internal_links( '', $host ), 'empty content → empty array' );

$external = '<a href="https://example.com/foo">x</a>';
hc_eq( array(), sn_health_extract_internal_links( $external, $host ), 'external host link skipped' );

$absolute = '<a href="https://juanlentino.com/notes/x">x</a>';
hc_eq( array( 'https://juanlentino.com/notes/x' ), sn_health_extract_internal_links( $absolute, $host ), 'same-host absolute link captured' );

$relative = '<a href="/notes/y">y</a>';
hc_eq( array( 'https://juanlentino.com/notes/y' ), sn_health_extract_internal_links( $relative, $host ), 'root-relative link normalized to home_url' );

$mixed = '<a href="https://juanlentino.com/a">a</a><a href="https://example.com/b">b</a><a href="/c">c</a>';
$got   = sn_health_extract_internal_links( $mixed, $host );
hc_eq( 2, count( $got ), 'mixed content: external dropped, 2 internal retained' );
hc_true( in_array( 'https://juanlentino.com/a', $got, true ), 'absolute internal present' );
hc_true( in_array( 'https://juanlentino.com/c', $got, true ), 'root-relative internal present' );

$junk = '<a href="#section">anchor</a><a href="mailto:x@y.z">mail</a><a href="tel:+15555">phone</a><a href="javascript:alert(1)">xss</a>';
hc_eq( array(), sn_health_extract_internal_links( $junk, $host ), 'anchors + mailto + tel + javascript: all skipped' );

$dedupe = '<a href="/x">a</a><a href="/x">b</a><a href="/x">c</a>';
hc_eq( array( 'https://juanlentino.com/x' ), sn_health_extract_internal_links( $dedupe, $host ), 'repeated identical URLs are deduped' );

// Case-insensitive host comparison (a real reproducer is uncommon but the impl handles it)
$cap = '<a href="https://Juanlentino.COM/up">U</a>';
hc_eq( array( 'https://Juanlentino.COM/up' ), sn_health_extract_internal_links( $cap, $host ), 'mixed-case host treated as internal' );

// ─── Test 3: link_status caching + result shape ──────────────────────
echo "\nTest 3: sn_health_link_status caching\n";
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_http_responses'] = array(
	'https://juanlentino.com/ok'   => array( 'response' => array( 'code' => 200 ) ),
	'https://juanlentino.com/gone' => array( 'response' => array( 'code' => 404 ) ),
);
$ok = sn_health_link_status( 'https://juanlentino.com/ok' );
hc_eq( true, $ok['ok'], '200 → ok=true' );
hc_eq( 200, $ok['code'], '200 → code=200' );

$gone = sn_health_link_status( 'https://juanlentino.com/gone' );
hc_eq( false, $gone['ok'], '404 → ok=false' );
hc_eq( 404, $gone['code'], '404 → code=404' );

// Second call should hit the transient — flip the underlying response
// to a different code; if we still see the cached value, caching works.
$GLOBALS['__test_http_responses']['https://juanlentino.com/ok'] = array( 'response' => array( 'code' => 500 ) );
$cached = sn_health_link_status( 'https://juanlentino.com/ok' );
hc_eq( 200, $cached['code'], 'second call returns cached 200, not the new live 500' );

// 405 (HEAD rejected) should fall through to GET — explicit GET fixture.
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_http_responses']['https://juanlentino.com/head_no']     = array( 'response' => array( 'code' => 405 ) );
$GLOBALS['__test_http_responses_get']['https://juanlentino.com/head_no'] = array( 'response' => array( 'code' => 200 ) );
$fallback = sn_health_link_status( 'https://juanlentino.com/head_no' );
hc_eq( 200, $fallback['code'], 'HEAD 405 falls back to GET 200' );
hc_eq( true, $fallback['ok'], 'fallback 200 → ok=true' );

// Network error → code=0, ok=false.
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_http_responses']['https://juanlentino.com/oops'] = new WP_Error( 'http_request_failed', 'connection refused' );
$err = sn_health_link_status( 'https://juanlentino.com/oops' );
hc_eq( false, $err['ok'], 'WP_Error → ok=false' );
hc_eq( 0, $err['code'], 'WP_Error → code=0' );

// ─── Test 4: pack_check envelope ──────────────────────────────────────
echo "\nTest 4: sn_health_pack_check\n";
$packed = sn_health_pack_check( 'My check', array( array( 'x' => 1 ), array( 'x' => 2 ) ), 'fix it' );
hc_eq( 2, $packed['count'], 'count derived from findings array' );
hc_eq( 'My check', $packed['label'], 'label echoed' );
hc_eq( 'fix it', $packed['fix_hint'], 'fix_hint echoed' );

$empty = sn_health_pack_check( 'Empty', array() );
hc_eq( 0, $empty['count'], 'empty findings → count 0' );
hc_eq( '', $empty['fix_hint'], 'fix_hint defaults to empty string' );

// ─── Test: extract_time_phrase_candidates — pattern coverage ─────────
echo "\nTest extract_time_phrase_candidates: pattern coverage\n";
$content = "Welcome. As of 2024 the framework supports both modes. This year we expect changes. Recently the team announced a new approach — just released last month. The latest version is 5.4. Currently in beta. Next year, things may differ.";
$candidates = sn_health_extract_time_phrase_candidates( $content );
hc_true( is_array( $candidates ), 'returns array' );
hc_true( count( $candidates ) >= 5, 'finds at least 5 phrases in mixed content' );
$phrase_set = array_map( 'strtolower', array_column( $candidates, 'phrase' ) );
hc_true( in_array( 'as of 2024', $phrase_set, true ),     'matches "as of YYYY"' );
hc_true( in_array( 'this year', $phrase_set, true ),       'matches "this year"' );
hc_true( in_array( 'recently', $phrase_set, true ),        'matches "recently"' );
hc_true( in_array( 'just released', $phrase_set, true ),   'matches "just released"' );
hc_true( in_array( 'the latest', $phrase_set, true ),      'matches "the latest"' );
hc_true( in_array( 'currently', $phrase_set, true ),       'matches "currently"' );
hc_true( in_array( 'next year', $phrase_set, true ),       'matches "next year"' );

// ─── Test: empty content returns empty ───────────────────────────────
echo "\nTest extract_time_phrase_candidates: empty content\n";
hc_eq( array(), sn_health_extract_time_phrase_candidates( '' ), 'empty string → empty array' );
hc_eq( array(), sn_health_extract_time_phrase_candidates( '<p>No timey phrases here at all.</p>' ), 'no matches → empty array' );

// ─── Test: candidate has context_snippet for AI ──────────────────────
echo "\nTest extract_time_phrase_candidates: context snippet\n";
$content = str_repeat( 'lorem ipsum dolor sit amet ', 5 ) . 'as of 2024 things changed. ' . str_repeat( 'consectetur adipiscing elit ', 5 );
$candidates = sn_health_extract_time_phrase_candidates( $content );
hc_true( count( $candidates ) >= 1, 'one phrase found' );
$snippet = $candidates[0]['context_snippet'];
hc_true( false !== stripos( $snippet, 'as of 2024' ), 'context includes the phrase' );
hc_true( strlen( $snippet ) <= 220 && strlen( $snippet ) >= 30, 'context is bounded (~200 chars)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
