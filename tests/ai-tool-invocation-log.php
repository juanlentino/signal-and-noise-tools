<?php
/**
 * Standalone fixture tests for inc/ai-tool-invocation-log.php — the Copilot
 * tool-invocation log.
 *
 * We hook desktop-mode's Stable `desktop_mode_ai_tool_called` action (fires per
 * tool call, server-side, before execute()) and record which tool the model
 * chose, so the NEXT prune is evidence-based instead of a size argument.
 *
 * THE PRIVACY RULE: names + counts + timestamps ONLY. `args` can carry the user's
 * query fragments / content — it must NEVER reach storage. That assertion is the
 * point of this file and is mutation-checked.
 *
 * null IS NOT the empty log: an unrecorded log is array() (an answer), never null.
 *
 * Run: php tests/ai-tool-invocation-log.php
 *
 * @since plugin v9.60.0
 */

// SECURITY: CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; }
	else { $fail++; echo "  FAIL— $label\n"; }
}

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {}

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

/** Fire the captured desktop_mode_ai_tool_called callback(s) with a context. */
function fire_tool_called( $ctx ) {
	foreach ( $GLOBALS['__actions']['desktop_mode_ai_tool_called'] ?? array() as $cb ) {
		$cb( $ctx );
	}
}

require_once __DIR__ . '/../inc/ai-tool-invocation-log.php';

/** Reset the option store between cases. */
function inv_reset() { $GLOBALS['__options'] = array(); }

echo "\n── the action is hooked ──\n";
ok( ! empty( $GLOBALS['__actions']['desktop_mode_ai_tool_called'] ),
	'the module hooks desktop_mode_ai_tool_called (the Stable per-invocation action)' );

echo "\n── an empty log is array(), never null ──\n";
inv_reset();
ok( snt_ai_tool_invocations() === array(),
	'the reader returns array() when nothing has been recorded — an unmeasured log is an empty answer, not null' );

echo "\n── it counts invocations by tool name ──\n";
inv_reset();
fire_tool_called( array( 'tool_name' => 'export_audit_log', 'args' => array(), 'user_id' => 1, 'request_id' => 'r1' ) );
$log = snt_ai_tool_invocations();
ok( ( $log['export_audit_log']['n'] ?? 0 ) === 1, 'one call records n=1 for that tool' );
$first = $log['export_audit_log']['first'] ?? null;
ok( is_int( $first ) && $first > 0, 'first-seen timestamp is recorded' );
ok( ( $log['export_audit_log']['last'] ?? null ) === $first, 'after one call, last === first' );

fire_tool_called( array( 'tool_name' => 'export_audit_log', 'args' => array(), 'user_id' => 1, 'request_id' => 'r2' ) );
$log = snt_ai_tool_invocations();
ok( ( $log['export_audit_log']['n'] ?? 0 ) === 2, 'a second call increments n to 2' );
ok( ( $log['export_audit_log']['first'] ?? null ) === $first, 'first is immutable — it does not move on later calls' );
ok( is_int( $log['export_audit_log']['last'] ?? null ) && $log['export_audit_log']['last'] >= $first,
	'last is an int at or after first' );

echo "\n── it records EVERY tool, not just ours (cross-plugin visibility) ──\n";
inv_reset();
fire_tool_called( array( 'tool_name' => 'search_posts', 'args' => array(), 'user_id' => 1, 'request_id' => 'r1' ) );
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 1,
	"desktop-mode's own tool is recorded too — the log is the whole picture, the audit reads only ours" );

echo "\n── THE PRIVACY RULE: args is NEVER stored ──\n";
inv_reset();
fire_tool_called( array(
	'tool_name'  => 'export_audit_log',
	'args'       => array( 'secret' => 'PII-LEAK-CANARY', 'range' => 30 ),
	'user_id'    => 42,
	'request_id' => 'r1',
) );
$stored = $GLOBALS['__options']['sn_ai_tool_invocations'] ?? array();
ok( strpos( serialize( $stored ), 'PII-LEAK-CANARY' ) === false,
	'args content is NOWHERE in the stored option — walked the full serialized payload for the canary' );
ok( ! isset( $stored['export_audit_log']['args'] ),
	'no `args` key is stored on the record' );
ok( array_keys( (array) ( $stored['export_audit_log'] ?? array() ) ) === array( 'n', 'first', 'last' ),
	'a record holds EXACTLY n/first/last — nothing carried over from the context' );

echo "\n── an empty / missing tool_name is ignored ──\n";
inv_reset();
fire_tool_called( array( 'tool_name' => '', 'args' => array(), 'user_id' => 1, 'request_id' => 'r1' ) );
fire_tool_called( array( 'args' => array(), 'user_id' => 1, 'request_id' => 'r2' ) );
ok( snt_ai_tool_invocations() === array(),
	'an empty or absent tool_name records nothing — no empty-string key' );

echo "\n── the map is capped so a misbehaving upstream cannot grow it unbounded ──\n";
inv_reset();
for ( $i = 0; $i < 250; $i++ ) {
	fire_tool_called( array( 'tool_name' => 'tool_' . $i, 'args' => array(), 'user_id' => 1, 'request_id' => 'r' ) );
}
ok( count( snt_ai_tool_invocations() ) <= 200,
	'the log is capped at 200 keys (bounded ~40-50 real tools; the cap guards against upstream churn)' );

// Render stubs (real escaping so we can prove output is escaped).
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $d = null ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function __( $t, $d = null ) { return $t; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

echo "\n── ranked view: empty is a clean answer, not a crash ──\n";
inv_reset();
$r = snt_ai_tool_invocations_ranked();
ok( $r['distinct'] === 0 && $r['calls'] === 0 && $r['tools'] === array(),
	'ranked() on an empty log returns distinct=0, calls=0, tools=[] — the empty-state signal' );

echo "\n── ranked view: sorts by count desc, sums calls ──\n";
inv_reset();
$GLOBALS['__options']['sn_ai_tool_invocations'] = array(
	'get_analytics_summary' => array( 'n' => 2, 'first' => 100, 'last' => 200 ),
	'search_posts'          => array( 'n' => 9, 'first' => 100, 'last' => 300 ),
	'get_health_scan'       => array( 'n' => 9, 'first' => 100, 'last' => 250 ),
);
$r = snt_ai_tool_invocations_ranked();
ok( $r['calls'] === 20, 'calls sums every tool\'s n (2+9+9)' );
ok( $r['distinct'] === 3, 'distinct counts the tools seen' );
ok( $r['tools'][0]['name'] === 'get_health_scan' && $r['tools'][1]['name'] === 'search_posts',
	'ranked by count desc, ties broken by name asc (get_health_scan before search_posts at n=9)' );
ok( $r['tools'][2]['name'] === 'get_analytics_summary', 'the lowest count sorts last' );

echo "\n── the view RENDERS an empty state (the whole point of shipping it early) ──\n";
inv_reset();
ob_start();
snt_ai_tool_invocations_render();
$html_empty = (string) ob_get_clean();
ok( strpos( $html_empty, 'No Ask AI tool calls recorded yet' ) !== false,
	'with nothing accrued, the view shows a clean empty state — not a blank or a fatal' );
ok( strpos( $html_empty, 'Copilot tool usage' ) !== false, 'the section still has its heading when empty' );

echo "\n── the view RENDERS counts, escaped ──\n";
inv_reset();
$GLOBALS['__options']['sn_ai_tool_invocations'] = array(
	'search_posts'        => array( 'n' => 9, 'first' => 100, 'last' => 300 ),
	'<script>evil</script>' => array( 'n' => 1, 'first' => 100, 'last' => 100 ), // a hostile name must be escaped
);
ob_start();
snt_ai_tool_invocations_render();
$html = (string) ob_get_clean();
ok( strpos( $html, 'search_posts' ) !== false, 'a used tool appears in the view' );
ok( strpos( $html, '>9<' ) !== false || strpos( $html, '— 9' ) !== false, 'its call count is shown' );
ok( strpos( $html, '<script>evil</script>' ) === false,
	'a hostile tool name is ESCAPED — output goes through esc_html, never raw' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
