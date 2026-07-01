<?php
/**
 * Standalone fixture tests for the v7.4.0 unlinked-mentions Suggest+Apply
 * impls (inc/ai-link-suggest.php). Mirrors the drift machinery: raw-position
 * resolution via snt_ai_drift_locate_in_raw(), md5 window fingerprint via
 * snt_ai_drift_fingerprint(), 409 on concurrent edit.
 *
 * Run: php tests/ai-link-suggest.php
 * @since plugin v7.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

// ── WP stubs ──
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_basename' ) ) { function wp_basename( $p ) { return basename( (string) $p ); } }
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id ) { return array(); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }
if ( ! function_exists( 'strip_shortcodes' ) ) { function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $s ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://x.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( (string) $u ) : parse_url( (string) $u, $c ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return str_replace( array( '"', "'", '<', '>' ), '', (string) $u ); } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : (int) $p; return 'https://x.test/notes/' . ( $GLOBALS['__posts'][ $id ]->post_name ?? $id ) . '/'; } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

$GLOBALS['__posts'] = array();
function mk_post( $id, $title, $name, $content, $status = 'publish' ) {
	$p = new stdClass();
	$p->ID = $id; $p->post_title = $title; $p->post_name = $name;
	$p->post_content = $content; $p->post_status = $status;
	$p->post_modified_gmt = '2026-07-01 00:00:00';
	$GLOBALS['__posts'][ $id ] = $p;
	return $p;
}
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }

$GLOBALS['__can_edit'] = true;
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['__can_edit']; }

$GLOBALS['__updated'] = null;   // captured wp_update_post payload
$GLOBALS['__update_result'] = 1;
function wp_update_post( $arr, $wp_error = false ) {
	$GLOBALS['__updated'] = $arr;
	return $GLOBALS['__update_result'];
}

$GLOBALS['__transients'] = array();
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }

// ── AI seam stubs (call-counted for the cache test) ──
$GLOBALS['__ai_gate'] = null;   // null = available; WP_Error = gated
function snt_ai_require_text_generation() { return $GLOBALS['__ai_gate']; }
$GLOBALS['__ai_calls'] = 0;
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Directly references the essay."}';
function snt_ai_generate_with_constraints( $prompt, $system, $max_tokens = 256, $feature = 'generic' ) {
	$GLOBALS['__ai_calls']++;
	return $GLOBALS['__ai_response'];
}

$GLOBALS['wpdb'] = new stdClass(); // unused by these impls; satisfies file-scope expectations.

require_once __DIR__ . '/../inc/health-checks.php';          // sn_health_contains_note_link
require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php'; // locator + fingerprint (reused)
require_once __DIR__ . '/../inc/ai-link-suggest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── fixtures: target + a source with block markup BEFORE the mention so raw
// offset != stripped offset (the drift v4.1.1 raw-vs-stripped lesson). ──
mk_post( 2, 'Honesty has to be the cheap option', 'cheap-option', '<p>target body</p>' );
mk_post( 1, 'Source note', 'source-a',
	"<!-- wp:paragraph -->\n<p>Preamble paragraph.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>I said honesty has to be the cheap option and meant it.</p>\n<!-- /wp:paragraph -->" );

echo "\nTest: snt_ai_link_suggest_impl — happy path\n";
$res = snt_ai_link_suggest_impl( 1, 2 );
ok( is_array( $res ) && true === ( $res['ok'] ?? false ), 'returns ok array' );
ok( 'link' === ( $res['verdict'] ?? '' ), 'verdict passes through' );
ok( 'honesty has to be the cheap option' === ( $res['anchor'] ?? '' ), 'anchor is the mention as it appears in prose' );
ok( 'https://x.test/notes/cheap-option/' === ( $res['target_url'] ?? '' ), 'target_url is the target permalink' );
$raw = $GLOBALS['__posts'][1]->post_content;
ok( ( $res['position'] ?? -1 ) === strpos( $raw, 'honesty has to be the cheap option' ), 'position is the RAW-content offset (not stripped coords)' );
ok( 32 === strlen( (string) ( $res['fingerprint'] ?? '' ) ), 'fingerprint is md5 (32 hex chars)' );
ok( snt_ai_drift_fingerprint( $raw, $res['anchor'], $res['position'] ) === $res['fingerprint'], 'fingerprint matches the drift fingerprint contract at the resolved position' );
ok( '' !== ( $res['context_snippet'] ?? '' ), 'context snippet present' );
ok( 1 === $GLOBALS['__ai_calls'], 'one AI call made' );

echo "\nTest: verdict cache short-circuits the AI\n";
$res2 = snt_ai_link_suggest_impl( 1, 2 );
ok( 1 === $GLOBALS['__ai_calls'], 'second suggest for the same (source, target, modified) makes NO AI call' );
ok( ( $res2['verdict'] ?? '' ) === 'link' && ( $res2['fingerprint'] ?? '' ) === $res['fingerprint'], 'cached verdict + fresh fingerprint returned' );

echo "\nTest: suggest guards\n";
$GLOBALS['__ai_gate'] = new WP_Error( 'snt_ai_unavailable', 'no provider' );
ok( is_wp_error( snt_ai_link_suggest_impl( 1, 2 ) ), 'AI gate error propagates' );
$GLOBALS['__ai_gate'] = null;
ok( 'snt_ai_post_not_found' === snt_ai_link_suggest_impl( 99, 2 )->get_error_code(), 'missing source → 404 code' );
ok( 'snt_ai_post_not_found' === snt_ai_link_suggest_impl( 1, 99 )->get_error_code(), 'missing target → 404 code' );
ok( 'snt_ai_link_invalid' === snt_ai_link_suggest_impl( 1, 1 )->get_error_code(), 'self pair → invalid' );

// already linked → 409 (stale finding).
mk_post( 3, 'Already linked source', 'source-b', '<p>see <a href="/notes/cheap-option/">honesty has to be the cheap option</a></p>' );
$e = snt_ai_link_suggest_impl( 3, 2 );
ok( 'snt_ai_link_already_linked' === $e->get_error_code() && 409 === ( $e->get_error_data()['status'] ?? 0 ), 'already-linked source → 409' );

// mention gone → 409.
mk_post( 4, 'No mention source', 'source-c', '<p>entirely unrelated prose</p>' );
ok( 'snt_ai_mention_drifted' === snt_ai_link_suggest_impl( 4, 2 )->get_error_code(), 'mention absent → drifted 409' );

// fenced JSON is parsed; garbage is a runtime error.
$GLOBALS['__transients'] = array();
$GLOBALS['__ai_response'] = "```json\n{\"verdict\":\"skip\",\"reason\":\"generic phrase\"}\n```";
ok( 'skip' === ( snt_ai_link_suggest_impl( 1, 2 )['verdict'] ?? '' ), 'markdown-fenced verdict JSON parsed' );
$GLOBALS['__transients'] = array();
$GLOBALS['__ai_response'] = 'not json at all';
ok( 'snt_ai_runtime_error' === snt_ai_link_suggest_impl( 1, 2 )->get_error_code(), 'unparseable AI response → runtime error' );
$GLOBALS['__transients'] = array();
$GLOBALS['__ai_response'] = '{"verdict":"banana","reason":"?"}';
ok( 'unsure' === ( snt_ai_link_suggest_impl( 1, 2 )['verdict'] ?? '' ), 'out-of-enum verdict coerces to unsure' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
