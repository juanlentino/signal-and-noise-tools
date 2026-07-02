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

echo "\nTest: snt_ai_link_apply_impl — happy path\n";
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"r"}';
$GLOBALS['__transients'] = array();
$s = snt_ai_link_suggest_impl( 1, 2 );
$out = snt_ai_link_apply_impl( 1, $s['anchor'], $s['context_snippet'], $s['fingerprint'], $s['target_url'] );
ok( is_array( $out ) && true === ( $out['ok'] ?? false ), 'apply returns ok' );
$written = (string) ( $GLOBALS['__updated']['post_content'] ?? '' );
ok( false !== strpos( $written, '<a href="https://x.test/notes/cheap-option/">honesty has to be the cheap option</a>' ), 'mention wrapped in the target link' );
ok( 1 === substr_count( $written, '<a href="https://x.test/notes/cheap-option/">' ), 'exactly one link inserted' );
ok( false !== strpos( $written, 'Preamble paragraph.' ), 'surrounding content untouched' );

echo "\nTest: apply — multi-occurrence disambiguation via context\n";
// The two occurrences need genuinely distinct 200-char neighborhoods —
// the locator disambiguates by stripped-window similarity, so a fixture
// where both windows cover the same short text cannot resolve (and the
// shipped drift contract falls back to the first occurrence on a tie).
mk_post( 5, 'Multi source', 'source-d',
	'<p>First: honesty has to be the cheap option here, in a paragraph about pricing strategy and the long-term cost of dishonest work, which continues at length so the first occurrence owns a distinct neighborhood of words all its own.</p>' .
	"\n" .
	'<p>Entirely different closing thoughts about UNIQUE-MARKER trust and craft: honesty has to be the cheap option again, this time framed around reader trust and the compounding value of saying true things plainly and often.</p>' );
$raw5   = $GLOBALS['__posts'][5]->post_content;
// Derive the context the way suggest does: stripped window around the SECOND occurrence.
$strip5 = wp_strip_all_tags( strip_shortcodes( $raw5 ) );
$sec_s  = strpos( $strip5, 'honesty has to be the cheap option', strpos( $strip5, 'UNIQUE-MARKER' ) );
$ctx    = trim( substr( $strip5, max( 0, $sec_s - 80 ), 200 ) );
$second = strpos( $raw5, 'honesty has to be the cheap option', strpos( $raw5, 'UNIQUE-MARKER' ) );
$fp     = snt_ai_drift_fingerprint( $raw5, 'honesty has to be the cheap option', $second );
$out = snt_ai_link_apply_impl( 5, 'honesty has to be the cheap option', $ctx, $fp, 'https://x.test/notes/cheap-option/' );
ok( is_array( $out ) && true === ( $out['ok'] ?? false ), 'second-occurrence apply succeeds' );
$written = (string) $GLOBALS['__updated']['post_content'];
ok( false !== strpos( $written, "First: honesty has to be the cheap option here" ), 'FIRST occurrence untouched' );
ok( false !== strpos( $written, 'UNIQUE-MARKER trust and craft: <a href="https://x.test/notes/cheap-option/">honesty' ), 'SECOND occurrence (context match) wrapped' );

echo "\nTest: apply guards\n";
// already inside an <a> → 400.
mk_post( 6, 'Linked source', 'source-e', '<p>see <a href="/elsewhere/">honesty has to be the cheap option</a></p>' );
$raw6 = $GLOBALS['__posts'][6]->post_content;
$p6   = strpos( $raw6, 'honesty' );
$fp6  = snt_ai_drift_fingerprint( $raw6, 'honesty has to be the cheap option', $p6 );
$e = snt_ai_link_apply_impl( 6, 'honesty has to be the cheap option', '', $fp6, 'https://x.test/notes/cheap-option/' );
ok( 'snt_ai_link_already_linked' === $e->get_error_code() && 400 === ( $e->get_error_data()['status'] ?? 0 ), 'anchor inside existing <a> → 400' );

// <aside> before the anchor must NOT trip the inside-anchor guard.
mk_post( 7, 'Aside source', 'source-f', '<aside>note</aside><p>honesty has to be the cheap option</p>' );
$raw7 = $GLOBALS['__posts'][7]->post_content;
$p7   = strpos( $raw7, 'honesty' );
ok( false === snt_ai_link_position_inside_anchor( $raw7, $p7 ), '<aside> does not read as an open <a> (tag-name boundary)' );

// fingerprint mismatch → 409.
$e = snt_ai_link_apply_impl( 1, $s['anchor'], $s['context_snippet'], str_repeat( '0', 32 ), $s['target_url'] );
ok( 'snt_ai_apply_conflict' === $e->get_error_code() && 409 === ( $e->get_error_data()['status'] ?? 0 ), 'fingerprint mismatch → 409' );

// anchor gone → 409.
$e = snt_ai_link_apply_impl( 4, 'honesty has to be the cheap option', '', $s['fingerprint'], $s['target_url'] );
ok( 'snt_ai_apply_conflict' === $e->get_error_code(), 'anchor absent from content → 409' );

// permission → 403.
$GLOBALS['__can_edit'] = false;
$e = snt_ai_link_apply_impl( 1, $s['anchor'], $s['context_snippet'], $s['fingerprint'], $s['target_url'] );
ok( 'snt_ai_capability' === $e->get_error_code(), 'edit_post denial → 403' );
$GLOBALS['__can_edit'] = true;

// cross-host target → 422; empty anchor → 422.
$e = snt_ai_link_apply_impl( 1, $s['anchor'], $s['context_snippet'], $s['fingerprint'], 'https://evil.example/phish' );
ok( 'snt_ai_link_target_invalid' === $e->get_error_code(), 'cross-host target_url → 422' );
$e = snt_ai_link_apply_impl( 1, '', '', $s['fingerprint'], $s['target_url'] );
ok( 'snt_ai_anchor_invalid' === $e->get_error_code(), 'empty anchor → 422' );

// wp_update_post failure → 500.
$GLOBALS['__update_result'] = new WP_Error( 'db', 'boom' );
$e = snt_ai_link_apply_impl( 1, $s['anchor'], $s['context_snippet'], $s['fingerprint'], $s['target_url'] );
ok( 'snt_ai_write_failed' === $e->get_error_code(), 'wp_update_post WP_Error → 500' );
$GLOBALS['__update_result'] = 1;

echo "\nTest: v8.1.1 — mention already inside a link degrades to advice-only\n";
// Same class as the pair-suggest live incident: a mention of the target's
// title can sit inside an existing <a> to a THIRD note; apply would 400.
mk_post( 20, 'Provenance At Every Layer', 'provenance-at-every-layer', '<p>target body</p>' );
mk_post( 21, 'Quoting Source', 'quoting-source',
	"<!-- wp:paragraph -->\n<p>Lead-in text here.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>Last week I wrote <a href=\"/notes/another-note\">Provenance At Every Layer</a> as a follow-up.</p>\n<!-- /wp:paragraph -->" );
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Real reference."}';
$res = snt_ai_link_suggest_impl( 21, 20 );
ok( is_array( $res ) && 'link' === ( $res['verdict'] ?? '' ), 'verdict still computed on inside-anchor degrade' );
ok( false === ( $res['can_apply'] ?? true ), 'mention inside an existing <a> => advice-only (apply would 400)' );
ok( '' === ( $res['anchor'] ?? 'x' ) && -1 === ( $res['position'] ?? 0 ), 'anchor + position emptied when inside a link' );

echo "\nTest: v8.1.1 — happy path gains can_apply true (additive)\n";
$GLOBALS['__posts'][1]->post_modified_gmt = '2026-07-03 00:00:00';
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Directly references the essay."}';
$res = snt_ai_link_suggest_impl( 1, 2 );
ok( true === ( $res['can_apply'] ?? false ) && '' !== ( $res['anchor'] ?? '' ), 'normal mention carries can_apply true + full splice contract' );

echo "\nTest: v8.1.1 — prose-preamble JSON salvage (shared parser)\n";
$GLOBALS['__posts'][1]->post_modified_gmt = '2026-07-03 01:00:00';
$GLOBALS['__ai_response'] = 'Sure! {"verdict":"skip","reason":"Coincidental."} Done.';
$res = snt_ai_link_suggest_impl( 1, 2 );
ok( is_array( $res ) && 'skip' === ( $res['verdict'] ?? '' ), 'preamble-wrapped JSON parses via brace salvage' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
