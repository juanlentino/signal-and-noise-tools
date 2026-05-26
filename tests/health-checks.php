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

class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// AI client stubs — tests inject responses + availability via globals.
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() {
		return ! empty( $GLOBALS['__test_ai_available'] );
	}
}
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max_tokens = 256 ) {
		if ( ! isset( $GLOBALS['__test_ai_call_count'] ) ) {
			$GLOBALS['__test_ai_call_count'] = 0;
		}
		$GLOBALS['__test_ai_call_count']++;
		$GLOBALS['__test_ai_last_prompt'] = $prompt;
		$GLOBALS['__test_ai_last_system'] = $system;
		if ( isset( $GLOBALS['__test_ai_response'] ) ) {
			return $GLOBALS['__test_ai_response'];
		}
		return new WP_Error( 'snt_ai_unavailable', 'no fixture' );
	}
}

// Minimal $wpdb stub — drift-detection tests load rows into ->rows and
// get_results() returns them verbatim. The SQL string is ignored: the
// real filter under test is the PHP regex extractor.
if ( ! defined( 'OBJECT' ) )  { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public $posts = 'wp_posts';
		public $rows  = array();
		public function get_results( $sql, $output_mode = 'OBJECT' ) {
			return $this->rows;
		}
	};
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}

require_once __DIR__ . '/../inc/health-checks.php';

// snt_ai_can_text_generate stub for orphan-suggest gate.
if ( ! function_exists( 'snt_ai_can_text_generate' ) ) {
	function snt_ai_can_text_generate() {
		return ! empty( $GLOBALS['__test_ai_available'] );
	}
}

// Minimal get_post / wp_get_attachment_image_url stubs.
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['__test_posts'][ $id ] ) ) {
			return null;
		}
		return (object) $GLOBALS['__test_posts'][ $id ];
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size ) {
		return 'https://example.com/thumb-' . (int) $id . '.jpg';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}

require_once __DIR__ . '/../inc/ai-orphan-suggest.php';

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

// ─── Test: drift_time_phrases — no AI available skips entirely ───────
echo "\nTest drift_time_phrases: AI unavailable returns empty\n";
$GLOBALS['__test_ai_available'] = false;
$check = sn_health_check_drift_time_phrases();
hc_eq( 0, $check['count'], 'no findings when AI unavailable' );
hc_eq( 'Time-relative drift', $check['label'], 'label set' );

// ─── Test: drift_time_phrases — no candidate phrases skips AI ────────
echo "\nTest drift_time_phrases: zero-candidate post skips AI call\n";
$GLOBALS['__test_ai_available'] = true;
$GLOBALS['__test_ai_call_count'] = 0;
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 1, 'post_title' => 'Evergreen post', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'Evergreen content with no time-relative language at all.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 0, $check['count'], 'no findings for post without candidates' );
hc_eq( 0, $GLOBALS['__test_ai_call_count'], 'AI was NOT called (regex pre-filter eliminated post)' );

// ─── Test: drift_time_phrases — stale verdict surfaces as finding ────
echo "\nTest drift_time_phrases: stale verdict creates finding\n";
$GLOBALS['__test_ai_available'] = true;
$GLOBALS['__test_ai_response'] = '[{"phrase":"as of 2024","verdict":"stale","reason":"Year is 2026; the post claims 2024-current state."}]';
$GLOBALS['__test_ai_call_count'] = 0;
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 5, 'post_title' => 'Dated post', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'As of 2024 the docs say X. Now things may differ.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 1, $check['count'], 'one stale finding' );
hc_eq( 1, $GLOBALS['__test_ai_call_count'], 'AI was called exactly once' );
$f = $check['findings'][0];
hc_eq( 5, $f['subject_id'], 'finding refers to post id 5' );
hc_eq( 'post', $f['subject_type'], 'subject_type = post' );
hc_true( false !== strpos( $f['note'], 'as of 2024' ),   'note quotes the phrase' );
hc_true( false !== strpos( $f['note'], 'Year is 2026' ), 'note includes AI reason' );

// ─── Test: drift_time_phrases — "ok" verdict does NOT create finding
echo "\nTest drift_time_phrases: ok verdict yields no finding\n";
$GLOBALS['__test_ai_response'] = '[{"phrase":"as of 2024","verdict":"ok","reason":"Post was last modified in 2024; phrase reflects accurate snapshot."}]';
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 6, 'post_title' => 'Snapshot post', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'As of 2024 the docs say X.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 0, $check['count'], 'no findings — AI said ok' );

// ─── Test: drift_time_phrases — malformed AI JSON returns empty for that post
echo "\nTest drift_time_phrases: malformed AI response degrades gracefully\n";
$GLOBALS['__test_ai_response'] = 'not even json';
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 7, 'post_title' => 'P', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'As of 2024', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 0, $check['count'], 'malformed AI → silently drops the post (no finding, no fatal)' );

// ─── Test: drift_time_phrases — per-finding edit_url + subject_url
echo "\nTest drift_time_phrases: finding shape matches existing health checks\n";
$GLOBALS['__test_ai_response'] = '[{"phrase":"recently","verdict":"stale","reason":"Old post — \'recently\' refers to events from 2023."}]';
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 8, 'post_title' => 'Old', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'We recently launched.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 1, $check['count'], 'one finding' );
$f = $check['findings'][0];
hc_true( false !== strpos( $f['edit_url'], 'post=8' ),    'edit_url contains post id' );
hc_true( false !== strpos( $f['subject_url'], '?p=8' ),   'subject_url uses get_permalink stub' );
hc_eq( 'Old', $f['subject_label'], 'subject_label is post title' );

// ─── Test: drift_time_phrases — markdown-fenced AI response unwraps ───
echo "\nTest drift_time_phrases: markdown-fenced AI response is unwrapped\n";
$GLOBALS['__test_ai_response'] = "```json\n[{\"phrase\":\"as of 2024\",\"verdict\":\"stale\",\"reason\":\"Old snapshot.\"}]\n```";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 9, 'post_title' => 'Fenced', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'As of 2024 things changed.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 1, $check['count'], 'markdown-fenced JSON gets stripped and parsed' );

// ─── Test: drift_time_phrases — partial fence (no closing) still parses ─
echo "\nTest drift_time_phrases: partial fence still parses\n";
$GLOBALS['__test_ai_response'] = "```json\n[{\"phrase\":\"recently\",\"verdict\":\"stale\",\"reason\":\"Old.\"}]";
$GLOBALS['wpdb']->rows = array(
	array( 'ID' => 10, 'post_title' => 'Partial', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => 'We recently shipped.', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ),
);
$check = sn_health_check_drift_time_phrases();
hc_eq( 1, $check['count'], 'partial fence (no closer) still strips and parses' );

// ─── Test: orphan-suggest cache invariants (v4.1.0) ──────────────────
echo "\nTest: snt_ai_orphan_suggest_impl cache invariants\n";

$GLOBALS['__test_ai_available']  = true;
$GLOBALS['__test_transients']    = array();
$GLOBALS['__test_ai_call_count'] = 0;
$GLOBALS['__test_ai_response']   = '{"verdict":"delete","reason":"one-off screenshot"}';
$GLOBALS['__test_posts']         = array(
	555 => array(
		'ID'                => 555,
		'post_type'         => 'attachment',
		'post_mime_type'    => 'image/png',
		'post_title'        => 'screenshot-2024-03',
		'post_excerpt'      => '',
		'guid'              => 'https://example.com/uploads/screenshot-2024-03.png',
		'post_parent'       => 0,
		'post_date_gmt'     => '2024-03-15 10:00:00',
		'post_modified_gmt' => '2024-03-15 10:00:00',
	),
);

// First call — cold cache, should fire AI.
$res1 = snt_ai_orphan_suggest_impl( 555 );
hc_eq( 1, $GLOBALS['__test_ai_call_count'], 'cold cache: AI call count = 1' );
hc_eq( 'delete', $res1['verdict'], 'cold cache: verdict=delete' );

// Second call — warm cache, should NOT fire AI.
$res2 = snt_ai_orphan_suggest_impl( 555 );
hc_eq( 1, $GLOBALS['__test_ai_call_count'], 'warm cache: AI call count still = 1 (no new call)' );
hc_eq( 'delete', $res2['verdict'], 'warm cache: same verdict returned' );

// Third call after prompt_version mismatch — should fire AI again.
$cache_key = 'sn_orphan_verdict_555';
$GLOBALS['__test_transients'][ $cache_key ]['prompt_version'] = 'stale-md5';
$res3 = snt_ai_orphan_suggest_impl( 555 );
hc_eq( 2, $GLOBALS['__test_ai_call_count'], 'prompt_version mismatch: AI call count = 2' );

// ─── Test: drift locator handles Gutenberg block markup (v4.1.1 B-01 regression test) ───
//
// Pre-v4.1.1 bug: extractor reported stripped-content offsets; apply impl used
// them against raw post_content. For any post with block markup before the
// phrase, the stripped offset was smaller than the raw offset → preflight always
// failed with 409. snt_ai_drift_locate_in_raw() resolves the raw offset
// dynamically using context_snippet — this test guards the regression.

require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php';

echo "\nTest: snt_ai_drift_locate_in_raw — Gutenberg block markup\n";

$gutenberg_content = "<!-- wp:paragraph -->\n<p>Some intro text without the phrase.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>We recently shipped a new feature that customers love.</p>\n<!-- /wp:paragraph -->";

// Extract candidates from the SAME content (as the scan would).
$candidates = sn_health_extract_time_phrase_candidates( $gutenberg_content );
hc_true( count( $candidates ) >= 1, 'gutenberg: extractor found at least one candidate' );
$cand = $candidates[0];
hc_eq( 'recently', $cand['phrase'], 'gutenberg: first candidate phrase is "recently"' );

// The bug: cand['position'] is in stripped coords. substr(raw, position, len(phrase))
// would NOT equal "recently" for this content. Verify the bug exists at the
// data-shape level (so we know the test fixture is realistic).
$at_stripped_offset = substr( $gutenberg_content, $cand['position'], strlen( $cand['phrase'] ) );
hc_true( $at_stripped_offset !== $cand['phrase'], 'gutenberg: stripped-coords position does NOT match raw content (confirms pre-v4.1.1 bug shape)' );

// The fix: snt_ai_drift_locate_in_raw resolves the raw offset.
$raw_pos = snt_ai_drift_locate_in_raw( $gutenberg_content, $cand['phrase'], $cand['context_snippet'] );
hc_true( $raw_pos >= 0, 'gutenberg locator: returned a valid raw offset (not -1)' );
$at_raw_offset = substr( $gutenberg_content, $raw_pos, strlen( $cand['phrase'] ) );
hc_eq( 'recently', $at_raw_offset, 'gutenberg locator: phrase IS at the resolved raw offset' );

// Disambiguation: same phrase appearing twice. Locator should pick the one
// whose surroundings best match context_snippet.
$dup_content = "<!-- wp:paragraph -->\n<p>We recently shipped feature A.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Customers recently asked for feature B.</p>\n<!-- /wp:paragraph -->";
$snippet_b   = 'Customers recently asked for feature B.';
$pos_b       = snt_ai_drift_locate_in_raw( $dup_content, 'recently', $snippet_b );
hc_true( $pos_b > strpos( $dup_content, 'recently shipped' ), 'gutenberg disambiguation: snippet_b locator picks the SECOND occurrence (further into the content)' );

// Phrase not present → -1.
$gone = snt_ai_drift_locate_in_raw( "<p>nothing here</p>", 'recently', 'we recently shipped' );
hc_eq( -1, $gone, 'gutenberg locator: returns -1 when phrase absent (drift signal)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
