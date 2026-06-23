<?php
/**
 * Standalone fixture tests for inc/ai-og-card-title.php.
 *
 * Locks in the v6.39.2 split:
 *   - snt_ai_og_card_title_impl()  — USER-facing entry. Adds an internal
 *     current_user_can('edit_post', $id) guard (defense-in-depth behind the
 *     REST permission_callback / ability cap check) before delegating.
 *   - snt_ai_og_card_title_write() — no-cap internal writer. WP-Cron prepop
 *     (no logged-in user) calls THIS directly so a cap check can't reject it.
 *
 * @since plugin v6.39.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP + collaborator stubs ─────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) {
		$this->code = $c; $this->message = $m; $this->data = $d;
	}
	public function get_error_code()    { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data()    { return $this->data; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// AI gate — available by default; a test can force the unavailable WP_Error.
if ( ! function_exists( 'snt_ai_require_text_generation' ) ) {
	function snt_ai_require_text_generation() {
		return ! empty( $GLOBALS['__og_gate_blocked'] )
			? new WP_Error( 'snt_ai_unavailable', 'AI off', array( 'status' => 503 ) )
			: null;
	}
}

// Per-post capability — default allowed; tests set $GLOBALS['__og_caps'][$id]=false.
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $id = 0 ) {
		$id = (int) $id;
		return array_key_exists( $id, $GLOBALS['__og_caps'] ?? array() ) ? (bool) $GLOBALS['__og_caps'][ $id ] : true;
	}
}

$GLOBALS['__og_posts'] = array();
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['__og_posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'snt_ai_extract_post_text' ) ) {
	function snt_ai_extract_post_text( $id, $words = 1000 ) { return ''; }
}

// Records every AI generation so a denied call can be proven to NOT happen.
$GLOBALS['__og_gen_calls'] = 0;
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic' ) {
		$GLOBALS['__og_gen_calls']++;
		return $GLOBALS['__og_gen_return'] ?? 'A Punchy Card Title';
	}
}

$GLOBALS['__og_meta'] = array();
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $val ) { $GLOBALS['__og_meta'][ (int) $id ][ $key ] = $val; return true; }
}
if ( ! function_exists( 'sn_generate_og_card' ) ) {
	function sn_generate_og_card( $id ) { return true; }
}
if ( ! function_exists( 'sn_og_image_url_for_post' ) ) {
	function sn_og_image_url_for_post( $post ) { return 'https://example.test/card.png'; }
}

require_once __DIR__ . '/../inc/ai-og-card-title.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function og_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function og_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n"; } }
function og_reset() {
	$GLOBALS['__og_gate_blocked'] = false;
	$GLOBALS['__og_caps']         = array();
	$GLOBALS['__og_gen_calls']    = 0;
	$GLOBALS['__og_meta']         = array();
	unset( $GLOBALS['__og_gen_return'] );
	$p = new stdClass(); $p->post_title = 'The Original Article Title'; $p->ID = 50;
	$GLOBALS['__og_posts'] = array( 50 => $p );
}

echo "ai-og-card-title suite — plugin v6.39.2\n";

// ─── Test 1: impl rejects when the user cannot edit the post ─────────
echo "\nTest 1: snt_ai_og_card_title_impl — edit_post denied → 403, no AI call\n";
og_reset();
$GLOBALS['__og_caps'][50] = false;
$res = snt_ai_og_card_title_impl( 50 );
og_true( is_wp_error( $res ), 'returns WP_Error when cap denied' );
og_eq( 403, is_wp_error( $res ) ? ( $res->get_error_data()['status'] ?? null ) : null, 'status 403' );
og_eq( 0, $GLOBALS['__og_gen_calls'], 'no AI generation fired on denial (short-circuits before the spend)' );

// ─── Test 2: impl succeeds when the user can edit the post ───────────
echo "\nTest 2: snt_ai_og_card_title_impl — cap granted → generates + persists\n";
og_reset();
$res = snt_ai_og_card_title_impl( 50 );
og_true( is_array( $res ), 'returns result array when allowed' );
og_eq( 'A Punchy Card Title', is_array( $res ) ? $res['title'] : null, 'title returned' );
og_eq( 1, $GLOBALS['__og_gen_calls'], 'one AI generation fired' );
og_eq( 'A Punchy Card Title', $GLOBALS['__og_meta'][50]['_sn_og_card_title'] ?? null, 'override meta persisted' );

// ─── Test 3: writer ignores caps (the WP-Cron prepop path) ──────────
echo "\nTest 3: snt_ai_og_card_title_write — no cap check (cron has no user)\n";
og_reset();
$GLOBALS['__og_caps'][50] = false; // a cap check WOULD reject — the writer must not check.
$res = snt_ai_og_card_title_write( 50 );
og_true( is_array( $res ), 'writer succeeds with no logged-in user / denied cap' );
og_eq( 'A Punchy Card Title', is_array( $res ) ? $res['title'] : null, 'writer returns the title' );
og_eq( 1, $GLOBALS['__og_gen_calls'], 'writer fired the AI generation regardless of cap' );

// ─── Test 4: writer still honors the AI-availability gate + 404 ──────
echo "\nTest 4: snt_ai_og_card_title_write — availability gate + missing post\n";
og_reset();
$GLOBALS['__og_gate_blocked'] = true;
$res = snt_ai_og_card_title_write( 50 );
og_true( is_wp_error( $res ), 'gate blocked → WP_Error' );
og_eq( 'snt_ai_unavailable', is_wp_error( $res ) ? $res->get_error_code() : null, 'gate error code' );
og_reset();
$res = snt_ai_og_card_title_write( 999 );
og_true( is_wp_error( $res ), 'missing post → WP_Error' );
og_eq( 'snt_ai_post_not_found', is_wp_error( $res ) ? $res->get_error_code() : null, '404 code' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
