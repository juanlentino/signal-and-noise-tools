<?php
/**
 * The refusals, counted (inc/mcp/mcp-door-refusals*.php): a refusal is
 * recorded, the store is bounded, no payload is kept, every observed decision
 * comes back unchanged, and the read merges the call log with the option.
 *
 * Run: php tests/door-refusals.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
const SN_MCP_TELEMETRY_TABLE = 'sn_tool_call';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; } }
}
function __( $s, $d = null ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['__opts'] = array(); $GLOBALS['__autoload'] = array(); $GLOBALS['__hooks'] = array(); $GLOBALS['__uid'] = 1;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function get_current_user_id() { return $GLOBALS['__uid']; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['__hooks'][] = array( $h, $cb, $p, $n ); return true; }
function add_action( $h, $cb, $p = 10, $n = 1 ) { return add_filter( $h, $cb, $p, $n ); }

// The guard's real predicates and error builder; the lifecycle guard with the doubles its own suite uses.
require dirname( __DIR__ ) . '/inc/mcp/mcp-rw-guard.php';
require dirname( __DIR__ ) . '/inc/mcp/mcp-door-refusals.php';
$GLOBALS['__rw_allowlist'] = array( 'signal-noise/sn-apply' );
if ( ! defined( 'SN_MCP_DOOR_RW' ) ) { define( 'SN_MCP_DOOR_RW', 'rw' ); }
if ( ! defined( 'SN_MCP_DOOR_READ' ) ) { define( 'SN_MCP_DOOR_READ', 'read' ); }
function sn_mcp_is_allowed( $slug, $door = 'read' ) { return 'rw' === $door ? in_array( $slug, $GLOBALS['__rw_allowlist'], true ) : true; }
function sn_mcp_rw_allowlist() { return $GLOBALS['__rw_allowlist']; }
require dirname( __DIR__ ) . '/inc/abilities-lifecycle-guard.php';
function blob() { return $GLOBALS['__opts'][ SN_DOOR_REFUSALS_OPTION ] ?? array(); }
function reset_store() { unset( $GLOBALS['__opts'][ SN_DOOR_REFUSALS_OPTION ] ); }

$now = gmmktime( 12, 0, 0, 10, 16, 2026 );

// 1. Recording.
ok( true === sn_door_refusals_record( 'rw', 'rw_credential_unbound', $now ), 'a signed-in refusal is counted' );
ok( 1 === ( blob()['days']['2026-10-16']['rw|rw_credential_unbound'] ?? 0 ), 'stored as door|rule on its UTC day' );
sn_door_refusals_record( 'rw', 'rw_credential_unbound', $now );
ok( 2 === blob()['days']['2026-10-16']['rw|rw_credential_unbound'], 'the same rule on the same day adds to one count' );
ok( false === $GLOBALS['__autoload'][ SN_DOOR_REFUSALS_OPTION ], 'the option is written with autoload off' );
$GLOBALS['__uid'] = 0;
ok( false === sn_door_refusals_record( 'rw', 'capability', $now ) && ! isset( blob()['days']['2026-10-16']['rw|capability'] ), 'a signed-out request is not counted (no write per anonymous probe)' );
$GLOBALS['__uid'] = 1;
ok( false === sn_door_refusals_record( 'read', 'x', $now ), 'an unknown door is not counted' );
ok( 'other' === sn_door_refusals_rule( 'Bad Rule; DROP' ) && 'other' === sn_door_refusals_rule( str_repeat( 'a', 65 ) ) && 'sn_rw_kill_switch' === sn_door_refusals_rule( 'sn_rw_kill_switch' ), 'a rule code is kept only in its safe shape' );

// 2. Bound: 90 days, and a cap on keys per day.
reset_store();
sn_door_refusals_record( 'rw', 'old', $now - 90 * DAY_IN_SECONDS );
sn_door_refusals_record( 'rw', 'edge', $now - 89 * DAY_IN_SECONDS );
sn_door_refusals_record( 'rw', 'new', $now );
ok( ! isset( blob()['days'][ gmdate( 'Y-m-d', $now - 90 * DAY_IN_SECONDS ) ] ) && isset( blob()['days'][ gmdate( 'Y-m-d', $now - 89 * DAY_IN_SECONDS ) ] ), 'a day older than 90 is dropped on the next write; day 90 stays' );
for ( $i = 0; $i < 60; $i++ ) { sn_door_refusals_record( 'rw', 'rule_' . $i, $now ); }
$today = blob()['days']['2026-10-16'];
ok( count( $today ) <= SN_DOOR_REFUSALS_DAY_KEYS + 1 && isset( $today['rw|other'] ), 'past the day cap, new rules fold into door|other: ' . count( $today ) . ' keys' );
ok( 61 === array_sum( $today ), 'folding loses no count' );

// 3. No payload is kept.
reset_store();
$secret = new WP_Error( 'snt_sn_apply_validation_failed', '{"payload":{"content":"SECRET-BODY"},"key":"sk-live-123"}', array( 'status' => 422, 'args' => 'SECRET-ARG' ) );
sn_door_refusals_observe( 'direct', $secret );
$flat = serialize( blob() );
ok( false === strpos( $flat, 'SECRET' ) && false === strpos( $flat, 'sk-live' ) && 1 === preg_match_all( '/s:\d+:"[a-z]+\|[a-z0-9_]+";i:\d+;/', $flat ), 'only door|rule => count is stored, never the message, data or arguments' );

// 4. Every observed decision comes back unchanged.
$wp_errors = array();
foreach ( array( 'rw_disabled', 'rw_credential_unbound', 'credential_not_authorized' ) as $code ) { $wp_errors[] = sn_mcp_rw_error( $code ); }
$wp_errors[] = new WP_Error( 'sn_rw_kill_switch', 'x', array( 'status' => 503 ) );
$same = true;
foreach ( array_merge( array( true, false, null, 0, '' ), $wp_errors ) as $decision ) {
	$same = $same && ( $decision === sn_door_refusals_observe( 'rw', $decision ) );
}
ok( $same, 'observe returns every decision identically (true, false, null, 0, empty, each guard WP_Error)' );
reset_store();
$handler = array( 'permission_callback' => 'sn_mcp_rw_permission' );
$res_ok  = array( 'jsonrpc' => '2.0' );
ok( $res_ok === sn_door_refusals_rest_observer( $res_ok, $handler ) && array() === blob(), 'REST observer: an allowed response passes through and counts nothing' );
$same = true;
foreach ( $wp_errors as $e ) { $same = $same && ( $e === sn_door_refusals_rest_observer( $e, $handler ) ); }
$forbidden = new WP_Error( 'rest_forbidden', 'no' );
$same = $same && ( $forbidden === sn_door_refusals_rest_observer( $forbidden, $handler ) );
ok( $same, 'REST observer returns every write-door denial identically' );
$day = blob()['days']['2026-10-16'] ?? blob()['days'][ gmdate( 'Y-m-d' ) ] ?? array();
ok( 1 === ( $day['rw|rw_disabled'] ?? 0 ) && 1 === ( $day['rw|rw_credential_unbound'] ?? 0 ) && 1 === ( $day['rw|credential_not_authorized'] ?? 0 ) && 1 === ( $day['rw|capability'] ?? 0 ), 'REST observer counts each guard rule under its own code, rest_forbidden as capability' );
ok( ! isset( $day['rw|sn_rw_kill_switch'] ), 'REST observer ignores a code the write door does not own' );
reset_store();
sn_door_refusals_rest_observer( sn_mcp_rw_error( 'rw_disabled' ), array( 'permission_callback' => 'sn_mcp_permission' ) );
ok( array() === blob(), 'REST observer ignores every other route' );
ok( in_array( array( 'rest_request_after_callbacks', 'sn_door_refusals_rest_observer', 10, 2 ), $GLOBALS['__hooks'], true ), 'the observer is hooked after core has decided' );

// Lifecycle guard: filter output equals the pure decision, recording on.
reset_store();
$same = true;
foreach ( array( false, true ) as $kill ) {
	$GLOBALS['__opts'][ SN_MCP_RW_ENABLED_OPTION ] = ! $kill;
	foreach ( array( true, false, new WP_Error( 'upstream', 'no' ) ) as $perm ) {
		foreach ( array( 'signal-noise/sn-apply', 'signal-noise/sn-scan' ) as $name ) {
			$want = sn_ability_guard_permission_decision( $perm, true, 'signal-noise/sn-apply' === $name, $kill );
			$got  = sn_ability_guard_filter_permission( $perm, $name, array(), null );
			$same = $same && ( is_object( $want ) ? ( is_object( $got ) && $want->get_error_code() === $got->get_error_code() ) : $want === $got );
		}
	}
}
ok( $same, 'lifecycle guard: every permission result is the pure decision, with counting on' );
$day = blob()['days'][ gmdate( 'Y-m-d' ) ] ?? array();
ok( 1 === ( $day['direct|sn_rw_kill_switch'] ?? 0 ) && 2 === ( $day['direct|capability'] ?? 0 ) && 2 === ( $day['direct|upstream'] ?? 0 ), 'lifecycle guard counts write denials outside MCP by rule: ' . wp_json_encode_stub( $day ) );
ok( 5 === array_sum( $day ), 'a read ability\'s denial and an allowed write are not counted' );
reset_store();
sn_ability_guard_mcp_depth( 1 );
sn_ability_guard_filter_permission( false, 'signal-noise/sn-apply', array(), null );
sn_ability_guard_mcp_depth( -1 );
ok( array() === blob(), 'inside an MCP call the guard does not count (the call log has it)' );
reset_store();
sn_door_refusals_rest_mark( null, array( 'permission_callback' => 'sn_mcp_rw_permission' ) );
$perm = sn_ability_guard_filter_permission( false, 'signal-noise/sn-apply', array(), null );
ok( false === $perm && array() === blob(), 'an MCP door request: the permission check sn_mcp_call_tool() runs before its depth bracket is not counted twice' );
sn_door_refusals_rest_observer( array(), array( 'permission_callback' => 'sn_mcp_rw_permission' ) );
sn_door_refusals_rest_mark( null, array( 'permission_callback' => 'some_other_route' ) );
sn_ability_guard_filter_permission( false, 'signal-noise/sn-apply', array(), null );
ok( 1 === ( blob()['days'][ gmdate( 'Y-m-d' ) ]['direct|capability'] ?? 0 ), 'after the MCP request ends, a denial outside MCP counts again' );
sn_door_refusals_in_mcp_request( false );
function wp_json_encode_stub( $v ) { return json_encode( $v ); }

// 5. The read.
reset_store();
sn_door_refusals_record( 'rw', 'rw_disabled', $now );
sn_door_refusals_record( 'rw', 'rw_disabled', $now - 10 * DAY_IN_SECONDS );
sn_door_refusals_record( 'rw', 'ancient', $now - 40 * DAY_IN_SECONDS );
$opt = sn_door_refusals_merge( sn_door_refusals_from_option( blob(), $now ) );
ok( array( array( 'door' => 'rw', 'rule' => 'rw_disabled', 'd7' => 1, 'd30' => 2 ) ) === $opt, 'option rows: 7 and 30 days split by day; older than 30 days is not listed' );
class Fake_WPDB { public $prefix = 'wp_'; public $last_error = ''; public $rows = array(); public $sql = ''; public $fail_with = '';
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function get_results( $q, $f ) { $this->sql = $q; $this->last_error = $this->fail_with; return $this->rows; } }
$GLOBALS['wpdb'] = new Fake_WPDB();
$GLOBALS['wpdb']->rows = array(
	array( 'door' => 'rw', 'tool_name' => 'signal-noise__sn-apply', 'outcome' => 'conflict', 'refusal_gate' => null, 'error_code' => 'snt_sn_apply_fingerprint_stale', 'd30' => '5', 'd7' => '1' ),
	array( 'door' => 'rw', 'tool_name' => 'signal-noise__sn-apply', 'outcome' => 'refused', 'refusal_gate' => 'rate_limit', 'error_code' => null, 'd30' => '3', 'd7' => '3' ),
	array( 'door' => 'direct', 'tool_name' => 'signal-noise/sn-apply', 'outcome' => 'invalid_input', 'refusal_gate' => null, 'error_code' => null, 'd30' => '2', 'd7' => '0' ),
	array( 'door' => 'direct', 'tool_name' => 'signal-noise/sn-scan', 'outcome' => 'schema_error', 'refusal_gate' => null, 'error_code' => 'snt_bad', 'd30' => '9', 'd7' => '9' ),
);
$read = sn_door_refusals_read( $now );
ok( true === $read['log_readable'] && false !== strpos( $GLOBALS['wpdb']->sql, "outcome IN ('refused','schema_error','invalid_input','conflict')" ), 'the log is read for the four declined outcomes only' );
ok( array( 'snt_sn_apply_fingerprint_stale', 'rate_limit', 'rw_disabled', 'invalid_input' ) === array_column( $read['rows'], 'rule' ), 'ties break on the 7-day count; rule is error code, else gate, else outcome; sorted by 30-day count; a read tool outside the write door is left out' );
ok( 12 === $read['total_30'] && 5 === $read['total_7'], 'totals add both sources: ' . $read['total_30'] . '/' . $read['total_7'] );
$GLOBALS['wpdb']->fail_with = 'Table missing';
$GLOBALS['wpdb']->rows       = array();
$read = sn_door_refusals_read( $now );
ok( false === $read['log_readable'] && array( 'rw_disabled' ) === array_column( $read['rows'], 'rule' ), 'an unreadable log says so and still reports the option\'s counts: ' . json_encode( $read ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
