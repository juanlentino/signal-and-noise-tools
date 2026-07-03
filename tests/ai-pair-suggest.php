<?php
/**
 * Standalone fixture tests for the v8.1.0 semantic-pair Suggest impl
 * (inc/ai-pair-suggest.php): AI verdict + anchor NOMINATION validated by
 * the impl against current prose (fabricated anchors degrade to
 * advice-only), dual-stamp verdict cache, Apply riding the existing
 * snt_ai_link_apply_impl unchanged.
 *
 * Run: php tests/ai-pair-suggest.php
 * @since plugin v8.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )  { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'ARRAY_A' ) )         { define( 'ARRAY_A', 'ARRAY_A' ); }

// ── WP stubs (cribbed from tests/ai-link-suggest.php) ──
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://x.test/wp-admin/' . $p; } }
// v8.4.1: map-backed option stubs — the verdict store is durable now.
$GLOBALS['__options'] = array();
$GLOBALS['__option_autoload'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; $GLOBALS['__option_autoload'][ $k ] = $autoload; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; } }
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

// ── AI seam stubs (call-counted for the cache tests) ──
$GLOBALS['__ai_gate'] = null;   // null = available; WP_Error = gated
function snt_ai_require_text_generation() { return $GLOBALS['__ai_gate']; }
$GLOBALS['__ai_calls'] = 0;
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Related.","anchor":""}';
function snt_ai_generate_with_constraints( $prompt, $system, $max_tokens = 256, $feature = 'generic' ) {
	$GLOBALS['__ai_calls']++;
	return $GLOBALS['__ai_response'];
}

// Tag stub (payload context only; empty is fine for these tests).
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $tax = 'post_tag', $args = array() ) { return array(); }
}

$GLOBALS['wpdb'] = new stdClass(); // unused by these impls; satisfies file-scope expectations.

require_once __DIR__ . '/../inc/health-checks.php';           // sn_health_contains_note_link
require_once __DIR__ . '/../inc/ai-drift-phrase-suggest.php'; // locator + fingerprint (reused)
require_once __DIR__ . '/../inc/ai-link-suggest.php';         // apply impl (integration case) + anchor max const
require_once __DIR__ . '/../inc/ai-pair-suggest.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "ai-pair-suggest suite - plugin v8.1.0\n";

// Fixtures: block markup BEFORE the anchor phrase so raw offset != stripped
// offset (the drift v4.1.1 raw-vs-stripped lesson, same trap as link-suggest).
mk_post( 2, 'Console Craft', 'console-craft', '<p>target body about desks and consoles</p>' );
mk_post( 1, 'Mixing Vocals Loud', 'mixing-vocals-loud',
	"<!-- wp:paragraph -->\n<p>Preamble paragraph.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>My whole approach to the mixing console changed last year.</p>\n<!-- /wp:paragraph -->" );

echo "\nTest: happy path — AI nominates a real phrase\n";
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"The source discusses console workflow the target covers.","anchor":"the mixing console"}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( is_array( $res ) && true === ( $res['ok'] ?? false ), 'returns ok array' );
ok( 'link' === ( $res['verdict'] ?? '' ), 'verdict passes through' );
ok( true === ( $res['can_apply'] ?? false ), 'validated anchor => can_apply true' );
ok( 'the mixing console' === ( $res['anchor'] ?? '' ), 'anchor is the phrase as it appears in prose' );
$raw = $GLOBALS['__posts'][1]->post_content;
ok( ( $res['position'] ?? -1 ) === strpos( $raw, 'the mixing console' ), 'position is the RAW-content offset' );
ok( 32 === strlen( (string) ( $res['fingerprint'] ?? '' ) ), 'fingerprint is md5 (32 chars)' );
ok( snt_ai_drift_fingerprint( $raw, $res['anchor'], $res['position'] ) === $res['fingerprint'], 'fingerprint matches the drift contract at the resolved position' );
ok( '' !== ( $res['context_snippet'] ?? '' ), 'context snippet present' );
ok( 'https://x.test/notes/console-craft/' === ( $res['target_url'] ?? '' ), 'target_url is the target permalink' );
ok( 1 === $GLOBALS['__ai_calls'], 'one AI call made' );

echo "\nTest: dual-stamp verdict cache\n";
$res2 = snt_ai_pair_suggest_impl( 1, 2 );
ok( 1 === $GLOBALS['__ai_calls'], 'same stamps: NO second AI call' );
ok( ( $res2['fingerprint'] ?? '' ) === $res['fingerprint'], 'fingerprint recomputed fresh (never cached)' );

echo "\nTest: verdict memory is DURABLE (v8.4.1) and PER-ROW (v8.4.3)\n";
$GLOBALS['__transients'] = array(); // Breeze purge / v10.22.0 auto-purge flushes every transient
$res2b = snt_ai_pair_suggest_impl( 1, 2 );
ok( 1 === $GLOBALS['__ai_calls'], 'verdict survives a full transient flush — no AI re-bill, no resurrected finding' );
// v8.4.5: the key is ID-only; the stamps live INSIDE the payload. Stamp-keyed
// rows orphaned on every Apply (wp_update_post bumps post_modified, the key
// stops matching, judged siblings resurrect + re-bill — the owner's "still
// doing the same" on v8.4.4).
$pkey = 'sn_pair_verdict_' . md5( '1|2' );
ok( is_array( $GLOBALS['__options'][ $pkey ] ?? null ), 'pair verdict lives in its OWN ID-keyed option row (v8.4.5 — stamp-keyed rows orphaned on Apply)' );
ok( '2026-07-01 00:00:00' === ( $GLOBALS['__options'][ $pkey ]['src_mod'] ?? '' ) && '2026-07-01 00:00:00' === ( $GLOBALS['__options'][ $pkey ]['tgt_mod'] ?? '' ), 'payload carries BOTH modified stamps (invalidation moved from key to payload)' );
ok( 1 === ( $GLOBALS['__options'][ $pkey ]['src_id'] ?? 0 ) && 2 === ( $GLOBALS['__options'][ $pkey ]['tgt_id'] ?? 0 ), 'payload carries the post ids (restamp-on-apply needs them)' );
ok( false === ( $GLOBALS['__option_autoload'][ $pkey ] ?? null ), 'verdict row is autoload=no' );
ok( ! isset( $GLOBALS['__options']['sn_ai_link_verdicts'] ), 'the v8.4.1 shared-map option is NOT written' );
ok( 500 === SNT_AI_PAIR_SUGGEST_MAX_TOKENS, 'pair budget raised to 500 (three-field response was truncating at 300 live)' );

$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 09:00:00'; // TARGET edit invalidates too
$res3 = snt_ai_pair_suggest_impl( 1, 2 );
ok( 2 === $GLOBALS['__ai_calls'], 'target-modified change busts the cache (the stamp the mentions check never needed)' );
$pair_rows = 0;
foreach ( array_keys( $GLOBALS['__options'] ) as $k ) { if ( 0 === strpos( $k, 'sn_pair_verdict_' ) ) { $pair_rows++; } }
ok( 1 === $pair_rows, 'the re-suggest OVERWROTE the same row — stamp changes no longer orphan verdict rows' );
ok( '2026-07-02 09:00:00' === ( $GLOBALS['__options'][ $pkey ]['tgt_mod'] ?? '' ), 'overwritten row carries the fresh target stamp' );

echo "\nTest: v8.4.5 — legacy stamp-keyed row is read once and migrated\n";
mk_post( 21, 'Legacy Target', 'legacy-target', '<p>legacy target body</p>' );
mk_post( 20, 'Legacy Source', 'legacy-source', '<p>Prose that never mentions the target title.</p>' );
$legacy_key = 'sn_pair_verdict_' . md5( '20|21|2026-07-01 00:00:00|2026-07-01 00:00:00' );
$GLOBALS['__options'][ $legacy_key ] = array( 'verdict' => 'skip', 'reason' => 'judged pre-v8.4.5', 'anchor' => '', 'ts' => time() );
$calls_before = $GLOBALS['__ai_calls'];
$res_legacy   = snt_ai_pair_suggest_impl( 20, 21 );
ok( $GLOBALS['__ai_calls'] === $calls_before, 'legacy row is a cache HIT — no AI re-bill for a pre-upgrade judgment' );
ok( 'skip' === ( $res_legacy['verdict'] ?? '' ), 'legacy verdict passes through' );
$new_key = 'sn_pair_verdict_' . md5( '20|21' );
ok( is_array( $GLOBALS['__options'][ $new_key ] ?? null ) && 20 === ( $GLOBALS['__options'][ $new_key ]['src_id'] ?? 0 ), 'legacy row migrated to the ID-keyed row with ids + stamps' );
ok( ! isset( $GLOBALS['__options'][ $legacy_key ] ), 'legacy row deleted after migration' );

echo "\nTest: fabricated anchor degrades to advice-only\n";
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 10:00:00'; // fresh cache key
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Related.","anchor":"phrase that appears nowhere"}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( 'link' === ( $res['verdict'] ?? '' ) && 'Related.' === ( $res['reason'] ?? '' ), 'verdict and reason stand' );
ok( false === ( $res['can_apply'] ?? true ), 'fabricated anchor => can_apply false' );
ok( '' === ( $res['anchor'] ?? 'x' ) && -1 === ( $res['position'] ?? 0 ) && '' === ( $res['fingerprint'] ?? 'x' ), 'anchor/position/fingerprint emptied' );

echo "\nTest: empty anchor + skip verdict are advice-only shapes\n";
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 11:00:00';
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Related but no clean phrase.","anchor":""}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( false === ( $res['can_apply'] ?? true ), 'empty nomination => advice-only' );
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 12:00:00';
$GLOBALS['__ai_response'] = '{"verdict":"skip","reason":"Superficial overlap.","anchor":"the mixing console"}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( 'skip' === ( $res['verdict'] ?? '' ) && false === ( $res['can_apply'] ?? true ), 'skip verdict never carries an applyable anchor' );

echo "\nTest: markdown fences + unknown verdict coercion\n";
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 13:00:00';
$GLOBALS['__ai_response'] = "```json\n{\"verdict\":\"link\",\"reason\":\"Fenced.\",\"anchor\":\"the mixing console\"}\n```";
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( 'link' === ( $res['verdict'] ?? '' ) && true === ( $res['can_apply'] ?? false ), 'fenced JSON parses' );
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 14:00:00';
$GLOBALS['__ai_response'] = '{"verdict":"maybe","reason":"?"}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( 'unsure' === ( $res['verdict'] ?? '' ), 'unknown verdict coerces to unsure' );

echo "\nTest: guard table\n";
$e = snt_ai_pair_suggest_impl( 1, 1 );
ok( is_wp_error( $e ) && 'snt_ai_link_invalid' === $e->get_error_code(), 'self-pair 422' );
$e = snt_ai_pair_suggest_impl( 1, 999 );
ok( is_wp_error( $e ) && 'snt_ai_post_not_found' === $e->get_error_code(), 'missing target 404' );
mk_post( 3, 'Draft Target', 'draft-target', '<p>x</p>', 'draft' );
$e = snt_ai_pair_suggest_impl( 1, 3 );
ok( is_wp_error( $e ) && 'snt_ai_post_not_found' === $e->get_error_code(), 'unpublished target 404' );
mk_post( 4, 'Linked Already', 'linked-already', '<p>t</p>' );
mk_post( 5, 'Linker', 'linker', '<p>see <a href="/notes/linked-already">this</a></p>' );
$e = snt_ai_pair_suggest_impl( 5, 4 );
ok( is_wp_error( $e ) && 'snt_ai_link_already_linked' === $e->get_error_code(), 'stale finding: already linked 409' );

echo "\nTest: gate + unparseable\n";
$GLOBALS['__ai_gate'] = new WP_Error( 'snt_ai_unavailable', 'gated', array( 'status' => 503 ) );
$e = snt_ai_pair_suggest_impl( 1, 2 );
ok( is_wp_error( $e ) && 'snt_ai_unavailable' === $e->get_error_code(), 'availability gate passes through' );
$GLOBALS['__ai_gate'] = null;
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 15:00:00';
$GLOBALS['__ai_response'] = 'not json at all';
$e = snt_ai_pair_suggest_impl( 1, 2 );
ok( is_wp_error( $e ) && 'snt_ai_runtime_error' === $e->get_error_code(), 'unparseable verdict 500' );

echo "\nTest: integration — suggest output feeds the EXISTING apply impl\n";
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-02 16:00:00';
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Yes.","anchor":"the mixing console"}';
$res = snt_ai_pair_suggest_impl( 1, 2 );
$applied = snt_ai_link_apply_impl( $res['post_id'], $res['anchor'], $res['context_snippet'], $res['fingerprint'], $res['target_url'] );
ok( is_array( $applied ) && true === ( $applied['ok'] ?? false ), 'apply accepts the pair-suggest splice contract' );
ok( false !== strpos( (string) $GLOBALS['__updated']['post_content'], '<a href="https://x.test/notes/console-craft/">the mixing console</a>' ), 'anchor wrapped in the target link' );

echo "\nTest: v8.1.1 — nominated anchor already inside a link degrades to advice-only\n";
// Live incident 2026-07-02: the AI sees STRIPPED prose (links invisible), so
// it can nominate a phrase that already sits inside an <a>; apply then 400s.
// Suggest must run the same inside-anchor guard apply enforces.
mk_post( 11, 'Linked Anchor Target', 'linked-anchor-target', '<p>target body</p>' );
mk_post( 10, 'Linked Anchor Source', 'linked-anchor-source',
	"<!-- wp:paragraph -->\n<p>Intro paragraph first.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>See the <a href=\"/notes/some-other-note\">music provenance cluster</a> for context.</p>\n<!-- /wp:paragraph -->" );
$GLOBALS['__ai_response'] = '{"verdict":"link","reason":"Related.","anchor":"music provenance cluster"}';
$res = snt_ai_pair_suggest_impl( 10, 11 );
ok( is_array( $res ) && 'link' === ( $res['verdict'] ?? '' ), 'verdict stands on inside-anchor degrade' );
ok( false === ( $res['can_apply'] ?? true ), 'anchor inside an existing <a> => advice-only (apply would 400)' );
ok( '' === ( $res['anchor'] ?? 'x' ) && -1 === ( $res['position'] ?? 0 ), 'anchor + position emptied when inside a link' );

echo "\nTest: v8.1.1 — prose-preamble JSON salvage\n";
$GLOBALS['__posts'][2]->post_modified_gmt = '2026-07-03 08:00:00';
$GLOBALS['__ai_response'] = 'Here is my verdict: {"verdict":"link","reason":"Yes.","anchor":"the mixing console"} Hope that helps!';
$res = snt_ai_pair_suggest_impl( 1, 2 );
ok( is_array( $res ) && 'link' === ( $res['verdict'] ?? '' ) && true === ( $res['can_apply'] ?? false ), 'preamble-wrapped JSON parses via brace salvage' );

echo "\nTest: v8.1.1 — output budget raised for the three-field response\n";
ok( defined( 'SNT_AI_PAIR_SUGGEST_MAX_TOKENS' ) && SNT_AI_PAIR_SUGGEST_MAX_TOKENS >= 300, 'pair budget >= 300 tokens (truncation headroom for verdict+reason+anchor)' );

echo "\nTest: v8.1.2 — prompt forbids anchor-less link verdicts (no-anchor folds into skip)\n";
ok( false !== strpos( SNT_AI_PAIR_SUGGEST_SYSTEM, 'return "skip" instead' ), 'system prompt folds no-anchor into skip (owner noise rule)' );

echo "\nTest: v8.1.2 — snt_ai_pair_nomination_contract (shared validation, scan reuses it)\n";
$craw = "<!-- wp:paragraph -->\n<p>Alpha beta.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>Try the mixing console today.</p>\n<!-- /wp:paragraph -->";
$cstr = wp_strip_all_tags( strip_shortcodes( $craw ) );
$c = snt_ai_pair_nomination_contract( $craw, $cstr, 'the mixing console' );
ok( is_array( $c ) && 32 === strlen( (string) ( $c['fingerprint'] ?? '' ) ) && ( $c['position'] ?? -1 ) === strpos( $craw, 'the mixing console' ), 'valid nomination yields the full splice contract' );
ok( null === snt_ai_pair_nomination_contract( '<p>See <a href="/x">the phrase</a> now.</p>', 'See the phrase now.', 'the phrase' ), 'inside-anchor nomination yields null' );
ok( null === snt_ai_pair_nomination_contract( '<p>abc def.</p>', 'abc def.', '' ), 'empty nomination yields null' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
