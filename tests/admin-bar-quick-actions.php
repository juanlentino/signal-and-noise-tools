<?php
/**
 * Behavioral tests for the v4.7.0 admin-bar quick actions.
 *
 * v4.7.0 adds three quick-action items to the existing grandfathered S&N
 * admin-bar dropdown (inc/admin-bar.php), each wrapping an existing impl:
 *
 *   - Force Update Check  → snt_cmd_impl_force_check()   (busts update caches)
 *   - Scan Pattern Adoption → snt_pattern_adoption_run_scan() (envelope scan)
 *   - Regen OG Card (contextual) → sn_generate_og_card( $post_id )
 *
 * These are BEHAVIOR tests — they drive the AJAX handlers directly and assert
 * their EFFECTS + the JSON they emit, not just registration shape. In
 * particular they pin two defects the v4.6.0 cycle taught us to guard:
 *
 *   1. Scan must report count( $result['candidates'] ) — NOT count( $result ),
 *      which would always be 3 (the envelope's key count).
 *   2. Regen must cap-gate the contextual post_id (>0, exists, edit_post) —
 *      manage_options alone must not let it regen an arbitrary post.
 *
 * Stubs WP functions so the suite runs without a WP load, mirroring
 * tests/abilities-behavior-v460.php harness style. wp_send_json_success /
 * wp_send_json_error are stubbed to THROW a typed exception carrying the
 * payload — the handlers call them and exit in WP, so a throw lets us capture
 * the response and assert against it.
 *
 * @since plugin v4.7.0
 */

// SECURITY: CLI / WP-CLI only — never serve this fixture over HTTP.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── JSON-response capture via typed exceptions ──────────────────────
// In WP, wp_send_json_* echo + die(). Here they throw so the handler's
// control flow stops at the first send and we can inspect the payload.
class SN_AB_JsonResponse extends Exception {
	public $success;
	public $payload;
	public $status;
	public function __construct( $success, $payload, $status ) {
		parent::__construct( 'json' );
		$this->success = $success;
		$this->payload = $payload;
		$this->status  = $status;
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status = 200 ) {
		throw new SN_AB_JsonResponse( true, $data, $status );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status = 200 ) {
		throw new SN_AB_JsonResponse( false, $data, $status );
	}
}

// ─── check_ajax_referer — no-op (nonce verification not under test) ──
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		return 1;
	}
}

// ─── Capability stub: manage_options + per-post edit_post togglable ──
$GLOBALS['__ab_manage_options'] = true;
$GLOBALS['__ab_editable_posts'] = array(); // post_id => bool
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap = '', $object_id = null ) {
		if ( 'manage_options' === $cap ) {
			return ! empty( $GLOBALS['__ab_manage_options'] );
		}
		if ( 'edit_post' === $cap ) {
			return ! empty( $GLOBALS['__ab_editable_posts'][ (int) $object_id ] );
		}
		return true;
	}
}

// ─── get_post stub — existing posts live in __ab_posts ──────────────
$GLOBALS['__ab_posts'] = array(); // post_id => stdClass|null
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = null ) {
		$id = (int) $id;
		return $GLOBALS['__ab_posts'][ $id ] ?? null;
	}
}

// ─── Misc WP stubs needed at file-load + by the menu builder ─────────
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $priority = 10, $args = 1 ) { return true; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
// Context stubs read from globals so Test 5 can drive the admin + front-end
// branches of sn_admin_bar_contextual_post_id() deterministically.
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_is_singular'] = false;
$GLOBALS['__ab_queried_id']  = 0;
$GLOBALS['__ab_screen_base'] = ''; // get_current_screen()->base
// Real WP_Screen carries BOTH ->id and ->base, and sets both to 'site-editor'
// for site-editor.php. sn_admin_bar_destructive_allowed() reads both, so the
// stub has to model both — a stub that omits ->id would make the guard read an
// undefined property and silently answer "allowed" (the stub-drift trap).
// '' means "mirror base", which is what core does for post.php (id === 'post').
$GLOBALS['__ab_screen_id'] = '';
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return ! empty( $GLOBALS['__ab_is_admin'] ); }
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() { return ! empty( $GLOBALS['__ab_is_singular'] ); }
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() { return (int) $GLOBALS['__ab_queried_id']; }
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		$base = (string) $GLOBALS['__ab_screen_base'];
		if ( '' === $base ) {
			return null;
		}
		$id = (string) $GLOBALS['__ab_screen_id'];
		return (object) array( 'base' => $base, 'id' => '' === $id ? $base : $id );
	}
}

// ─── Underlying impls — stub so we observe the handler calling them ──
$GLOBALS['__ab_force_check_called'] = 0;
if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
	function snt_cmd_impl_force_check() {
		$GLOBALS['__ab_force_check_called']++;
		return array( 'ok' => true, 'message' => 'Update caches cleared.' );
	}
}

// Scan returns a 3-KEY ENVELOPE. The candidate COUNT is independent of the
// key count — the whole point of the regression guard.
$GLOBALS['__ab_scan_candidate_count'] = 0;
if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
	function snt_pattern_adoption_run_scan() {
		$n          = (int) $GLOBALS['__ab_scan_candidate_count'];
		$candidates = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$candidates[] = array( 'post_id' => 200 + $i, 'pattern_type' => 'pull-quote' );
		}
		return array(
			'candidates' => $candidates,
			'counts'     => array( 'pull_quote' => $n, 'steps_enumerated' => 0, 'posts_affected' => $n ),
			'scanned_at' => 123456,
		);
	}
}

$GLOBALS['__ab_og_called_for'] = array();
$GLOBALS['__ab_og_should_fail'] = false;
if ( ! function_exists( 'sn_generate_og_card' ) ) {
	function sn_generate_og_card( $post_id ) {
		$GLOBALS['__ab_og_called_for'][] = (int) $post_id;
		return ! $GLOBALS['__ab_og_should_fail'];
	}
}

// ─── Load the SUT ────────────────────────────────────────────────────
require_once __DIR__ . '/../inc/admin-bar.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ab_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ab_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
/**
 * Invoke a handler and return the captured JSON response as an array
 * { success, payload, status } — or a marker if it returned without sending.
 */
function ab_invoke( $fn ) {
	try {
		$fn();
	} catch ( SN_AB_JsonResponse $r ) {
		return array( 'success' => $r->success, 'payload' => $r->payload, 'status' => $r->status );
	}
	return array( 'success' => null, 'payload' => null, 'status' => null );
}

echo "Admin-bar quick actions suite: v4.7.0\n";

/* ════════════════════════════════════════════════════════════════════
 * Test 1 — sn_admin_bar_items() shape: the 3 new actions + Regen guard
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 1: sn_admin_bar_items() registers the new actions with correct labels\n";
$items = sn_admin_bar_items();

ab_true( isset( $items['sn-quick-force-update-check'] ), '1.1: force-update-check item present' );
ab_eq( 'sn_quick_force_update_check', $items['sn-quick-force-update-check']['action'] ?? null, '1.1b: force-update action name' );
ab_eq( '↺ Force Update Check', $items['sn-quick-force-update-check']['label'] ?? null, '1.1c: force-update label (↺ glyph)' );
ab_true( empty( $items['sn-quick-force-update-check']['guard'] ), '1.1d: force-update has NO guard' );

ab_true( isset( $items['sn-quick-scan-patterns'] ), '1.2: scan-patterns item present' );
ab_eq( 'sn_quick_scan_patterns', $items['sn-quick-scan-patterns']['action'] ?? null, '1.2b: scan action name' );
ab_eq( '⌕ Scan Pattern Adoption', $items['sn-quick-scan-patterns']['label'] ?? null, '1.2c: scan label (⌕ glyph)' );
ab_true( empty( $items['sn-quick-scan-patterns']['guard'] ), '1.2d: scan has NO guard' );

ab_true( isset( $items['sn-quick-regen-og-card'] ), '1.3: regen-og-card item present' );
ab_eq( 'sn_quick_regen_og_card', $items['sn-quick-regen-og-card']['action'] ?? null, '1.3b: regen action name' );
ab_eq( '⟳ Regen OG Card', $items['sn-quick-regen-og-card']['label'] ?? null, '1.3c: regen label (⟳ glyph)' );
ab_true( ! empty( $items['sn-quick-regen-og-card']['guard'] ), '1.3d: regen DOES carry a guard (contextual)' );
ab_true( is_callable( $items['sn-quick-regen-og-card']['guard'] ?? null ), '1.3e: regen guard is callable' );

// Glyphs are monochrome Unicode (no emoji). Assert no emoji presentation
// codepoints leaked into the new labels.
foreach ( array( 'sn-quick-force-update-check', 'sn-quick-scan-patterns', 'sn-quick-regen-og-card' ) as $id ) {
	$label = $items[ $id ]['label'];
	ab_true( ! preg_match( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $label ), "1.4: $id label is monochrome (no emoji)" );
}

/* ════════════════════════════════════════════════════════════════════
 * Test 2 — Force Update Check handler dispatches the impl + success JSON
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 2: Force Update Check dispatches snt_cmd_impl_force_check + returns success\n";
$GLOBALS['__ab_manage_options']     = true;
$GLOBALS['__ab_force_check_called'] = 0;
$res = ab_invoke( 'sn_handle_quick_force_update_check' );
ab_eq( 1, $GLOBALS['__ab_force_check_called'], '2.1: snt_cmd_impl_force_check() called exactly once' );
ab_true( true === $res['success'], '2.2: success JSON sent' );
ab_eq( 'Update check forced: see Dashboard › Updates.', $res['payload']['message'] ?? null, '2.3: toast message correct' );

// 2.4 — cap gate: no manage_options → 403 error, impl NOT called.
$GLOBALS['__ab_manage_options']     = false;
$GLOBALS['__ab_force_check_called'] = 0;
$res = ab_invoke( 'sn_handle_quick_force_update_check' );
ab_true( false === $res['success'], '2.4: non-admin → error JSON' );
ab_eq( 403, $res['status'], '2.4b: 403 status' );
ab_eq( 0, $GLOBALS['__ab_force_check_called'], '2.4c: impl NOT called for non-admin' );

/* ════════════════════════════════════════════════════════════════════
 * Test 3 — Scan handler surfaces CANDIDATE count, not envelope-key count (3)
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 3: Scan reports candidate count (not the 3 envelope keys)\n";
$GLOBALS['__ab_manage_options'] = true;

// 3.1 — 5 candidates → "5 candidates", NOT "3".
$GLOBALS['__ab_scan_candidate_count'] = 5;
$res = ab_invoke( 'sn_handle_quick_scan_patterns' );
ab_true( true === $res['success'], '3.1: scan success JSON sent' );
ab_eq( 'Pattern scan complete. 5 candidates.', $res['payload']['message'] ?? null, '3.1b: message reports 5 (candidate count, not 3 envelope keys)' );
ab_true( false === strpos( (string) ( $res['payload']['message'] ?? '' ), '3 candidate' ), '3.1c: message does NOT report 3 (the envelope-key trap)' );

// 3.2 — 1 candidate → singular "candidate".
$GLOBALS['__ab_scan_candidate_count'] = 1;
$res = ab_invoke( 'sn_handle_quick_scan_patterns' );
ab_eq( 'Pattern scan complete. 1 candidate.', $res['payload']['message'] ?? null, '3.2: singular wording for 1 candidate' );

// 3.3 — 0 candidates → "0 candidates" (NOT 3).
$GLOBALS['__ab_scan_candidate_count'] = 0;
$res = ab_invoke( 'sn_handle_quick_scan_patterns' );
ab_eq( 'Pattern scan complete. 0 candidates.', $res['payload']['message'] ?? null, '3.3: zero candidates → 0 (envelope still has 3 keys)' );

/* ════════════════════════════════════════════════════════════════════
 * Test 4 — Regen OG Card cap-gates the contextual post_id
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 4: Regen OG Card validates + cap-gates the post_id\n";
$GLOBALS['__ab_manage_options'] = true;

// 4.1 — post_id 0 (no context) → 400, impl NOT called.
$_POST = array();
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_true( false === $res['success'], '4.1: missing post_id → error' );
ab_eq( 400, $res['status'], '4.1b: 400 status (no context)' );
ab_eq( 0, count( $GLOBALS['__ab_og_called_for'] ), '4.1c: regen impl NOT called' );

// 4.2 — post_id for a NON-EXISTENT post → 400, impl NOT called.
$_POST = array( 'post_id' => '999' );
$GLOBALS['__ab_posts'] = array(); // 999 not present
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_true( false === $res['success'], '4.2: non-existent post → error' );
ab_eq( 400, $res['status'], '4.2b: 400 status' );
ab_eq( 0, count( $GLOBALS['__ab_og_called_for'] ), '4.2c: regen impl NOT called' );

// 4.3 — existing post but user CANNOT edit it → 403, impl NOT called.
$_POST = array( 'post_id' => '42' );
$GLOBALS['__ab_posts'] = array( 42 => (object) array( 'ID' => 42 ) );
$GLOBALS['__ab_editable_posts'] = array( 42 => false ); // not editable
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_true( false === $res['success'], '4.3: non-editable post → error (cap gate)' );
ab_eq( 403, $res['status'], '4.3b: 403 status (edit_post cap denied)' );
ab_eq( 0, count( $GLOBALS['__ab_og_called_for'] ), '4.3c: regen impl NOT called when edit_post denied' );

// 4.4 — valid + editable post → success, impl called for THAT post.
$_POST = array( 'post_id' => '42' );
$GLOBALS['__ab_posts'] = array( 42 => (object) array( 'ID' => 42 ) );
$GLOBALS['__ab_editable_posts'] = array( 42 => true );
$GLOBALS['__ab_og_should_fail'] = false;
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_true( true === $res['success'], '4.4: valid editable post → success' );
ab_eq( array( 42 ), $GLOBALS['__ab_og_called_for'], '4.4b: sn_generate_og_card called with post 42' );
ab_eq( 'OG card regenerated for this post.', $res['payload']['message'] ?? null, '4.4c: success toast' );

// 4.5 — impl returns false → 500 error surfaced.
$_POST = array( 'post_id' => '42' );
$GLOBALS['__ab_og_should_fail'] = true;
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_true( false === $res['success'], '4.5: impl failure → error JSON' );
ab_eq( 500, $res['status'], '4.5b: 500 status on generation failure' );
$GLOBALS['__ab_og_should_fail'] = false;

// 4.6 — non-admin (no manage_options) → 403 before anything else.
$GLOBALS['__ab_manage_options'] = false;
$_POST = array( 'post_id' => '42' );
$GLOBALS['__ab_og_called_for'] = array();
$res = ab_invoke( 'sn_handle_quick_regen_og_card' );
ab_eq( 403, $res['status'], '4.6: non-admin → 403' );
ab_eq( 0, count( $GLOBALS['__ab_og_called_for'] ), '4.6b: impl NOT called for non-admin' );
$GLOBALS['__ab_manage_options'] = true;

/* ════════════════════════════════════════════════════════════════════
 * Test 5 — contextual guard resolves post IDs from admin + front-end
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 5: sn_admin_bar_contextual_post_id resolves context correctly\n";

// 5.1 — no admin + non-singular front-end → 0 (item hidden).
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_is_singular'] = false;
$_GET = array();
ab_eq( 0, sn_admin_bar_contextual_post_id(), '5.1: non-admin, non-singular → 0 (item hidden)' );

// 5.2 — front-end singular → queried object id.
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_is_singular'] = true;
$GLOBALS['__ab_queried_id']  = 77;
ab_eq( 77, sn_admin_bar_contextual_post_id(), '5.2: front-end singular → queried object id' );

// 5.3 — admin post-edit screen (base=post) with ?post=42 → 42.
$GLOBALS['__ab_is_admin']    = true;
$GLOBALS['__ab_is_singular'] = false;
$GLOBALS['__ab_screen_base'] = 'post';
$_GET = array( 'post' => '42' );
ab_eq( 42, sn_admin_bar_contextual_post_id(), '5.3: admin post.php?post=42 → 42' );

// 5.4 — admin list screen (base=edit, not post) → 0 (not a single post).
$GLOBALS['__ab_screen_base'] = 'edit';
$_GET = array( 'post' => '42' );
ab_eq( 0, sn_admin_bar_contextual_post_id(), '5.4: admin list screen (base=edit) → 0' );

// 5.5 — admin post screen but NO ?post= (e.g. post-new) → 0.
$GLOBALS['__ab_screen_base'] = 'post';
$_GET = array();
ab_eq( 0, sn_admin_bar_contextual_post_id(), '5.5: admin post screen with no ?post= → 0' );
$_GET = array();

/* ════════════════════════════════════════════════════════════════════
 * Test 6 — WP 7.1: the destructive item is hidden in the Site Editor.
 *
 * 7.1 makes the toolbar persistent in the Site Editor, which never showed it.
 * "Clear DB Overrides" force-deletes (no trash, no undo) every wp_template /
 * wp_template_part / wp_navigation — the exact records the Site Editor writes.
 * Its only previous protection was the toolbar's absence there.
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest 6: destructive item hidden in the Site Editor (WP 7.1 toolbar persistence)\n";

$items = sn_admin_bar_items();
ab_true( ! empty( $items['sn-quick-clear-overrides']['guard'] ), '6.1: clear-overrides DOES carry a guard' );
ab_eq( 'sn_admin_bar_destructive_allowed', $items['sn-quick-clear-overrides']['guard'] ?? null, '6.1b: guard is the destructive-screen gate' );
ab_true( is_callable( $items['sn-quick-clear-overrides']['guard'] ?? null ), '6.1c: guard is callable' );

// Front end: positively not the Site Editor → allowed (unchanged behaviour).
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_screen_base'] = '';
$GLOBALS['__ab_screen_id']   = '';
ab_true( true === sn_admin_bar_destructive_allowed(), '6.2: front end → allowed' );

// Site Editor, both properties set the way core sets them → hidden.
$GLOBALS['__ab_is_admin']    = true;
$GLOBALS['__ab_screen_base'] = 'site-editor';
$GLOBALS['__ab_screen_id']   = 'site-editor';
ab_true( false === sn_admin_bar_destructive_allowed(), '6.3: Site Editor → hidden' );

// Only ->id carries it (core keeps base as something else): still hidden.
$GLOBALS['__ab_screen_base'] = 'toplevel_page_whatever';
$GLOBALS['__ab_screen_id']   = 'site-editor';
ab_true( false === sn_admin_bar_destructive_allowed(), '6.4: site-editor on ->id alone → hidden (not coupled to one property)' );

// Only ->base carries it: still hidden.
$GLOBALS['__ab_screen_base'] = 'site-editor';
$GLOBALS['__ab_screen_id']   = 'some-other-id';
ab_true( false === sn_admin_bar_destructive_allowed(), '6.5: site-editor on ->base alone → hidden' );

// Post editor keeps the item — availability users already have is not removed,
// and a template override is not what a post author is editing.
$GLOBALS['__ab_screen_base'] = 'post';
$GLOBALS['__ab_screen_id']   = 'post';
ab_true( true === sn_admin_bar_destructive_allowed(), '6.6: post editor → still allowed (no availability regression)' );

// Ordinary admin screen: allowed.
$GLOBALS['__ab_screen_base'] = 'appearance_page_sn-theme-options';
$GLOBALS['__ab_screen_id']   = 'appearance_page_sn-theme-options';
ab_true( true === sn_admin_bar_destructive_allowed(), '6.7: plugin settings screen → allowed' );

// Unresolvable screen inside admin: fail CLOSED. An unhidden force-delete is a
// worse outcome than a missing menu row, and hiding the row never gates the
// action — the AJAX handler owns nonce + capability either way.
$GLOBALS['__ab_screen_base'] = '';
$GLOBALS['__ab_screen_id']   = '';
ab_true( false === sn_admin_bar_destructive_allowed(), '6.8: admin with unresolvable screen → hidden (fails closed)' );

// The guard returns a BOOL, never an int. The menu builder forwards a positive
// int guard value as the item's postId (that is how Regen OG Card passes its
// post); a truthy int here would attach a bogus post_id to a destructive action.
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_screen_base'] = '';
$GLOBALS['__ab_screen_id']   = '';
$allowed = sn_admin_bar_destructive_allowed();
ab_true( is_bool( $allowed ), '6.9: guard returns a bool, so no postId is ever derived from it' );
ab_true( ! is_int( $allowed ), '6.9b: guard is not an int (the postId-carrying shape)' );

// Reset shared state for any later section.
$GLOBALS['__ab_is_admin']    = false;
$GLOBALS['__ab_screen_base'] = '';
$GLOBALS['__ab_screen_id']   = '';

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
