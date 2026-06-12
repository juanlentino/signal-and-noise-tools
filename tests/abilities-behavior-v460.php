<?php
/**
 * Behavioral tests for the v4.6.0 ability fixes (adversarial-review remediation).
 *
 * These are BEHAVIOR tests — earlier suites asserted registration shape, which
 * is exactly why the defects below shipped. Here we drive the ability
 * execute_callbacks directly and assert their EFFECTS:
 *
 *   - run-cron-event: delegates to snt_cron_run_event_impl, so an orphan hook
 *     (no callbacks) is rejected (impl's has_action() WP_Error), a registered
 *     hook dispatches ok:true, and a throwing callback yields ok:false (not a
 *     fatal — impl's Throwable catch).
 *   - pattern-adoption-dismiss: writes the `pattern_type:fingerprint` key into
 *     the `_snt_pattern_adoption_dismissed` POST-META (the real store the
 *     scanner reads), not the dead `sn_pattern_adoption_dismissed` option.
 *   - pattern-adoption-scan: reads the envelope's `candidates` list, so the
 *     returned count === N candidates (not 3 = count of envelope keys).
 *
 * Stubs WP functions so the suite runs without a WP load, mirroring
 * tests/pattern-adoption-detect.php + tests/cron-dashboard.php harness style.
 *
 * @since plugin v4.6.0
 */

// SECURITY: Prevent web access. This file is a test fixture, not a runtime
// module. Direct HTTP GET to this path would leak internal structure. Allow
// only CLI / WP-CLI invocations.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'PHP_INT_MAX' ) ) {
	// PHP_INT_MAX is always defined; guard kept for parity with other suites.
	define( 'PHP_INT_MAX', 9223372036854775807 );
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

// ─── add_action capturing stub (real callback registry) ──────────────
// snt_cron_run_event_impl registers its tracker + dispatches via
// do_action_ref_array, so we implement a working action registry rather
// than a no-op. has_action() must reflect what we register.
$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_actions'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $tag, $callback = false ) {
		return ! empty( $GLOBALS['__test_actions'][ $tag ] );
	}
}
// v4.9.0: cron-dashboard.php registers a Site Health filter + REST route at
// module scope (Task 2). Stub the registration helpers so the module loads.
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() {} }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://x/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( $tag, $args ) {
		if ( empty( $GLOBALS['__test_actions'][ $tag ] ) ) {
			return;
		}
		foreach ( $GLOBALS['__test_actions'][ $tag ] as $cb ) {
			call_user_func_array( $cb, is_array( $args ) ? $args : array() );
		}
	}
}

// ─── Capability + misc WP stubs ──────────────────────────────────────
$GLOBALS['__test_manage_options'] = true;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap = '', $object_id = null ) {
		if ( 'manage_options' === $cap ) {
			return ! empty( $GLOBALS['__test_manage_options'] );
		}
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?: time() ); }
}
if ( ! function_exists( 'current_action' ) ) {
	// Real snt_cron_track_last_fired_cb() (loaded from cron-dashboard.php)
	// calls current_action(); during our synchronous dispatch we don't track
	// a "current" action, so return ''. snt_cron_record_last_fired() no-ops
	// on an empty hook, which is the desired behavior under test.
	function current_action() { return ''; }
}

// ─── Post-meta store stub (the REAL dismiss target) ──────────────────
$GLOBALS['__test_post_meta'] = array();
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$val = $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
		return $val;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['__test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

// ─── Option store stub — the DEAD store. We assert it stays UNTOUCHED. ─
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['__test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__test_options'][ $key ] = $value;
		return true;
	}
}

// ─── Transient stub (dismiss invalidates the user's scan transient) ───
$GLOBALS['__test_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['__test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $ttl = 0 ) { $GLOBALS['__test_transients'][ $key ] = $val; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { unset( $GLOBALS['__test_transients'][ $key ] ); return true; }
}

// ─── Cron-history stub (snt_cron_run_event_impl optional dep) ─────────
// We do NOT load inc/cron-history.php, so snt_cron_history_record() is
// absent. Define it here BEFORE requiring cron-dashboard.php so the impl's
// function_exists() guard routes through and exercises the history-record
// call path. (snt_cron_last_fired_for + snt_cron_track_last_fired_cb are
// defined by cron-dashboard.php itself — we must NOT redeclare them.)
$GLOBALS['__test_history_records'] = array();
if ( ! function_exists( 'snt_cron_history_record' ) ) {
	function snt_cron_history_record( $hook, $args, $elapsed_ms, $success, $error ) {
		$GLOBALS['__test_history_records'][] = compact( 'hook', 'success', 'error' );
		return true;
	}
}

// ─── Load the SUTs ───────────────────────────────────────────────────
// cron-dashboard.php provides snt_cron_run_event_impl (the real impl A delegates to).
require_once __DIR__ . '/../inc/cron-dashboard.php';
// pattern-adoption-admin.php provides snt_pattern_adoption_dismiss_impl.
require_once __DIR__ . '/../inc/pattern-adoption-admin.php';

// The ability callbacks live in abilities-*.php. Those files call add_action()
// at top level to register abilities (captured harmlessly by our stub) and
// then DEFINE the callback functions — which is what we drive.
require_once __DIR__ . '/../inc/abilities-cron.php';
require_once __DIR__ . '/../inc/abilities-pattern-adoption.php';

// ─── Override snt_pattern_adoption_run_scan with a fixture envelope ───
// abilities-pattern-adoption.php references it via function_exists(); we
// define it here BEFORE first call so the scan ability routes to it.
$GLOBALS['__scan_candidate_count'] = 0;
if ( ! function_exists( 'snt_pattern_adoption_run_scan' ) ) {
	function snt_pattern_adoption_run_scan() {
		$n          = (int) $GLOBALS['__scan_candidate_count'];
		$candidates = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$candidates[] = array( 'post_id' => 100 + $i, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'fp' . $i );
		}
		return array(
			'candidates' => $candidates,
			'counts'     => array( 'pull_quote' => $n, 'steps_enumerated' => 0, 'posts_affected' => $n ),
			'scanned_at' => 123456,
		);
	}
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function bx_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function bx_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Abilities behavior suite — v4.6.0 adversarial-review remediation\n";

/* ════════════════════════════════════════════════════════════════════
 * FIX A — run-cron-event delegates to snt_cron_run_event_impl
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest A: run-cron-event delegates to the proven impl\n";

// A.1 — empty hook → WP_Error (ability-level guard).
$r = snt_ability_run_cron_event( array( 'hook' => '' ) );
bx_true( is_wp_error( $r ) && 'snt_invalid_hook' === $r->get_error_code(), 'A.1: empty hook → snt_invalid_hook WP_Error' );

// A.2 — sn_* hook → refused (ability-level pre-filter, never reaches impl).
$r = snt_ability_run_cron_event( array( 'hook' => 'sn_rss_tracker_daily_prune' ) );
bx_true( is_wp_error( $r ) && 'snt_sn_hook_refused' === $r->get_error_code(), 'A.2: sn_* hook → snt_sn_hook_refused WP_Error' );

// A.3 — ORPHAN hook (no callbacks) → impl's has_action() guard returns WP_Error.
//        This is THE regression test: the old body did do_action on nothing
//        and returned ok:true. Delegation now surfaces the orphan error.
$GLOBALS['__test_actions'] = array();
$r = snt_ability_run_cron_event( array( 'hook' => 'orphan_hook_xyz' ) );
bx_true( is_wp_error( $r ), 'A.3: orphan hook → WP_Error (delegated has_action guard), not ok:true' );
bx_eq( 'snt_cron_no_handler', is_wp_error( $r ) ? $r->get_error_code() : null, 'A.3b: orphan error code is snt_cron_no_handler' );

// A.4 — REGISTERED hook dispatches → ok:true.
$GLOBALS['__test_actions'] = array();
$GLOBALS['__ran_ok_hook'] = false;
add_action( 'sntbx_registered_hook', function() { $GLOBALS['__ran_ok_hook'] = true; } );
$r = snt_ability_run_cron_event( array( 'hook' => 'sntbx_registered_hook' ) );
bx_true( is_array( $r ) && ! empty( $r['ok'] ), 'A.4: registered hook → ok:true' );
bx_true( $GLOBALS['__ran_ok_hook'], 'A.4b: the registered callback actually fired' );

// A.5 — THROWING callback → ok:false (impl Throwable catch), NOT a fatal.
$GLOBALS['__test_actions'] = array();
add_action( 'sntbx_throwing_hook', function() { throw new RuntimeException( 'boom from handler' ); } );
$r = snt_ability_run_cron_event( array( 'hook' => 'sntbx_throwing_hook' ) );
bx_true( is_array( $r ) && empty( $r['ok'] ), 'A.5: throwing callback → ok:false (no fatal)' );
bx_true( is_array( $r ) && false !== strpos( (string) $r['message'], 'Dispatch failed' ), 'A.5b: message reports dispatch failure' );

// A.6 — mixed-case / namespaced hook NOT mangled (no sanitize_key).
//        Register under the verbatim mixed-case name; the ability must match it.
$GLOBALS['__test_actions'] = array();
$GLOBALS['__ran_mixed'] = false;
add_action( 'MyPlugin\\Do_Thing', function() { $GLOBALS['__ran_mixed'] = true; } );
$r = snt_ability_run_cron_event( array( 'hook' => 'MyPlugin\\Do_Thing' ) );
bx_true( is_array( $r ) && ! empty( $r['ok'] ) && $GLOBALS['__ran_mixed'], 'A.6: mixed-case namespaced hook matched verbatim (no sanitize_key mangling)' );

/* ════════════════════════════════════════════════════════════════════
 * FIX B — pattern-adoption-dismiss writes the REAL post-meta store
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest B: pattern-adoption-dismiss writes _snt_pattern_adoption_dismissed post-meta\n";

$GLOBALS['__test_post_meta'] = array();
$GLOBALS['__test_options']   = array();
$GLOBALS['__test_transients']['snt_pattern_adoption_candidates_1'] = array( 'stale' => true );

$r = snt_ability_pattern_adoption_dismiss( array(
	'post_id'           => 42,
	'pattern_type'      => 'pull-quote',
	'block_fingerprint' => 'abc123',
) );
bx_true( is_array( $r ) && ! empty( $r['ok'] ), 'B.1: dismiss returns ok:true' );

$meta = get_post_meta( 42, '_snt_pattern_adoption_dismissed', true );
bx_true( is_array( $meta ) && in_array( 'pull-quote:abc123', $meta, true ), 'B.2: key "pull-quote:abc123" written to the REAL post-meta store' );

// The dead option must NEVER be touched.
bx_true( ! isset( $GLOBALS['__test_options']['sn_pattern_adoption_dismissed'] ), 'B.3: dead option sn_pattern_adoption_dismissed NOT written' );

// Scan transient invalidated.
bx_true( ! isset( $GLOBALS['__test_transients']['snt_pattern_adoption_candidates_1'] ), 'B.4: user scan transient invalidated' );

// B.5 — idempotent: second dismiss of the same key is a no-op — the stored
//        array does NOT grow. (We compare counts before/after rather than
//        asserting ==1: real WP get_post_meta(...,true) returns '' for an
//        absent key, and the impl's `(array) ''` seeds a harmless empty-string
//        element — pre-existing behavior the REST handler shares verbatim, so
//        idempotency is "count unchanged", not "count == 1".)
$count_before = count( (array) get_post_meta( 42, '_snt_pattern_adoption_dismissed', true ) );
$r2 = snt_ability_pattern_adoption_dismiss( array(
	'post_id'           => 42,
	'pattern_type'      => 'pull-quote',
	'block_fingerprint' => 'abc123',
) );
bx_true( is_array( $r2 ) && ! empty( $r2['ok'] ), 'B.5: repeat dismiss returns ok:true (no-op)' );
$meta2 = (array) get_post_meta( 42, '_snt_pattern_adoption_dismissed', true );
bx_eq( $count_before, count( $meta2 ), 'B.5b: stored array did not grow on repeat dismiss (idempotent)' );
bx_true( in_array( 'pull-quote:abc123', $meta2, true ), 'B.5c: the dismissed key is still present after repeat' );

// B.6 — validation: missing pattern_type → ok:false, no write.
$GLOBALS['__test_post_meta'] = array();
$r3 = snt_ability_pattern_adoption_dismiss( array( 'post_id' => 7, 'pattern_type' => '', 'block_fingerprint' => 'x' ) );
bx_true( is_array( $r3 ) && empty( $r3['ok'] ), 'B.6: empty pattern_type → ok:false' );
bx_true( ! isset( $GLOBALS['__test_post_meta'][7] ), 'B.6b: no post-meta written on invalid input' );

// B.7 — validation: post_id <= 0 → ok:false.
$r4 = snt_ability_pattern_adoption_dismiss( array( 'post_id' => 0, 'pattern_type' => 'pull-quote', 'block_fingerprint' => 'x' ) );
bx_true( is_array( $r4 ) && empty( $r4['ok'] ), 'B.7: post_id 0 → ok:false' );

/* ════════════════════════════════════════════════════════════════════
 * FIX C — pattern-adoption-scan reads the envelope's candidate list
 * ════════════════════════════════════════════════════════════════════ */
echo "\nTest C: pattern-adoption-scan returns real candidate count (not 3)\n";

// C.1 — 5 candidates → count === 5 (NOT 3 = count of envelope keys).
$GLOBALS['__scan_candidate_count'] = 5;
$r = snt_ability_pattern_adoption_scan( null );
bx_true( is_array( $r ) && ! empty( $r['ok'] ), 'C.1: scan returns ok:true' );
bx_eq( 5, $r['count'], 'C.1b: count === 5 (real candidate count, not 3 envelope keys)' );
bx_eq( 5, is_array( $r['candidates'] ) ? count( $r['candidates'] ) : -1, 'C.1c: candidates array has 5 entries' );

// C.2 — 0 candidates → count === 0 (proves it is NOT the envelope-key count of 3).
$GLOBALS['__scan_candidate_count'] = 0;
$r = snt_ability_scan_zero_check();
bx_eq( 0, $r['count'], 'C.2: zero candidates → count 0 (envelope still has 3 keys)' );
bx_eq( 0, count( $r['candidates'] ), 'C.2b: candidates empty' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );

/**
 * Tiny indirection so the C.2 call site reads clearly; avoids a second
 * inline closure. Returns the scan ability output for the current fixture.
 */
function snt_ability_scan_zero_check() {
	return snt_ability_pattern_adoption_scan( null );
}
