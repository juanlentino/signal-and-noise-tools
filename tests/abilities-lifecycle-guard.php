<?php
/**
 * Standalone tests for inc/abilities-lifecycle-guard.php — the WP 7.1
 * forward-compat layer that attaches our MCP-door policy (rw kill switch,
 * telemetry, rw audit) to core's ability-execution lifecycle filters
 * (wp_pre_execute_ability / wp_ability_permission_result /
 * wp_ability_execute_result). Pre-7.1 those hooks never fire, so the guard
 * must be a pure no-op there; these tests exercise the handlers directly.
 *
 * THE ASSERTION THAT MATTERS MOST here is section 1's second half: every hook
 * the guard registers must be a MEMBER of the set 7.1 actually ships. From
 * v10.38.0 through v10.92.4 the guard registered `wp_ability_invoked`, a hook
 * that exists in no WordPress release, and this suite passed anyway — it
 * asserted only that we
 * had registered the name we had decided to register. A handler on a
 * non-existent hook is indistinguishable from a handler on a not-yet-shipped
 * one, so nothing downstream could have caught it either: the symptom was a
 * permanent latency_ms=0 on `direct` telemetry rows, which reads as a fast
 * ability. One-sided contract assertions are the trap; the membership check is
 * the second side.
 *
 * Stub-drift note (the 5x trap): every stub below models the REAL callee's
 * signature, verified against source at authoring time:
 *   sn_mcp_is_allowed( $slug, $door )                    -> bool
 *   sn_mcp_rw_kill_switch_engaged()                      -> bool
 *   sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null )
 *   sn_mcp_telemetry_record( $tool, $args, $door, $outcome, $refusal_gate, $latency_ms, $result_count = null )
 *   sn_mcp_telemetry_classify_wp_error( $err )           -> array{outcome,refusal_gate}
 *
 * @since plugin v10.38.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok  - $label\n"; }
	else { $fail++; echo "FAIL - $label\n"; }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message;
		public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

// Hook-registration capture: the guard registers its handlers at include time.
$GLOBALS['__hooks'] = array();
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $prio = 10, $arity = 1 ) { $GLOBALS['__hooks'][] = array( 'filter', $h, $cb, $prio, $arity ); return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $prio = 10, $arity = 1 ) { $GLOBALS['__hooks'][] = array( 'action', $h, $cb, $prio, $arity ); return true; } }

// MCP-door test doubles (real signatures — see header). Behavior is driven by
// globals so individual tests can flip state.
$GLOBALS['__rw_allowlist']    = array( 'signal-noise/update-post-surfaces' );
$GLOBALS['__rw_kill_engaged'] = false;
$GLOBALS['__audit_rows']      = array();
$GLOBALS['__telemetry_rows']  = array();
if ( ! defined( 'SN_MCP_DOOR_READ' ) ) { define( 'SN_MCP_DOOR_READ', 'read' ); }
if ( ! defined( 'SN_MCP_DOOR_RW' ) ) { define( 'SN_MCP_DOOR_RW', 'rw' ); }
function sn_mcp_is_allowed( $slug, $door = SN_MCP_DOOR_READ ) {
	if ( SN_MCP_DOOR_RW === $door ) { return in_array( (string) $slug, $GLOBALS['__rw_allowlist'], true ); }
	return true;
}
function sn_mcp_rw_kill_switch_engaged() { return $GLOBALS['__rw_kill_engaged']; }
function sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null ) {
	$GLOBALS['__audit_rows'][] = array( 'slug' => $slug, 'args' => $args, 'outcome' => $outcome, 'error_source' => $error_source );
}
function sn_mcp_telemetry_record( $tool_name, $arguments, $door, $outcome, $refusal_gate, $latency_ms, $result_count = null ) {
	$GLOBALS['__telemetry_rows'][] = array(
		'tool_name' => $tool_name, 'door' => $door, 'outcome' => $outcome,
		'refusal_gate' => $refusal_gate, 'latency_ms' => $latency_ms, 'result_count' => $result_count,
	);
}
function sn_mcp_telemetry_classify_wp_error( $err ) { return array( 'outcome' => 'server_error', 'refusal_gate' => null ); }
function sn_mcp_telemetry_result_count( $out ) { return is_array( $out ) ? count( $out ) : null; }

require dirname( __DIR__ ) . '/inc/abilities-lifecycle-guard.php';

// ---------------------------------------------------------------------------
// 1. Hook registration at include time.
// ---------------------------------------------------------------------------
$hooked = array();
foreach ( $GLOBALS['__hooks'] as $h ) { $hooked[ $h[1] ] = $h; }
ok( isset( $hooked['wp_pre_execute_ability'] ) && 'filter' === $hooked['wp_pre_execute_ability'][0] && 4 === $hooked['wp_pre_execute_ability'][4], 'registers wp_pre_execute_ability filter with arity 4' );
ok( isset( $hooked['wp_ability_permission_result'] ) && 'filter' === $hooked['wp_ability_permission_result'][0] && 4 === $hooked['wp_ability_permission_result'][4], 'registers wp_ability_permission_result filter with arity 4' );
ok( isset( $hooked['wp_ability_execute_result'] ) && 'filter' === $hooked['wp_ability_execute_result'][0] && 4 === $hooked['wp_ability_execute_result'][4], 'registers wp_ability_execute_result filter with arity 4' );

// 1b. THE SECOND SIDE OF THE CONTRACT. Registering a name is not evidence the
//     name exists. Every hook the guard attaches to must be a member of the set
//     7.1 ships, at the arity that set declares, and registered as a filter —
//     7.1's lifecycle hooks are filters without exception, so an `action` here
//     means a return value core will discard.
$shipped = sn_ability_lifecycle_hooks_71();
ok( 4 === count( $shipped ), 'shipped set: 7.1 declares exactly four lifecycle filters' );
$unknown = array();
$wrong_arity = array();
$not_filter  = array();
foreach ( $GLOBALS['__hooks'] as $h ) {
	list( $kind, $name, , , $arity ) = $h;
	if ( ! array_key_exists( $name, $shipped ) ) { $unknown[] = $name; continue; }
	if ( $shipped[ $name ] !== $arity ) { $wrong_arity[] = $name; }
	if ( 'filter' !== $kind ) { $not_filter[] = $name; }
}
ok( array() === $unknown, 'membership: every registered hook exists in shipped 7.1' . ( $unknown ? ' (unknown: ' . implode( ', ', $unknown ) . ')' : '' ) );
ok( array() === $wrong_arity, 'membership: every registered hook uses the arity 7.1 declares' . ( $wrong_arity ? ' (wrong: ' . implode( ', ', $wrong_arity ) . ')' : '' ) );
ok( array() === $not_filter, 'membership: every registered hook is attached as a filter' . ( $not_filter ? ' (not filter: ' . implode( ', ', $not_filter ) . ')' : '' ) );

// Mutation check: prove the membership assertion can actually fail, rather than
// being vacuously true because $GLOBALS['__hooks'] is empty or the lookup is
// wrong. A guard that cannot be made to fire is not a guard.
ok( ! array_key_exists( 'wp_ability_invoked', $shipped ), 'membership: the v10.38.0 name wp_ability_invoked is NOT in the shipped set (the bug this check exists for)' );
ok( 3 === count( $GLOBALS['__hooks'] ), 'membership: the loop actually inspected three registrations (not vacuously empty)' );

// ---------------------------------------------------------------------------
// 2. Namespace scoping.
// ---------------------------------------------------------------------------
ok( true === sn_ability_guard_is_ours( 'signal-noise/sn-scan' ), 'is_ours: plugin namespace' );
ok( true === sn_ability_guard_is_ours( 'signal-and-noise/get-design-tokens' ), 'is_ours: theme namespace' );
ok( false === sn_ability_guard_is_ours( 'core/get-user-info' ), 'is_ours: core ability is not ours' );
ok( false === sn_ability_guard_is_ours( 'signal-noisex/evil' ), 'is_ours: prefix must include the slash boundary' );

// ---------------------------------------------------------------------------
// 3. Permission decision (pure): tighten-only.
// ---------------------------------------------------------------------------
$e = sn_ability_guard_permission_decision( true, true, true, true );
ok( is_wp_error( $e ) && 'sn_rw_kill_switch' === $e->get_error_code(), 'decision: write-class ability denied while rw kill switch engaged' );
ok( true === sn_ability_guard_permission_decision( true, true, false, true ), 'decision: read-class ability unaffected by rw kill switch' );
ok( true === sn_ability_guard_permission_decision( true, true, true, false ), 'decision: write-class allowed when switch not engaged' );
ok( true === sn_ability_guard_permission_decision( true, false, true, true ), 'decision: non-ours ability never touched' );
ok( false === sn_ability_guard_permission_decision( false, true, true, true ), 'decision: an upstream false denial passes through unchanged (never loosened)' );
$up = new WP_Error( 'upstream', 'no' );
ok( $up === sn_ability_guard_permission_decision( $up, true, true, true ), 'decision: an upstream WP_Error passes through by identity' );

// ---------------------------------------------------------------------------
// 4. Live permission filter wiring.
// ---------------------------------------------------------------------------
$GLOBALS['__rw_kill_engaged'] = true;
$res = sn_ability_guard_filter_permission( true, 'signal-noise/update-post-surfaces', array(), null );
ok( is_wp_error( $res ), 'live filter: rw-allowlisted ability denied under engaged switch' );
$res = sn_ability_guard_filter_permission( true, 'signal-noise/sn-scan', array(), null );
ok( true === $res, 'live filter: read-only ability (not on rw allowlist) unaffected' );
$res = sn_ability_guard_filter_permission( true, 'core/get-user-info', array(), null );
ok( true === $res, 'live filter: core ability untouched even with switch engaged' );
$GLOBALS['__rw_kill_engaged'] = false;
$res = sn_ability_guard_filter_permission( true, 'signal-noise/update-post-surfaces', array(), null );
ok( true === $res, 'live filter: write ability allowed when switch disengaged' );

// ---------------------------------------------------------------------------
// 4b. Write-class derivation — review finding: rw-allowlist membership alone
//     misses the abilities held off BOTH doors for being too destructive
//     (ai-orphan-apply, merge-tags, clear-template-overrides, run-cron-event),
//     which remain REST-run-reachable and need the kill switch MOST.
// ---------------------------------------------------------------------------
class SN_Guard_Test_Ability {
	private $meta;
	public function __construct( $annotations ) { $this->meta = array( 'annotations' => $annotations ); }
	public function get_meta() { return $this->meta; }
}
ok( true === sn_ability_guard_is_write_class( 'signal-noise/update-post-surfaces', null ), 'write-class: rw-allowlisted slug is write' );
ok( false === sn_ability_guard_is_write_class( 'signal-noise/sn-scan', null ), 'write-class: unlisted slug with no signal defaults to read' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/ai-orphan-apply', null ), 'write-class: held-out destructive slug is write despite being off both MCP doors' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/merge-tags', null ), 'write-class: merge-tags held-out is write' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/clear-template-overrides', null ), 'write-class: clear-template-overrides held-out is write' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/run-cron-event', null ), 'write-class: run-cron-event held-out is write' );
ok( false === sn_ability_guard_is_write_class( 'signal-noise/some-future-read', new SN_Guard_Test_Ability( array( 'readonly' => true ) ) ), 'write-class: declared readonly:true wins as read' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/some-future-write', new SN_Guard_Test_Ability( array( 'readonly' => false ) ) ), 'write-class: declared readonly:false is write' );
ok( true === sn_ability_guard_is_write_class( 'signal-noise/some-future-destroyer', new SN_Guard_Test_Ability( array( 'destructive' => true ) ) ), 'write-class: declared destructive:true is write' );

$GLOBALS['__rw_kill_engaged'] = true;
$res = sn_ability_guard_filter_permission( true, 'signal-noise/ai-orphan-apply', array(), null );
ok( is_wp_error( $res ) && 'sn_rw_kill_switch' === $res->get_error_code(), 'live filter: held-out destructive ability IS denied under engaged switch' );
$GLOBALS['__rw_kill_engaged'] = false;

// Held-out direct executions must also land in the rw audit log.
$GLOBALS['__audit_rows'] = array();
sn_ability_guard_filter_execute_result( array( 'deleted' => 3 ), 'signal-noise/ai-orphan-apply', array(), null );
ok( 1 === count( $GLOBALS['__audit_rows'] ) && 'signal-noise/ai-orphan-apply' === $GLOBALS['__audit_rows'][0]['slug'], 'observer: held-out destructive direct execution audited' );
$GLOBALS['__audit_rows'] = array();

// ---------------------------------------------------------------------------
// 5. MCP dispatch depth: dedup flag.
// ---------------------------------------------------------------------------
ok( 0 === sn_ability_guard_mcp_depth(), 'depth: starts at zero' );
ok( 1 === sn_ability_guard_mcp_depth( 1 ), 'depth: increments' );
ok( 0 === sn_ability_guard_mcp_depth( -1 ), 'depth: decrements' );
ok( 0 === sn_ability_guard_mcp_depth( -1 ), 'depth: floors at zero (unbalanced decrement cannot go negative)' );

// ---------------------------------------------------------------------------
// 6. Execute-result observer: records direct executions, stands down inside
//    MCP dispatch, and NEVER mutates the result.
// ---------------------------------------------------------------------------
$GLOBALS['__telemetry_rows'] = array();
$GLOBALS['__audit_rows']     = array();

sn_ability_guard_filter_pre_execute( null, 'signal-noise/sn-scan', array(), null );
$out = array( 'a', 'b' );
$ret = sn_ability_guard_filter_execute_result( $out, 'signal-noise/sn-scan', array(), null );
ok( $out === $ret, 'observer: result passes through by identity' );
ok( 1 === count( $GLOBALS['__telemetry_rows'] ), 'observer: direct execution recorded once' );
$row = $GLOBALS['__telemetry_rows'][0];
ok( 'direct' === $row['door'], 'observer: direct executions carry door=direct (MCP rows stay distinguishable)' );
ok( 'ok' === $row['outcome'] && 2 === $row['result_count'], 'observer: ok outcome with raw result_count' );
ok( is_int( $row['latency_ms'] ) && $row['latency_ms'] >= 0, 'observer: latency measured from wp_pre_execute_ability' );
ok( array() === $GLOBALS['__audit_rows'], 'observer: read-class direct execution writes no rw audit row' );

// ---------------------------------------------------------------------------
// 6b. wp_pre_execute_ability is a SHORT-CIRCUIT filter. Two properties, both
//     load-bearing: it must never invent a return value (that would replace
//     every ability's result with ours), and it must not stamp t0 when a prior
//     filter already short-circuited (the execute callback will not run, so
//     wp_ability_execute_result never fires, so the stamp would never be popped
//     and would surface as the NEXT call's latency).
// ---------------------------------------------------------------------------
ok( null === sn_ability_guard_filter_pre_execute( null, 'signal-noise/sn-scan', array(), null ), 'pre_execute: returns null $pre unchanged (never short-circuits execution itself)' );
sn_ability_guard_t0( 'signal-noise/sn-scan' ); // drain the stamp the assertion above pushed.

$short = array( 'cached' => true );
ok( $short === sn_ability_guard_filter_pre_execute( $short, 'signal-noise/sn-scan', array(), null ), 'pre_execute: a non-null $pre passes through by identity' );
ok( null === sn_ability_guard_t0( 'signal-noise/sn-scan' ), 'pre_execute: no t0 stamped when a prior filter short-circuited (stack stays clean)' );

// A non-ours ability is never stamped either — same stack-hygiene reason.
sn_ability_guard_filter_pre_execute( null, 'core/get-user-info', array(), null );
ok( null === sn_ability_guard_t0( 'core/get-user-info' ), 'pre_execute: non-ours ability is not stamped' );

// Inside MCP dispatch the wrapper already records — the observer stands down.
$GLOBALS['__telemetry_rows'] = array();
sn_ability_guard_mcp_depth( 1 );
sn_ability_guard_filter_pre_execute( null, 'signal-noise/sn-scan', array(), null );
$ret = sn_ability_guard_filter_execute_result( $out, 'signal-noise/sn-scan', array(), null );
ok( $out === $ret && array() === $GLOBALS['__telemetry_rows'], 'observer: no double-record inside MCP dispatch' );
sn_ability_guard_mcp_depth( -1 );

// Non-ours abilities are never observed.
$GLOBALS['__telemetry_rows'] = array();
$ret = sn_ability_guard_filter_execute_result( 'x', 'core/get-user-info', array(), null );
ok( 'x' === $ret && array() === $GLOBALS['__telemetry_rows'], 'observer: core abilities not recorded' );

// Write-class direct executions also land in the rw audit log — both outcomes.
$GLOBALS['__telemetry_rows'] = array();
$GLOBALS['__audit_rows']     = array();
sn_ability_guard_filter_pre_execute( null, 'signal-noise/update-post-surfaces', array( 'post_id' => 7 ), null );
sn_ability_guard_filter_execute_result( array( 'ok' => true ), 'signal-noise/update-post-surfaces', array( 'post_id' => 7 ), null );
ok( 1 === count( $GLOBALS['__audit_rows'] ) && 'ok' === $GLOBALS['__audit_rows'][0]['outcome'] && 'signal-noise/update-post-surfaces' === $GLOBALS['__audit_rows'][0]['slug'], 'observer: write-class direct success audited' );

$err = new WP_Error( 'boom', 'exploded' );
$ret = sn_ability_guard_filter_execute_result( $err, 'signal-noise/update-post-surfaces', array( 'post_id' => 7 ), null );
ok( $err === $ret, 'observer: WP_Error result passes through by identity (no recovery)' );
ok( 2 === count( $GLOBALS['__audit_rows'] ) && 'error' === $GLOBALS['__audit_rows'][1]['outcome'], 'observer: write-class direct failure audited as error' );
$last = end( $GLOBALS['__telemetry_rows'] );
ok( 'server_error' === $last['outcome'], 'observer: WP_Error outcome classified via telemetry classifier' );

// ---------------------------------------------------------------------------
// 7. Re-entrancy: nested same-name execution must not zero the OUTER latency
//    (review finding — the t0 store is a LIFO stack, not last-write-wins).
// ---------------------------------------------------------------------------
$GLOBALS['__telemetry_rows'] = array();
sn_ability_guard_filter_pre_execute( null, 'signal-noise/sn-scan', array(), null ); // outer
usleep( 2000 );
sn_ability_guard_filter_pre_execute( null, 'signal-noise/sn-scan', array(), null ); // inner
sn_ability_guard_filter_execute_result( array(), 'signal-noise/sn-scan', array(), null ); // inner completes
usleep( 2000 );
sn_ability_guard_filter_execute_result( array(), 'signal-noise/sn-scan', array(), null ); // outer completes
ok( 2 === count( $GLOBALS['__telemetry_rows'] ), 're-entrancy: both nested executions recorded' );
ok( $GLOBALS['__telemetry_rows'][1]['latency_ms'] > 0, 're-entrancy: outer execution keeps a real latency (not zeroed by the inner call)' );
ok( null === sn_ability_guard_t0( 'signal-noise/sn-scan' ), 're-entrancy: stack fully drained after balanced pairs' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
