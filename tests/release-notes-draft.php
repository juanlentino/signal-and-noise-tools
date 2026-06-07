<?php
/**
 * Standalone fixture tests for inc/release-notes-draft.php (v4.11.0, Task 4).
 *
 * Pure-PHP CLI harness (no PHPUnit), matching tests/insights.php conventions.
 * Stubs the AI gate (snt_ai_require_text_generation) + the generation helper
 * (snt_ai_generate_with_constraints — capturing its args) so we can assert the
 * impl's OBSERVABLE behavior: empty rejection, the ~4000-char input hard-cap
 * (truncation BEFORE the call), the system instruction being passed, and a
 * normal delta returning the (stubbed) markdown.
 *
 * Run: php tests/release-notes-draft.php
 *
 * @since plugin v4.11.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $d = array() ) {
		$this->code    = $c;
		$this->message = $m;
		$this->data    = $d;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// ─── AI stubs (overridable per-test via globals) ──────────────────────
$GLOBALS['__gate_return']   = null;  // null = AI available
$GLOBALS['__gen_calls']     = array();
$GLOBALS['__gen_return']    = "### New\n- Stubbed line";

function snt_ai_require_text_generation() {
	return $GLOBALS['__gate_return'];
}
function snt_ai_generate_with_constraints( $prompt, $system_instruction, $max_tokens = 256 ) {
	$GLOBALS['__gen_calls'][] = array(
		'prompt'      => $prompt,
		'system'      => $system_instruction,
		'max_tokens'  => $max_tokens,
	);
	return $GLOBALS['__gen_return'];
}

require_once __DIR__ . '/../inc/release-notes-draft.php';

// ─── tiny assert harness ──────────────────────────────────────────────
$pass = 0; $fail = 0;
function rn_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function rn_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}
function rn_reset() {
	$GLOBALS['__gate_return'] = null;
	$GLOBALS['__gen_calls']   = array();
	$GLOBALS['__gen_return']  = "### New\n- Stubbed line";
}

// ─── Test 1: empty / whitespace input → WP_Error('snt_rn_empty') ─────
echo "\nTest 1: empty + whitespace input → snt_rn_empty (no AI call)\n";
rn_reset();
$res = snt_release_notes_draft_impl( '' );
rn_true( is_wp_error( $res ), 'empty string returns WP_Error' );
rn_eq( 'snt_rn_empty', $res->get_error_code(), 'empty → code snt_rn_empty' );
rn_eq( 0, count( $GLOBALS['__gen_calls'] ), 'empty short-circuits BEFORE the AI call' );

rn_reset();
$res = snt_release_notes_draft_impl( "   \n\t  " );
rn_true( is_wp_error( $res ), 'whitespace-only returns WP_Error' );
rn_eq( 'snt_rn_empty', $res->get_error_code(), 'whitespace → code snt_rn_empty' );

// ─── Test 2: gate WP_Error propagates (no AI call) ────────────────────
echo "\nTest 2: AI gate WP_Error propagates before any work\n";
rn_reset();
$GLOBALS['__gate_return'] = new WP_Error( 'snt_ai_unavailable', 'nope', array( 'status' => 503 ) );
$res = snt_release_notes_draft_impl( 'Added a thing. Fixed a bug.' );
rn_true( is_wp_error( $res ), 'gate error returns WP_Error' );
rn_eq( 'snt_ai_unavailable', $res->get_error_code(), 'propagates the gate code' );
rn_eq( 0, count( $GLOBALS['__gen_calls'] ), 'gate failure short-circuits BEFORE the AI call' );

// ─── Test 3: normal delta → stubbed markdown, system passed, 700 cap ──
echo "\nTest 3: normal delta returns the markdown + passes the system instruction\n";
rn_reset();
$delta = "- Added a release-notes drafter\n- Fixed the palette guard";
$res   = snt_release_notes_draft_impl( $delta );
rn_eq( "### New\n- Stubbed line", $res, 'returns the (stubbed) markdown string' );
rn_eq( 1, count( $GLOBALS['__gen_calls'] ), 'exactly one AI call' );
$call = $GLOBALS['__gen_calls'][0];
rn_true( defined( 'SNT_RELEASE_NOTES_SYSTEM' ), 'SNT_RELEASE_NOTES_SYSTEM is defined' );
rn_eq( SNT_RELEASE_NOTES_SYSTEM, $call['system'], 'the Mimestream system instruction is passed' );
rn_eq( 700, $call['max_tokens'], 'max_tokens cap is 700' );
rn_true( false !== strpos( $call['system'], '### New' ), 'system instruction names the New section' );
rn_true( false !== strpos( $call['system'], '### Improvements' ), 'system instruction names the Improvements section' );
rn_true( false !== strpos( $call['system'], '### Fixed' ), 'system instruction names the Fixed section' );

// ─── Test 4: prompt within cap is passed verbatim (trimmed) ───────────
echo "\nTest 4: a short delta is passed through (trimmed) unchanged\n";
rn_reset();
$res = snt_release_notes_draft_impl( "  keep me  " );
rn_eq( 'keep me', $GLOBALS['__gen_calls'][0]['prompt'], 'trims surrounding whitespace before the call' );

// ─── Test 5: over-length delta is hard-capped BEFORE the call ─────────
echo "\nTest 5: input over the ~4000-char cap is truncated before the AI call\n";
rn_reset();
// 6000 chars of word-ish content (space-separated 4-char tokens).
$big = trim( str_repeat( 'word ', 1200 ) ); // ~6000 chars
rn_true( strlen( $big ) > 4500, 'fixture delta exceeds the cap (sanity)' );
$res = snt_release_notes_draft_impl( $big );
$sent = $GLOBALS['__gen_calls'][0]['prompt'];
rn_true( strlen( $sent ) <= 4000, 'prompt sent to AI is <= 4000 chars (hard cap)' );
rn_true( strlen( $sent ) > 0, 'prompt sent is non-empty after truncation' );
// Word-boundary truncation must not split a token: the sent prompt should be a
// prefix of the original (modulo trim), ending on a whole 'word'.
rn_true( 0 === strpos( $big, $sent ) || 0 === strpos( $big, rtrim( $sent ) ), 'truncation is a prefix of the input' );
rn_true( false === strpos( rtrim( $sent ), 'wor ' ) && substr( rtrim( $sent ), -3 ) !== 'wor', 'truncation respects the word boundary (no split token)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
