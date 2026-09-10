<?php
/**
 * Behavioral tests for the weekly-digest (narration) abilities (plugin v7.0.0).
 *
 *   - signal-noise/run-narration  (force a fresh digest; wraps snt_narration_run)
 *   - signal-noise/get-narration   (return the cached digest; wraps snt_narration_last)
 *
 * The narration logic itself is covered by tests/insights-narration.php; these
 * assert the ability CONTRACT (registration shape) + that the thin wrappers
 * delegate correctly (force passed through, return value propagated). The impl
 * helpers are stubbed at the delegation boundary.
 *
 * @since plugin v7.0.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

$GLOBALS['__acts'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $t ][] = $cb; return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
$GLOBALS['__ab'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; return true; } }

// Delegation boundary: stub the narration impl helpers (real ones live in
// inc/insights-narration.php and are AI-driven — covered by their own suite).
// v9.51.2: run-narration is ASYNC now — it calls snt_narration_schedule (not
// snt_narration_run) and reads snt_narration_last, never generating inline.
$GLOBALS['__narr_last']       = null;
$GLOBALS['__narr_sched_force'] = 'UNSET';
$GLOBALS['__narr_sched_ret']  = true;
function snt_narration_last() { return $GLOBALS['__narr_last']; }
function snt_narration_schedule( $force = false ) { $GLOBALS['__narr_sched_force'] = $force; return $GLOBALS['__narr_sched_ret']; }
$GLOBALS['__narr_last_error'] = null;
function snt_narration_last_error() { return $GLOBALS['__narr_last_error']; }

require __DIR__ . '/../inc/abilities-narration.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "narration abilities — v7.0.0\n";

// ── run-narration registration ──
$run = $GLOBALS['__ab']['signal-noise/run-narration'] ?? null;
ok( is_array( $run ), 'run-narration registered' );
ok( 'snt_ability_run_narration' === ( $run['execute_callback'] ?? '' ), 'run-narration execute_callback wired' );
ok( 'snt_ability_perm_manage_options' === ( $run['permission_callback'] ?? '' ), 'run-narration gated on manage_options' );
ok( isset( $run['input_schema']['properties']['force'] ), 'run-narration accepts force' );
ok( empty( $run['meta']['annotations']['readonly'] ) && false === ( $run['meta']['annotations']['idempotent'] ?? null ), 'run-narration is NOT readonly/idempotent (regenerates via AI)' );

// ── get-narration registration ──
$get = $GLOBALS['__ab']['signal-noise/get-narration'] ?? null;
ok( is_array( $get ), 'get-narration registered' );
ok( 'snt_ability_get_narration' === ( $get['execute_callback'] ?? '' ), 'get-narration execute_callback wired' );
ok( 'snt_ability_perm_manage_options' === ( $get['permission_callback'] ?? '' ), 'get-narration gated on manage_options' );
ok( true === ( $get['meta']['annotations']['readonly'] ?? null ) && true === ( $get['meta']['annotations']['idempotent'] ?? null ), 'get-narration is readonly + idempotent' );

// ── delegation behavior ──
ok( null === snt_ability_get_narration( null ), 'get-narration returns null when no digest cached AND no recorded failure (cold)' );
$GLOBALS['__narr_last'] = array( 'generated_at' => 999, 'headline' => 'Traffic up 12%', 'paragraphs' => array( 'a' ), 'highlights' => array( 'x' ) );
$g = snt_ability_get_narration( null );
ok( is_array( $g ) && 'Traffic up 12%' === $g['headline'], 'get-narration returns the cached digest verbatim' );
ok( 'ready' === ( $g['state'] ?? '' ), '   ...and stamps it state:ready' );

// ── the two nulls, split (2026-09-10, measured under a depleted API account) ──
// A dead provider used to be indistinguishable from "nothing generated yet":
// the cron handler discarded the WP_Error and get-narration returned null for
// both. Now a recorded failure surfaces as state:unavailable with its reason,
// while a genuinely cold cache stays null — the case every reader already handles.
echo "\nGroup: a dead provider is reported as a fault, not as an empty digest\n";
$GLOBALS['__narr_last']       = null;
$GLOBALS['__narr_last_error'] = array( 'code' => 'snt_ai_unavailable', 'message' => 'Provider refused: credit exhausted.', 'raw' => '', 'at' => 1757500000 );
$u = snt_ability_get_narration( null );
ok( is_array( $u ) && 'unavailable' === ( $u['state'] ?? '' ), 'no digest + recorded failure => state:unavailable' );
ok( 'snt_ai_unavailable' === ( $u['reason'] ?? '' ), '   ...carrying the failure code as reason' );
ok( 1757500000 === ( $u['failed_at'] ?? null ), '   ...and when it failed' );
ok( array_key_exists( 'headline', $u ) && null === $u['headline'] && array() === $u['paragraphs'], '   ...with an EMPTY digest body, so nothing reads as content' );
// The failure must not shadow a real digest: a cached digest wins even if a
// stale error row is still lying around from before it was generated.
$GLOBALS['__narr_last'] = array( 'generated_at' => 1000, 'headline' => 'Recovered', 'paragraphs' => array(), 'highlights' => array() );
$r = snt_ability_get_narration( null );
ok( 'ready' === ( $r['state'] ?? '' ) && 'Recovered' === ( $r['headline'] ?? '' ), 'a cached digest outranks a lingering failure row' );
$GLOBALS['__narr_last']       = null;
$GLOBALS['__narr_last_error'] = null;
ok( null === snt_ability_get_narration( null ), 'and with neither, still null (the cold case did not become a fault)' );
// Schema: the new keys are declared, so a client validating output does not
// reject the fault it was given.
$get_schema = $GLOBALS['__ab']['signal-noise/get-narration']['output_schema']['properties'] ?? array();
ok( isset( $get_schema['state'], $get_schema['reason'], $get_schema['failed_at'] ), 'output_schema declares state / reason / failed_at' );
ok( array( 'ready', 'unavailable' ) === ( $get_schema['state']['enum'] ?? array() ), '   ...and state is an enum of exactly the two reported values' );

// v9.51.2: run-narration is async — SCHEDULES generation (never runs the AI
// call in this request) and returns a queued status. get-narration reads it.
$GLOBALS['__narr_last'] = null; $GLOBALS['__narr_sched_force'] = 'UNSET';
$r = snt_ability_run_narration( array( 'force' => true ) );
ok( true === $GLOBALS['__narr_sched_force'], 'run-narration(force=true) schedules with force=true' );
ok( is_array( $r ) && true === $r['scheduled'] && false === $r['cached'], 'run-narration returns a queued status (scheduled, not cached)' );
ok( isset( $r['message'] ) && false === stripos( $r['message'], 'error' ), 'run-narration message points at get-narration (no error)' );

// No force + a digest already cached ⇒ does NOT schedule; reports cached.
$GLOBALS['__narr_last'] = array( 'headline' => 'Traffic up 12%' ); $GLOBALS['__narr_sched_force'] = 'UNSET';
$r2 = snt_ability_run_narration( null );
ok( 'UNSET' === $GLOBALS['__narr_sched_force'], 'run-narration(no force) with a cached digest does NOT schedule' );
ok( true === $r2['cached'] && false === $r2['scheduled'], 'run-narration reports cached:true scheduled:false when a digest exists' );

// Forced regenerates even when a digest is cached.
$GLOBALS['__narr_last'] = array( 'headline' => 'old' ); $GLOBALS['__narr_sched_force'] = 'UNSET';
snt_ability_run_narration( array( 'force' => true ) );
ok( true === $GLOBALS['__narr_sched_force'], 'run-narration(force=true) schedules even when a digest is cached' );

// Already-queued (schedule returns false) ⇒ still a clean queued status, no error.
$GLOBALS['__narr_last'] = null; $GLOBALS['__narr_sched_ret'] = false;
$r3 = snt_ability_run_narration( array( 'force' => true ) );
ok( is_array( $r3 ) && false === $r3['scheduled'] && false === stripos( $r3['message'], 'error' ), 'already-queued returns scheduled:false with a clean message' );
$GLOBALS['__narr_sched_ret'] = true;

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
