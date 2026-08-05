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

// current_filter() stack: mirrors real WP's $wp_current_filter. fire_tool_called()/
// fire_tool_called_os() below push the DISPATCHING hook name before invoking the
// callback, exactly as WP's do_action() does, so the v10.43.0 family-aware
// double-fire guard (inc/openstation-compat.php's snt_os_compat_seen_once()) can
// tell a desktop_mode_ai_tool_called firing from an openstation_ai_tool_called one.
$GLOBALS['__current_filter'] = array();
function current_filter() {
	$c = $GLOBALS['__current_filter'];
	return empty( $c ) ? false : end( $c );
}

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

/** Fire the callback(s) registered under $hook with a context — real do_action() semantics. */
function fire_tool_called_named( $hook, $ctx ) {
	$GLOBALS['__current_filter'][] = $hook;
	foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) {
		$cb( $ctx );
	}
	array_pop( $GLOBALS['__current_filter'] );
}

/** Fire the captured desktop_mode_ai_tool_called callback(s) with a context. */
function fire_tool_called( $ctx ) {
	fire_tool_called_named( 'desktop_mode_ai_tool_called', $ctx );
}

// v10.43.0: the module now dual-registers via snt_os_compat_add_action()
// and guards its side effect via snt_os_compat_seen_once()
// (inc/openstation-compat.php).
require_once __DIR__ . '/../inc/openstation-compat.php';
require_once __DIR__ . '/../inc/ai-tool-invocation-log.php';

/**
 * Reset the option store between cases. Also clears the OpenStation
 * double-fire guard's per-request memory (snt_os_compat_reset_seen_once())
 * — production never needs this (a real request starts every static fresh),
 * but this suite runs many logically-distinct cases inside ONE PHP process,
 * so without the reset a later case reusing an earlier case's exact
 * (tool_name, args, user_id, request_id) tuple would be silently swallowed
 * by the guard meant for a hypothetical double-fire, not a legitimate repeat.
 */
function inv_reset() {
	$GLOBALS['__options'] = array();
	if ( function_exists( 'snt_os_compat_reset_seen_once' ) ) {
		snt_os_compat_reset_seen_once();
	}
}

echo "\n── the action is hooked ──\n";
ok( ! empty( $GLOBALS['__actions']['desktop_mode_ai_tool_called'] ),
	'the module hooks desktop_mode_ai_tool_called (the Stable per-invocation action)' );

echo "\n── v10.43.0: OpenStation dual registration ──\n";
// Post-#475 OpenStation renames this action to `openstation_ai_tool_called`
// (verified: includes/ai-copilot/search.php:1322/1399/1753). No shim exists
// upstream, so the module must be registered under BOTH names to work on
// either release line.
ok( ! empty( $GLOBALS['__actions']['openstation_ai_tool_called'] ),
	'the module ALSO hooks openstation_ai_tool_called (the post-#475 OpenStation name)' );
ok( count( $GLOBALS['__actions']['desktop_mode_ai_tool_called'] ) === count( $GLOBALS['__actions']['openstation_ai_tool_called'] ),
	'both names carry the same number of registered callbacks (one dual-registration, not a duplicate old-name registration)' );

/** Fire the callback registered under the post-#475 hook name. */
function fire_tool_called_os( $ctx ) {
	fire_tool_called_named( 'openstation_ai_tool_called', $ctx );
}

echo "\n── v10.43.0: the new-name registration actually records, same as the old one ──\n";
inv_reset();
fire_tool_called_os( array( 'tool_name' => 'get_analytics_summary', 'args' => array(), 'user_id' => 1, 'request_id' => 'os-r1' ) );
ok( ( snt_ai_tool_invocations()['get_analytics_summary']['n'] ?? 0 ) === 1,
	'firing via the post-#475 hook name records exactly the same as firing via the old name' );

echo "\n── v10.43.0: double-fire guard — the SAME call delivered via BOTH names counts once ──\n";
// Today exactly one hook name is ever live per install, so this never
// happens in production — it exercises the defensive guard against a
// hypothetical future OpenStation release that ships a transition shim
// firing both names for the same underlying tool call.
inv_reset();
$dup_ctx = array( 'tool_name' => 'search_posts', 'args' => array( 'q' => 'hello' ), 'user_id' => 7, 'request_id' => 'dup-1' );
fire_tool_called( $dup_ctx );    // old name
fire_tool_called_os( $dup_ctx ); // new name — SAME identity
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 1,
	'the same (tool_name, args, user_id, request_id) delivered via both hook names increments the counter exactly ONCE' );

echo "\n── v10.43.0: the guard does not suppress genuinely distinct calls ──\n";
inv_reset();
fire_tool_called( array( 'tool_name' => 'search_posts', 'args' => array( 'q' => 'a' ), 'user_id' => 7, 'request_id' => 'r-a' ) );
fire_tool_called( array( 'tool_name' => 'search_posts', 'args' => array( 'q' => 'b' ), 'user_id' => 7, 'request_id' => 'r-b' ) );
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 2,
	'two calls to the SAME tool with DIFFERENT args each count — the guard keys on the full payload, not just the tool name' );

echo "\n── REJECT #11 HIGH: same-family identical-repeat must NOT be dropped ──\n";
// Copilot's $request_id is per-RUN (search.php:888-890, reused across the
// iteration loop), so two identical tool calls (same tool, same args, same
// user, same request_id) within ONE turn hash identically. This is a
// LEGITIMATE repeat delivered via a SINGLE hook name (no transition shim in
// play on today's v0.9.8) — both must increment the counter.
inv_reset();
$same_family_ctx = array( 'tool_name' => 'search_posts', 'args' => array( 'q' => 'hello' ), 'user_id' => 7, 'request_id' => 'run-1' );
fire_tool_called( $same_family_ctx );
fire_tool_called( $same_family_ctx );
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 2,
	'REJECT #11 HIGH: two byte-identical calls delivered via the SAME (pre-rename) hook name both increment the counter' );

inv_reset();
fire_tool_called_os( $same_family_ctx );
fire_tool_called_os( $same_family_ctx );
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 2,
	'REJECT #11 HIGH: two byte-identical calls delivered via the SAME (post-#475) hook name both increment the counter' );

// Scenario B — a true future both-families transition shim: verified
// independently of scenario A, still suppressed to exactly one increment.
inv_reset();
fire_tool_called( $same_family_ctx );
fire_tool_called_os( $same_family_ctx );
ok( ( snt_ai_tool_invocations()['search_posts']['n'] ?? 0 ) === 1,
	'REJECT #11 HIGH: cross-family shadow still suppressed — the SAME call fired via both names increments exactly once' );

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
