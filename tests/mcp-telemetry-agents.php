<?php
/**
 * Standalone tests for inc/mcp/mcp-telemetry-agents.php — the Desktop Mode
 * AI-agent bridge into the sn_tool_call telemetry table (door='agent').
 *
 * Run: php tests/mcp-telemetry-agents.php
 *
 * @since plugin v10.31.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

if ( ! class_exists( 'WP_Error' ) ) {
	// Mirrors real WP_Error's multi-code storage + get_error_data() default,
	// same fixture shape tests/mcp-telemetry.php already uses.
	class WP_Error {
		public $errors     = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code || null === $code ) { return; }
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) { $this->error_data[ $code ] = $data; }
		}
		public function get_error_message() {
			$codes = array_keys( $this->errors );
			return $codes ? $this->errors[ $codes[0] ][0] : '';
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) { $code = $this->get_error_code(); }
			return $this->error_data[ $code ] ?? null;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
// current_filter() stack: mirrors real WP's $wp_current_filter — apply_filters()/
// do_action() push the dispatching hook name before invoking callbacks and pop it
// after, so a callback (or anything it calls, like snt_os_compat_seen_once())
// can ask "which literal hook name am I running under right now?". Required for
// the v10.43.0 family-aware double-fire guard (inc/openstation-compat.php) to
// tell a desktop_mode_* firing from an openstation_* firing of the SAME callback.
$GLOBALS['__current_filter'] = array();
if ( ! function_exists( 'current_filter' ) ) {
	function current_filter() {
		$c = $GLOBALS['__current_filter'];
		return empty( $c ) ? false : end( $c );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	$GLOBALS['__filters'] = array();
	function apply_filters( $hook, $value, ...$rest ) {
		if ( ! array_key_exists( $hook, $GLOBALS['__filters'] ) ) { return $value; }
		$GLOBALS['__current_filter'][] = $hook;
		$result = call_user_func_array( $GLOBALS['__filters'][ $hook ], array_merge( array( $value ), $rest ) );
		array_pop( $GLOBALS['__current_filter'] );
		return $result;
	}
}
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ] = $cb; return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
// --- actions: a REAL store, not a no-op — seam 2's wiring test drives it
//     via do_action() the same way seam 1's is driven via apply_filters(). ---
$GLOBALS['__actions'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; } }
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		if ( ! isset( $GLOBALS['__actions'][ $hook ] ) ) { return; }
		$GLOBALS['__current_filter'][] = $hook;
		foreach ( $GLOBALS['__actions'][ $hook ] as $cb ) { call_user_func_array( $cb, $args ); }
		array_pop( $GLOBALS['__current_filter'] );
	}
}

// --- PHP-warning/notice trap: malformed-shape handling must produce NONE. ---
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $errno, $errstr ) {
	if ( in_array( $errno, array( E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED ), true ) ) {
		$GLOBALS['__php_errors'][] = $errstr;
	}
	return true; // Swallow so the suite keeps running; we assert on the array instead.
} );

// --- option store (kill switch has no option, but mcp-telemetry.php's dbDelta guard needs one) ---
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }
if ( ! function_exists( 'dbDelta' ) ) { function dbDelta( $sql ) { return array(); } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min, $max ) { return mt_rand( $min, $max ); } }

// --- user resolution (get_userdata) ---
class SN_Test_WP_User {
	public $user_login;
	public function __construct( $login ) { $this->user_login = $login; }
}
$GLOBALS['__users'] = array();
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) { return $GLOBALS['__users'][ (int) $id ] ?? false; }
}

// --- $wpdb stand-in: models BOTH the success shape and the FAILURE shape
//     (insert() returning false), per project memory: a failed wpdb call
//     returns false, never null/throws. ---
class SN_Test_Wpdb_Agents {
	public $prefix       = 'wp_';
	public $insert_calls = array();
	public $fail_insert  = false;
	public function get_charset_collate() { return 'utf8mb4'; }
	public function insert( $table, $data, $format = null ) {
		if ( $this->fail_insert ) { return false; }
		$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}
	public function prepare( $sql, ...$args ) { return $sql; }
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Agents();
$wpdb            = $GLOBALS['wpdb'];

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-telemetry.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
// v10.43.0: mcp-telemetry-agents.php now dual-registers via
// snt_os_compat_add_filter()/snt_os_compat_add_action() and guards both
// seams' side effects via snt_os_compat_seen_once() (inc/openstation-compat.php).
require __DIR__ . '/../inc/openstation-compat.php';
require __DIR__ . '/../inc/mcp/mcp-telemetry-agents.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function sn_test_agents_reset() {
	global $wpdb;
	$wpdb->insert_calls      = array();
	$wpdb->fail_insert       = false;
	$GLOBALS['__opts']       = array();
	$GLOBALS['__users']      = array();
	$GLOBALS['__php_errors'] = array();
	// Deliberately NOT clearing __actions/__filters here — that would wipe
	// the bootstrap's own hook registrations (the exact bug this suite hit
	// once already in the kill-switch section below).
	//
	// v10.43.0: DOES clear the OpenStation double-fire guard's per-request
	// memory (snt_os_compat_reset_seen_once()). Production never needs this
	// — a real request starts every static fresh — but this suite runs many
	// logically-distinct cases inside ONE PHP process, several of which
	// reuse the exact same $slug/$args/$agent_user_id/$output or the exact
	// same $result_one_failure fixture. Without the reset, the guard meant
	// for a hypothetical double-fire would silently swallow those later,
	// legitimately-distinct cases.
	if ( function_exists( 'snt_os_compat_reset_seen_once' ) ) {
		snt_os_compat_reset_seen_once();
	}
}

echo "MCP telemetry — Desktop Mode agent bridge (plugin v10.31.0)\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * 1. Passthrough contract: $output MUST come back byte-identical, across
 *    success, WP_Error, and weird/unexpected shapes. This is the single
 *    hardest requirement — a filter callback that mutates the value breaks
 *    every agent's tool result, not just telemetry.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
$success_in  = array( 'candidates' => array( 1, 2, 3 ), 'meta' => array( 'scanned' => 10 ) );
$success_out = sn_mcp_telemetry_agent_tool_result( $success_in, 'signal-noise/sn-scan', array( 'type' => 'orphan_media' ), 42 );
ok( $success_in == $success_out && $success_in === $success_out || $success_in == $success_out, 'passthrough: success array shape is deep-equal after the filter' );
ok( serialize( $success_in ) === serialize( $success_out ), 'passthrough: success array shape survives byte-for-byte (serialize compare)' );

sn_test_agents_reset();
$error_in  = new WP_Error( 'snt_helper_unavailable', 'dependency missing', array( 'status' => 500 ) );
$error_out = sn_mcp_telemetry_agent_tool_result( $error_in, 'signal-noise/get-rss-stats', array(), 42 );
ok( $error_in === $error_out, 'passthrough: a WP_Error input comes back as the SAME object instance' );

sn_test_agents_reset();
$weird_in  = new stdClass();
$weird_in->unexpected = true;
$weird_out = sn_mcp_telemetry_agent_tool_result( $weird_in, 'signal-noise/sn-scan', array(), 42 );
ok( $weird_in === $weird_out, 'passthrough: an unexpected object shape comes back as the SAME instance, unmutated' );

sn_test_agents_reset();
$null_out = sn_mcp_telemetry_agent_tool_result( null, 'signal-noise/sn-scan', array(), 42 );
ok( null === $null_out, 'passthrough: a null output comes back as null' );

sn_test_agents_reset();
$scalar_out = sn_mcp_telemetry_agent_tool_result( 'a bare string result', 'signal-noise/sn-scan', array(), 42 );
ok( 'a bare string result' === $scalar_out, 'passthrough: a scalar string output comes back unchanged' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Namespace filtering — BOTH directions. 'signal-noise/' and
 *    'signal-and-noise/' diverge at character 7 (repo history: a
 *    strpos('signal-noise/') check silently missed 'signal-and-noise/'
 *    before), so both must be independently proven, plus a genuinely
 *    foreign slug must be silently ignored.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-noise/sn-scan', array(), 1 );
ok( 1 === count( $wpdb->insert_calls ), 'namespace: signal-noise/* (this plugin\'s own abilities) IS recorded' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-and-noise/get-active-template-structure', array(), 1 );
ok( 1 === count( $wpdb->insert_calls ), 'namespace: signal-and-noise/* (the companion theme\'s abilities) IS recorded' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'desktop-mode/get-post', array(), 1 );
ok( 0 === count( $wpdb->insert_calls ), 'namespace: a foreign ability (desktop-mode/get-post) is silently ignored — not ours to log' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'some-other-plugin/do-thing', array(), 1 );
ok( 0 === count( $wpdb->insert_calls ), 'namespace: any other third-party namespace is silently ignored' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), '', array(), 1 );
ok( 0 === count( $wpdb->insert_calls ), 'namespace: an empty slug is silently ignored, never crashes' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Actor resolution — resolved user, and the fallback for an
 *    unresolvable id.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
$GLOBALS['__users'][7] = new SN_Test_WP_User( 'research-bot' );
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-noise/sn-scan', array(), 7 );
ok( 'agent:research-bot' === $wpdb->insert_calls[0]['data']['actor'], 'actor: resolves to agent:<user_login> for a known agent user' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-noise/sn-scan', array(), 999 );
ok( 'agent:#999' === $wpdb->insert_calls[0]['data']['actor'], 'actor: falls back to agent:#<id> when the user id does not resolve' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. Outcome classification — per the real shapes grounded against
 *    ~/Projects/desktop-mode v0.9.8 includes/agents/runner.php:561-576
 *    (only non-WP_Error output ever reaches this filter on the real call
 *    site, but the classifier must still be correct if one arrives anyway).
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'candidates' => array() ), 'signal-noise/sn-scan', array(), 1 );
ok( 'ok' === $wpdb->insert_calls[0]['data']['outcome'], 'outcome: a normal (non-error) ability output classifies as ok' );

sn_test_agents_reset();
$wp_err = new WP_Error( 'snt_helper_unavailable', 'dependency missing', array( 'status' => 500 ) );
sn_mcp_telemetry_agent_tool_result( $wp_err, 'signal-noise/get-rss-stats', array(), 1 );
ok( 'server_error' === $wpdb->insert_calls[0]['data']['outcome'], 'outcome: a defensively-received status-500 WP_Error classifies as server_error (reuses sn_mcp_telemetry_classify_wp_error)' );
ok( 'snt_helper_unavailable' === $wpdb->insert_calls[0]['data']['error_code'], 'outcome: the WP_Error\'s code reaches the agent-door row (12th build_row arg wired)' );

sn_test_agents_reset();
$wp_err_422 = new WP_Error( 'snt_invalid_hook', 'no such hook', array( 'status' => 422 ) );
sn_mcp_telemetry_agent_tool_result( $wp_err_422, 'signal-noise/get-cron-history', array( 'hook' => 'bogus' ), 1 );
ok( 'schema_error' === $wpdb->insert_calls[0]['data']['outcome'], 'outcome: a defensively-received status-422 WP_Error classifies as schema_error' );
ok( false === strpos( wp_json_encode( $wpdb->insert_calls[0]['data'] ), 'bogus' ), 'outcome: the argument VALUE never reaches the inserted row' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. Row shape — door='agent', layer='server', tool_name projection matches
 *    the MCP door's own slug→name mapping, latency_ms is 0 (not fabricated,
 *    not NULL — the column is NOT NULL DEFAULT 0), args never stored raw.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-noise/sn-scan', array( 'type' => 'orphan_media' ), 1 );
$row = $wpdb->insert_calls[0]['data'];
ok( 'agent' === $row['door'], 'row: door is "agent"' );
ok( 'server' === $row['layer'], 'row: layer is "server" (inherited from the shared row builder)' );
ok( 'signal-noise__sn-scan' === $row['tool_name'], 'row: tool_name matches the MCP door\'s own slug→name projection (str_replace / → __)' );
ok( 0 === $row['latency_ms'], 'row: latency_ms is 0 — no timing seam at this call site, never fabricated' );
ok( 'type' === $row['args_shape'], 'row: args_shape carries only the key' );
ok( false === strpos( wp_json_encode( $row ), 'orphan_media' ), 'row: the argument VALUE never reaches the inserted row' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_tool_result( array( 'a', 'b' ), 'signal-noise/sn-scan', array(), 1 );
ok( 2 === $wpdb->insert_calls[0]['data']['result_count'], 'row: result_count populated for a list-shaped success output' );

/* ════════════════════════════════════════════════════════════════════════
 * 6. Kill switch — same sn_mcp_telemetry_enabled filter as the MCP door.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
add_filter( 'sn_mcp_telemetry_enabled', '__sn_test_agents_disabled' );
function __sn_test_agents_disabled() { return false; }
sn_mcp_telemetry_agent_tool_result( array( 'ok' => true ), 'signal-noise/sn-scan', array(), 1 );
ok( 0 === count( $wpdb->insert_calls ), 'kill switch: sn_mcp_telemetry_enabled=false disables the agent bridge too, zero writes' );
unset( $GLOBALS['__filters']['sn_mcp_telemetry_enabled'] ); // Leave the bootstrap's own desktop_mode_agent_tool_result registration intact.

/* ════════════════════════════════════════════════════════════════════════
 * 7. Fail-open — a wpdb insert failure must not throw and must not touch
 *    $output (repo trap #1: stub the FAILURE shape, not just success).
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
$wpdb->fail_insert = true;
$threw = false;
$out_before_fail = array( 'ok' => true );
try {
	$out_after_fail = sn_mcp_telemetry_agent_tool_result( $out_before_fail, 'signal-noise/sn-scan', array(), 1 );
} catch ( \Throwable $e ) {
	$threw = true;
	$out_after_fail = null;
}
ok( false === $threw, 'fail-open: a failing wpdb->insert() never throws out of the filter' );
ok( 0 === count( $wpdb->insert_calls ), 'fail-open: the forced failure really did fail (sanity — stub models FAILURE, not silent success)' );
ok( $out_before_fail === $out_after_fail, 'fail-open: $output is still returned unchanged even when the insert fails internally' );
$wpdb->fail_insert = false;

/* ════════════════════════════════════════════════════════════════════════
 * 8. Wiring sanity — the filter is actually registered under the real
 *    Desktop Mode hook name, at a late priority, accepting 4 args.
 * ════════════════════════════════════════════════════════════════════════ */

sn_test_agents_reset();
$via_apply = apply_filters( 'desktop_mode_agent_tool_result', array( 'ok' => true ), 'signal-noise/sn-scan', array(), 1 );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: apply_filters( desktop_mode_agent_tool_result, ... ) reaches our callback end-to-end' );
ok( array( 'ok' => true ) == $via_apply, 'wiring: apply_filters() itself returns the unmodified output' );

/* ════════════════════════════════════════════════════════════════════════
 * 8b. v10.43.0 — OpenStation rename compat: dual registration + the
 *     double-fire guard. Post-#475 OpenStation renames
 *     desktop_mode_agent_tool_result → openstation_agent_tool_result
 *     (includes/agents/runner.php:579) and desktop_mode_agent_completed →
 *     openstation_agent_completed (includes/agents/runner.php:243). No shim
 *     exists upstream today, so exactly one name ever fires per install —
 *     the guard below defends a HYPOTHETICAL future transition shim, not a
 *     present-day scenario.
 * ════════════════════════════════════════════════════════════════════════ */

echo "\nv10.43.0 — OpenStation rename compat\n\n";

sn_test_agents_reset();
$via_new_name = apply_filters( 'openstation_agent_tool_result', array( 'ok' => true ), 'signal-noise/sn-scan', array(), 1 );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: apply_filters( openstation_agent_tool_result, ... ) ALSO reaches our callback (dual registration)' );
ok( array( 'ok' => true ) == $via_new_name, 'wiring: the post-#475 name also returns the unmodified output' );

// A local seam-2 fixture — deliberately NOT reusing the file's later
// $result_one_failure (this block runs before it's defined below).
$wiring_result_fixture = array(
	'toolCalls' => array( array(
		'callId' => 'call_w1',
		'name'   => 'signal-noise/sn-scan',
		'args'   => array(),
		'output' => null,
		'error'  => 'wiring fixture failure',
	) ),
);

sn_test_agents_reset();
do_action( 'openstation_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: do_action( openstation_agent_completed, ... ) ALSO reaches our callback (dual registration)' );

// Double-fire guard, seam 1: the SAME call delivered via BOTH hook names
// (identical slug/args/agent_user_id/output) records exactly one row.
sn_test_agents_reset();
$dup_slug = 'signal-noise/sn-scan';
$dup_args = array( 'type' => 'orphan_media' );
$dup_out  = array( 'candidates' => array( 1 ) );
apply_filters( 'desktop_mode_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
apply_filters( 'openstation_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
ok( 1 === count( $wpdb->insert_calls ), 'double-fire guard (seam 1): the identical call delivered via both hook names inserts exactly ONE row' );

// The guard must not suppress a genuinely distinct SECOND call that merely
// shares a slug — different output, different agent.
sn_test_agents_reset();
apply_filters( 'desktop_mode_agent_tool_result', array( 'a' => 1 ), $dup_slug, $dup_args, 3 );
apply_filters( 'desktop_mode_agent_tool_result', array( 'a' => 2 ), $dup_slug, $dup_args, 3 );
ok( 2 === count( $wpdb->insert_calls ), 'double-fire guard (seam 1): two calls with DIFFERENT output are both recorded — the guard keys on the full identity, not just slug+args' );

// REJECT #11 HIGH finding: two LEGITIMATE, byte-identical calls delivered
// via the SAME hook name (single family — today's v0.9.8 reality, no
// transition shim in play) must BOTH be recorded. openstation_agent_tool_result
// (runner.php:579) carries no call_id, so two identical tool calls with
// byte-identical output within one agent run are indistinguishable by
// payload — the pre-fix guard collapsed them into one row, silently
// corrupting telemetry.
sn_test_agents_reset();
apply_filters( 'desktop_mode_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
apply_filters( 'desktop_mode_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
ok( 2 === count( $wpdb->insert_calls ), 'REJECT #11 HIGH: same-family identical-repeat (seam 1) — two byte-identical desktop_mode_agent_tool_result firings both record a row' );

sn_test_agents_reset();
apply_filters( 'openstation_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
apply_filters( 'openstation_agent_tool_result', $dup_out, $dup_slug, $dup_args, 3 );
ok( 2 === count( $wpdb->insert_calls ), 'REJECT #11 HIGH: same-family identical-repeat (seam 1), post-#475 name — two byte-identical openstation_agent_tool_result firings both record a row' );

// Double-fire guard, seam 2: the SAME (agent, result) trace delivered via
// BOTH hook names records the failure exactly once, not twice.
sn_test_agents_reset();
do_action( 'desktop_mode_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
do_action( 'openstation_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
ok( 1 === count( $wpdb->insert_calls ), 'double-fire guard (seam 2): the identical (agent, result) trace delivered via both hook names inserts exactly ONE row' );

// The guard must not suppress a genuinely distinct run for a different agent.
sn_test_agents_reset();
do_action( 'desktop_mode_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
do_action( 'desktop_mode_agent_completed', 9, 'msg', $wiring_result_fixture, array() );
ok( 2 === count( $wpdb->insert_calls ), 'double-fire guard (seam 2): the SAME result trace for a DIFFERENT agent is recorded separately' );

// REJECT #11 HIGH finding, seam 2: two LEGITIMATE identical completions
// delivered via the SAME hook name (single family) must BOTH record their
// failure row. Copilot's $request_id is per-RUN (search.php:888-890,
// reused across the iteration loop), so a same-tool same-args repeat within
// one turn hashes identically — the pre-fix guard dropped the second one.
sn_test_agents_reset();
do_action( 'desktop_mode_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
do_action( 'desktop_mode_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
ok( 2 === count( $wpdb->insert_calls ), 'REJECT #11 HIGH: same-family identical-repeat (seam 2) — two byte-identical desktop_mode_agent_completed firings both record a row' );

// Scenario B — a true future both-families transition shim: the SAME event
// fires once per family. Exactly ONE of the two proceeds (the guard's whole
// point), verified independently of scenario A above.
sn_test_agents_reset();
do_action( 'desktop_mode_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
do_action( 'openstation_agent_completed', 5, 'msg', $wiring_result_fixture, array() );
ok( 1 === count( $wpdb->insert_calls ), 'REJECT #11 HIGH: cross-family shadow (seam 2) still suppressed — the SAME event fired via both names records exactly once' );

/* ════════════════════════════════════════════════════════════════════════
 * SEAM 2 — desktop_mode_agent_completed: the failure-visibility fix
 * (adversarial-review HIGH finding). Every entry shape below mirrors
 * runner.php:578-593's real toolCalls builder: {callId, name, args, output,
 * error} — error is null on success, a message string on failure.
 * ════════════════════════════════════════════════════════════════════════ */

echo "\nSeam 2 — desktop_mode_agent_completed (failure-visibility fix)\n\n";

function sn_test_tool_call( $name, $args, $failed, $message = 'boom' ) {
	return array(
		'callId' => 'call_1',
		'name'   => $name,
		'args'   => $args,
		'output' => $failed ? null : array( 'ok' => true ),
		'error'  => $failed ? $message : null,
	);
}

// --- 9. A failed, in-namespace entry records exactly one row: server_error,
//        correct actor/tool_name/args_shape, argument VALUES never stored. ---
sn_test_agents_reset();
$result_one_failure = array(
	'text'      => '',
	'toolCalls' => array( sn_test_tool_call( 'signal-noise/sn-scan', array( 'type' => 'orphan_media' ), true, 'ability threw' ) ),
	'turns'     => 1,
);
sn_mcp_telemetry_agent_completed( 5, 'do something', $result_one_failure, array() );
ok( 1 === count( $wpdb->insert_calls ), 'seam 2: exactly one row for one failed toolCalls entry' );
$row2 = $wpdb->insert_calls[0]['data'];
ok( 'server_error' === $row2['outcome'], 'seam 2: outcome is the coarse documented default, server_error' );
ok( 'agent' === $row2['door'], 'seam 2: door is "agent", same as seam 1' );
ok( 'agent:#5' === $row2['actor'], 'seam 2: actor resolves from the action\'s own agent_user_id param' );
ok( 'signal-noise__sn-scan' === $row2['tool_name'], 'seam 2: tool_name uses the same slug projection as seam 1 / the MCP door' );
ok( 'type' === $row2['args_shape'], 'seam 2: args_shape carries only the key' );
ok( false === strpos( wp_json_encode( $row2 ), 'orphan_media' ), 'seam 2: the argument VALUE never reaches the inserted row' );
ok( 0 === $row2['latency_ms'], 'seam 2: latency_ms is 0 — no timing seam here either' );

// --- 10. Double-count guard: a SUCCESS entry (error === null) in the SAME
//         completed action inserts NOTHING — seam 1 already recorded it. ---
sn_test_agents_reset();
$result_success_only = array(
	'toolCalls' => array( sn_test_tool_call( 'signal-noise/sn-scan', array(), false ) ),
);
sn_mcp_telemetry_agent_completed( 5, 'do something', $result_success_only, array() );
ok( 0 === count( $wpdb->insert_calls ), 'seam 2: a successful toolCalls entry inserts NOTHING from the action path (double-count guard)' );

// Mixed run: one success + one failure → exactly one row, for the failure only.
sn_test_agents_reset();
$result_mixed = array(
	'toolCalls' => array(
		sn_test_tool_call( 'signal-noise/get-insights', array(), false ),
		sn_test_tool_call( 'signal-noise/sn-scan', array(), true, 'second call failed' ),
	),
);
sn_mcp_telemetry_agent_completed( 5, 'do something', $result_mixed, array() );
ok( 1 === count( $wpdb->insert_calls ), 'seam 2: a mixed success+failure run records exactly one row, for the failure only' );
ok( 'signal-noise__sn-scan' === $wpdb->insert_calls[0]['data']['tool_name'], 'seam 2: the recorded row is the FAILED call, not the successful one' );

// --- 11. Namespace gate applies identically on seam 2. ---
sn_test_agents_reset();
$result_foreign_failure = array(
	'toolCalls' => array( sn_test_tool_call( 'desktop-mode/get-post', array(), true ) ),
);
sn_mcp_telemetry_agent_completed( 5, 'do something', $result_foreign_failure, array() );
ok( 0 === count( $wpdb->insert_calls ), 'seam 2: a failed FOREIGN-namespace call is silently ignored, same gate as seam 1' );

sn_test_agents_reset();
$result_theme_failure = array(
	'toolCalls' => array( sn_test_tool_call( 'signal-and-noise/get-active-template-structure', array(), true ) ),
);
sn_mcp_telemetry_agent_completed( 5, 'do something', $result_theme_failure, array() );
ok( 1 === count( $wpdb->insert_calls ), 'seam 2: a failed THEME-namespace call IS recorded (both namespaces, both seams)' );

// --- 12. Malformed shapes — silent no-op, entry by entry, zero PHP warnings. ---
sn_test_agents_reset();
sn_mcp_telemetry_agent_completed( 5, 'msg', array( 'text' => 'no toolCalls key at all' ), array() );
ok( 0 === count( $wpdb->insert_calls ), 'malformed: $result missing the toolCalls key entirely → no-op' );
ok( array() === $GLOBALS['__php_errors'], 'malformed: missing toolCalls key → zero PHP warnings/notices' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_completed( 5, 'msg', 'not even an array', array() );
ok( 0 === count( $wpdb->insert_calls ), 'malformed: $result itself is not an array → no-op' );
ok( array() === $GLOBALS['__php_errors'], 'malformed: non-array $result → zero PHP warnings/notices' );

sn_test_agents_reset();
sn_mcp_telemetry_agent_completed( 5, 'msg', array( 'toolCalls' => 'not an array either' ), array() );
ok( 0 === count( $wpdb->insert_calls ), 'malformed: toolCalls itself is not an array → no-op' );
ok( array() === $GLOBALS['__php_errors'], 'malformed: non-array toolCalls → zero PHP warnings/notices' );

sn_test_agents_reset();
$result_mixed_bad_entries = array(
	'toolCalls' => array(
		'not an array at all',
		array( 'error' => 'no name key' ),                          // missing 'name' → treated as '' → not ours → skip
		array( 'name' => 'signal-noise/sn-scan' ),                   // missing 'error' key at all → treated as success → skip
		array( 'name' => 'signal-noise/sn-scan', 'error' => '' ),    // empty-string error → not a real failure → skip
		array( 'name' => 42, 'error' => 'non-string name' ),         // non-string name → not ours → skip
		sn_test_tool_call( 'signal-noise/sn-scan', array(), true, 'this one IS a real failure' ), // the only real hit
	),
);
sn_mcp_telemetry_agent_completed( 5, 'msg', $result_mixed_bad_entries, array() );
ok( 1 === count( $wpdb->insert_calls ), 'malformed: a mix of bad entries + one real failure records exactly the one real failure, nothing crashes' );
ok( array() === $GLOBALS['__php_errors'], 'malformed: no PHP warnings/notices across every malformed entry shape' );

// Missing 'args' key entirely on an otherwise-valid failure entry: args_shape
// falls back to empty string, never a fatal/undefined-index warning.
sn_test_agents_reset();
$result_missing_args = array(
	'toolCalls' => array( array( 'name' => 'signal-noise/sn-scan', 'error' => 'failed, no args key at all' ) ),
);
sn_mcp_telemetry_agent_completed( 5, 'msg', $result_missing_args, array() );
ok( 1 === count( $wpdb->insert_calls ), 'malformed: a failure entry with no "args" key still records one row' );
ok( '' === $wpdb->insert_calls[0]['data']['args_shape'], 'malformed: missing args key → args_shape falls back to empty string' );
ok( array() === $GLOBALS['__php_errors'], 'malformed: missing args key → zero PHP warnings/notices' );

// --- 13. Kill switch applies identically on seam 2. ---
sn_test_agents_reset();
add_filter( 'sn_mcp_telemetry_enabled', '__sn_test_agents_disabled' );
sn_mcp_telemetry_agent_completed( 5, 'msg', $result_one_failure, array() );
ok( 0 === count( $wpdb->insert_calls ), 'seam 2: kill switch disables the completed-action path too' );
unset( $GLOBALS['__filters']['sn_mcp_telemetry_enabled'] );

// --- 14. Fail-open on seam 2: a failing wpdb->insert() must not throw. ---
sn_test_agents_reset();
$wpdb->fail_insert = true;
$threw2 = false;
try {
	sn_mcp_telemetry_agent_completed( 5, 'msg', $result_one_failure, array() );
} catch ( \Throwable $e ) {
	$threw2 = true;
}
ok( false === $threw2, 'seam 2: a failing wpdb->insert() never throws out of the action' );
ok( 0 === count( $wpdb->insert_calls ), 'seam 2: the forced failure really did fail (sanity)' );
$wpdb->fail_insert = false;

// --- 15. Wiring sanity — the action is actually registered under the real
//         Desktop Mode hook name, and do_action() reaches it end-to-end. ---
sn_test_agents_reset();
do_action( 'desktop_mode_agent_completed', 5, 'msg', $result_one_failure, array() );
ok( 1 === count( $wpdb->insert_calls ), 'wiring: do_action( desktop_mode_agent_completed, ... ) reaches our callback end-to-end' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
