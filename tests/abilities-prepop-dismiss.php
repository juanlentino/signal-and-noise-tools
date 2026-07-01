<?php
/**
 * Behavioral tests for the signal-noise/prepop-dismiss ability (plugin v6.55.0).
 *
 * This ability is the Abilities-API replacement for the legacy
 * POST /signal-noise/v1/prepop/dismiss REST route (inc/ai-prepopulate-notice.php),
 * built so the prepop-notice JS caller can migrate to the run-path — the last
 * blocker on deprecating + eventually removing that route.
 *
 * These are BEHAVIOR tests: we drive the execute_callback and assert its EFFECT
 * (the three prepop sentinels are actually deleted from post meta via the real
 * sn_prepop_clear_sentinels), not merely that a spy was called. Registration
 * shape (slug, permission, input schema) is asserted separately.
 *
 * Stubs WP functions so the suite runs without a WP load, mirroring
 * tests/abilities-behavior-v460.php harness style.
 *
 * @since plugin v6.55.0
 */

// SECURITY: CLI / WP-CLI only. Direct HTTP GET to a test fixture would leak
// internal structure; this file is not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP_Error + is_wp_error ─────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $c = '', $m = '', $d = array() ) {
			$this->code    = $c;
			$this->message = $m;
			$this->data    = $d;
		}
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// ─── Registration capture stubs (add_action + wp_register_ability) ────
$GLOBALS['__acts'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__acts'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { return true; }
}
$GLOBALS['__ab'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $slug, $args ) {
		$GLOBALS['__ab'][ $slug ] = $args;
		return true;
	}
}

// ─── Capability stub (for the edit_post permission callback) ──────────
$GLOBALS['__can_edit'] = true;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap = '', $object_id = null ) {
		if ( 'edit_post' === $cap ) {
			return ! empty( $GLOBALS['__can_edit'] );
		}
		return true;
	}
}

// ─── Post-meta store stub (the REAL dismiss target) ───────────────────
$GLOBALS['__meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( '' === $key ) {
			return $GLOBALS['__meta'][ $post_id ] ?? array();
		}
		return $GLOBALS['__meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $GLOBALS['__meta'][ (int) $post_id ][ $key ] );
		return true;
	}
}

// ─── Load the SUTs ────────────────────────────────────────────────────
// ai-prepopulate.php provides the REAL sn_prepop_fields + sn_prepop_clear_sentinels.
require_once __DIR__ . '/../inc/ai-prepopulate.php';
// abilities-permission-helpers.php provides snt_ability_perm_edit_post.
require_once __DIR__ . '/../inc/abilities-permission-helpers.php';
// The SUT: the new ability registration + its execute_callback.
require_once __DIR__ . '/../inc/abilities-prepop-dismiss.php';

// Invoke captured wp_abilities_api_init closures to run the registration.
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) {
	$cb();
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function px_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function px_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "signal-noise/prepop-dismiss ability — behavioral suite (v6.55.0)\n";

/* ════════════════════════════════════════════════════════════════════
 * A — Registration shape (the run-path contract clients depend on)
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest A: registration shape\n";
$reg = $GLOBALS['__ab']['signal-noise/prepop-dismiss'] ?? null;
px_true( is_array( $reg ), 'A.1: signal-noise/prepop-dismiss is registered' );
px_eq( 'snt_ability_prepop_dismiss', $reg['execute_callback'] ?? null, 'A.2: execute_callback = snt_ability_prepop_dismiss' );
px_eq( 'snt_ability_perm_edit_post', $reg['permission_callback'] ?? null, 'A.3: permission_callback = snt_ability_perm_edit_post (per-post edit cap, not blanket)' );
px_true( isset( $reg['input_schema']['required'] ) && in_array( 'post_id', $reg['input_schema']['required'], true ), 'A.4: input_schema requires post_id' );
px_eq( 'integer', $reg['input_schema']['properties']['post_id']['type'] ?? null, 'A.5: post_id typed integer' );
px_true( ! empty( $reg['meta']['annotations']['idempotent'] ), 'A.6: annotated idempotent' );

/* ════════════════════════════════════════════════════════════════════
 * B — Behavior: clears the REAL prepop sentinels on the post
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest B: dismiss clears the three prepop sentinels\n";
$sentinels = array_keys( sn_prepop_fields() );
px_eq( 3, count( $sentinels ), 'B.0: sn_prepop_fields() exposes the 3 sentinels' );

$GLOBALS['__meta'][77] = array();
foreach ( $sentinels as $s ) {
	$GLOBALS['__meta'][77][ $s ] = '1';
}
$r = snt_ability_prepop_dismiss( array( 'post_id' => 77 ) );
px_true( is_array( $r ) && ! empty( $r['ok'] ), 'B.1: returns { ok: true }' );
$all_cleared = true;
foreach ( $sentinels as $s ) {
	if ( '' !== get_post_meta( 77, $s, true ) ) {
		$all_cleared = false;
	}
}
px_true( $all_cleared, 'B.2: all three sentinels deleted from post meta (real sn_prepop_clear_sentinels)' );

/* ════════════════════════════════════════════════════════════════════
 * C — Input validation (fail closed on a bad post_id)
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest C: input validation\n";
$r0 = snt_ability_prepop_dismiss( array( 'post_id' => 0 ) );
px_true( is_wp_error( $r0 ) && 'snt_prepop_invalid_post' === $r0->get_error_code(), 'C.1: post_id 0 → snt_prepop_invalid_post WP_Error' );
$rm = snt_ability_prepop_dismiss( array() );
px_true( is_wp_error( $rm ), 'C.2: missing post_id → WP_Error (no clear attempted)' );

/* ════════════════════════════════════════════════════════════════════
 * D — Idempotent: dismissing an already-clear post is a no-op ok:true
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest D: idempotency\n";
$r2 = snt_ability_prepop_dismiss( array( 'post_id' => 77 ) );
px_true( is_array( $r2 ) && ! empty( $r2['ok'] ), 'D.1: repeat dismiss on a clear post → ok:true (no error)' );

/* ════════════════════════════════════════════════════════════════════
 * E — Permission parity with the legacy route (edit_post on the post)
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest E: permission callback gates on edit_post(post_id)\n";
$GLOBALS['__can_edit'] = true;
px_true( snt_ability_perm_edit_post( array( 'post_id' => 77 ) ), 'E.1: capable user → permitted' );
$GLOBALS['__can_edit'] = false;
px_true( ! snt_ability_perm_edit_post( array( 'post_id' => 77 ) ), 'E.2: incapable user → denied' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
