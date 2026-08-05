<?php
/**
 * Standalone fixture tests for inc/openstation-compat.php — the
 * WordPress/openstation rename compatibility seam (PR #475, not yet in any
 * release; the owner runs pre-rename Desktop Mode v0.9.8 today).
 *
 * This is the ONE seam every desktop-mode-* / mcp-telemetry-agents consumer
 * routes through: presence detection, dispatch wrappers for the direct
 * function CALLS this plugin makes into upstream (register_command/
 * register_widget/register_icon/is_enabled/ai_ability_tool_name), dual hook
 * registration for the 9 upstream hooks this plugin CONSUMES, and the
 * per-request double-fire guard for handlers with real side effects.
 *
 * Run: php tests/openstation-compat.php
 *
 * @since plugin v10.43.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP stubs ─────────────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a ); }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a ); }

// current_filter() stack, test-controlled directly (no real apply_filters()/
// do_action() dispatch loop needed at this level — we're pinning the PRIMITIVE,
// snt_os_compat_seen_once(), not a consumer). snt_os_compat_push_filter()/
// snt_os_compat_pop_filter() simulate WP entering/leaving a hook dispatch.
$GLOBALS['__current_filter'] = array();
function current_filter() {
	$c = $GLOBALS['__current_filter'];
	return empty( $c ) ? false : end( $c );
}
function snt_os_compat_push_filter( $hook ) { $GLOBALS['__current_filter'][] = $hook; }
function snt_os_compat_pop_filter() { array_pop( $GLOBALS['__current_filter'] ); }

require_once __DIR__ . '/../inc/openstation-compat.php';

echo "openstation-compat — v10.43.0\n\n";

/* ════════════════════════════════════════════════════════════════════════
 * 1. Presence detection — neither family installed.
 * ════════════════════════════════════════════════════════════════════════ */

ok( false === snt_os_active(), 'snt_os_active() is false when neither naming family is installed' );
ok( false === snt_os_is_post_rename(), 'snt_os_is_post_rename() is false when neither is installed' );
ok( false === snt_os_register_widget_available(), 'snt_os_register_widget_available() is false when neither is installed' );
ok( false === snt_os_register_icon_available(), 'snt_os_register_icon_available() is false when neither is installed' );
ok( false === snt_os_is_enabled(), 'snt_os_is_enabled() defaults false when neither is installed' );
ok( null === snt_os_register_command( array( 'slug' => 'x' ) ), 'snt_os_register_command() returns null when neither is installed (never fatals)' );
ok( null === snt_os_register_widget( 'x', array() ), 'snt_os_register_widget() returns null when neither is installed' );
ok( null === snt_os_register_icon( 'x', array() ), 'snt_os_register_icon() returns null when neither is installed' );
ok( null === snt_os_ai_ability_tool_name( 'signal-noise/x' ), 'snt_os_ai_ability_tool_name() returns null when neither is installed' );

/* ════════════════════════════════════════════════════════════════════════
 * 2. Dispatch wrappers prefer the PRE-RENAME family when only it exists —
 *    the v0.9.8 install the owner runs today. Byte-identical behavior to
 *    calling desktop_mode_* directly.
 *
 *    Every function below is declared CONDITIONALLY (function_exists guard):
 *    PHP hoists unconditional top-level function declarations to the START
 *    of the script regardless of where they appear in the file, which would
 *    make section 1's "neither family installed" assertions false from the
 *    very first line. This is the same discipline
 *    tests/desktop-mode-dropzone.php already documents ("Conditionally
 *    declared: parse-time hoisting would falsify Gate 1").
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__dm_calls'] = array();
if ( ! function_exists( 'desktop_mode_register_command' ) ) {
	function desktop_mode_register_command( $args = array() ) { $GLOBALS['__dm_calls'][] = array( 'fn' => 'desktop_mode_register_command', 'args' => $args ); return 'dm-command'; }
}
if ( ! function_exists( 'desktop_mode_register_widget' ) ) {
	function desktop_mode_register_widget( $id, $args = array() ) { $GLOBALS['__dm_calls'][] = array( 'fn' => 'desktop_mode_register_widget', 'id' => $id ); return 'dm-widget'; }
}
if ( ! function_exists( 'desktop_mode_register_icon' ) ) {
	function desktop_mode_register_icon( $id, $args = array() ) { $GLOBALS['__dm_calls'][] = array( 'fn' => 'desktop_mode_register_icon', 'id' => $id ); return 'dm-icon'; }
}
if ( ! function_exists( 'desktop_mode_is_enabled' ) ) {
	function desktop_mode_is_enabled() { return true; }
}
if ( ! function_exists( 'desktop_mode_ai_ability_tool_name' ) ) {
	function desktop_mode_ai_ability_tool_name( $name ) { return 'dm:' . $name; }
}

ok( true === snt_os_active(), 'snt_os_active() is true once the pre-rename family exists' );
ok( false === snt_os_is_post_rename(), 'snt_os_is_post_rename() is still false — only desktop_mode_* exists, not openstation_*' );
ok( true === snt_os_register_widget_available(), 'widget availability is true via the pre-rename name' );
ok( true === snt_os_register_icon_available(), 'icon availability is true via the pre-rename name' );
ok( true === snt_os_is_enabled(), 'snt_os_is_enabled() dispatches to desktop_mode_is_enabled()' );

$GLOBALS['__dm_calls'] = array();
ok( 'dm-command' === snt_os_register_command( array( 'slug' => 'sn-cmd-x' ) ), 'snt_os_register_command() dispatches to desktop_mode_register_command() and returns its value' );
ok( 'dm-widget' === snt_os_register_widget( 'sn-widget-x', array() ), 'snt_os_register_widget() dispatches to desktop_mode_register_widget()' );
ok( 'dm-icon' === snt_os_register_icon( 'sn-icon-x', array() ), 'snt_os_register_icon() dispatches to desktop_mode_register_icon()' );
ok( 3 === count( $GLOBALS['__dm_calls'] ), 'exactly the 3 pre-rename functions were called — no double-dispatch' );
ok( 'dm:signal-noise/x' === snt_os_ai_ability_tool_name( 'signal-noise/x' ), 'snt_os_ai_ability_tool_name() dispatches to the pre-rename transform' );

/* ════════════════════════════════════════════════════════════════════════
 * 3. Dispatch wrappers PREFER the post-#475 family when BOTH exist — the
 *    scenario a future transition shim would create. openstation_* must
 *    win, and the pre-rename functions must NOT be called.
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__os_calls'] = array();
if ( ! function_exists( 'openstation_register_command' ) ) {
	function openstation_register_command( $args = array() ) { $GLOBALS['__os_calls'][] = array( 'fn' => 'openstation_register_command', 'args' => $args ); return 'os-command'; }
}
if ( ! function_exists( 'openstation_register_widget' ) ) {
	function openstation_register_widget( $id, $args = array() ) { $GLOBALS['__os_calls'][] = array( 'fn' => 'openstation_register_widget', 'id' => $id ); return 'os-widget'; }
}
if ( ! function_exists( 'openstation_register_icon' ) ) {
	function openstation_register_icon( $id, $args = array() ) { $GLOBALS['__os_calls'][] = array( 'fn' => 'openstation_register_icon', 'id' => $id ); return 'os-icon'; }
}
if ( ! function_exists( 'openstation_is_enabled' ) ) {
	function openstation_is_enabled() { return true; }
}
if ( ! function_exists( 'openstation_ai_ability_tool_name' ) ) {
	function openstation_ai_ability_tool_name( $name ) { return 'os:' . $name; }
}

ok( true === snt_os_is_post_rename(), 'snt_os_is_post_rename() flips true once openstation_register_command() exists' );

$GLOBALS['__dm_calls'] = array();
$GLOBALS['__os_calls'] = array();
ok( 'os-command' === snt_os_register_command( array( 'slug' => 'sn-cmd-x' ) ), 'snt_os_register_command() PREFERS the post-#475 function when both exist' );
ok( 'os-widget' === snt_os_register_widget( 'sn-widget-x', array() ), 'snt_os_register_widget() prefers openstation_register_widget()' );
ok( 'os-icon' === snt_os_register_icon( 'sn-icon-x', array() ), 'snt_os_register_icon() prefers openstation_register_icon()' );
ok( 'os:signal-noise/x' === snt_os_ai_ability_tool_name( 'signal-noise/x' ), 'snt_os_ai_ability_tool_name() prefers the post-#475 transform' );
ok( array() === $GLOBALS['__dm_calls'], 'the pre-rename functions are NOT called once the post-#475 ones are available' );
ok( 3 === count( $GLOBALS['__os_calls'] ), 'exactly the 3 tracked post-#475 dispatches were called (command, widget, icon)' );

/* ════════════════════════════════════════════════════════════════════════
 * 4. Dual hook registration.
 * ════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();

$cb = function ( $x ) { return $x; };
snt_os_compat_add_filter( 'desktop_mode_dock_items', 'openstation_dock_items', $cb, 15, 1 );
ok( isset( $GLOBALS['__filters']['desktop_mode_dock_items'] ), 'snt_os_compat_add_filter() registers under the OLD name' );
ok( isset( $GLOBALS['__filters']['openstation_dock_items'] ), 'snt_os_compat_add_filter() ALSO registers under the NEW name' );
ok( $GLOBALS['__filters']['desktop_mode_dock_items'][0]['cb'] === $cb && $GLOBALS['__filters']['openstation_dock_items'][0]['cb'] === $cb,
	'both registrations carry the SAME callback' );
ok( $GLOBALS['__filters']['desktop_mode_dock_items'][0]['p'] === 15 && $GLOBALS['__filters']['openstation_dock_items'][0]['p'] === 15,
	'both registrations carry the SAME priority' );

$cb2 = function () {};
snt_os_compat_add_action( 'desktop_mode_ai_tool_called', 'openstation_ai_tool_called', $cb2 );
ok( isset( $GLOBALS['__actions']['desktop_mode_ai_tool_called'] ) && isset( $GLOBALS['__actions']['openstation_ai_tool_called'] ),
	'snt_os_compat_add_action() registers under BOTH names' );

// Passing the SAME name for old and new must not double-register.
$GLOBALS['__filters']['same_name_test'] = array();
snt_os_compat_add_filter( 'same_name_test', 'same_name_test', $cb );
ok( 1 === count( $GLOBALS['__filters']['same_name_test'] ),
	'old_hook === new_hook registers exactly ONCE, not twice (a hook unaffected by the rename stays single-registered)' );

/* ════════════════════════════════════════════════════════════════════════
 * 5. Double-fire guard — snt_os_compat_seen_once() / reset.
 * ════════════════════════════════════════════════════════════════════════ */

snt_os_compat_reset_seen_once();
ok( false === snt_os_compat_seen_once( 'k1' ), 'the FIRST time a key is seen, the guard returns false (not yet seen)' );
ok( true === snt_os_compat_seen_once( 'k1' ), 'the SECOND time the SAME key is seen, the guard returns true (already seen)' );
ok( true === snt_os_compat_seen_once( 'k1' ), 'a third occurrence still returns true' );
ok( false === snt_os_compat_seen_once( 'k2' ), 'a DIFFERENT key is unaffected by k1 having been seen' );

snt_os_compat_reset_seen_once();
ok( false === snt_os_compat_seen_once( 'k1' ), 'after reset, a previously-seen key reports unseen again (the test-only reset seam)' );

// Non-string keys coerce safely.
snt_os_compat_reset_seen_once();
ok( false === snt_os_compat_seen_once( 42 ), 'an integer key is coerced to string without error' );
ok( true === snt_os_compat_seen_once( 42 ), 'the coerced integer key is remembered on the second call' );
ok( true === snt_os_compat_seen_once( '42' ), 'the string "42" collides with the int 42 (both coerce to the same key) — a deliberate, harmless quirk of string-keying' );

/* ════════════════════════════════════════════════════════════════════════
 * 6. REJECT #11 HIGH — family-aware suppression. The plain per-key boolean
 *    guard above (section 5) suppressed the SECOND of ANY two calls sharing
 *    a key, including two genuinely distinct events that merely hash
 *    identically within one PHP process — openstation_agent_tool_result
 *    (runner.php:579) carries no call_id, so two identical tool calls with
 *    byte-identical output in one agent run are indistinguishable by
 *    payload, and Copilot's $request_id is per-RUN (search.php:888-890),
 *    so a same-tool same-args repeat within one turn hashes identically
 *    too. Both are LEGITIMATE repeats today — there is no transition shim —
 *    and the old guard silently dropped the second row.
 *
 *    Fix: count firings per (key, hook family), family derived from
 *    current_filter()'s prefix. A firing is a shadow of an already-recorded
 *    event iff the OTHER family has fired MORE times for this key than
 *    THIS family has.
 * ════════════════════════════════════════════════════════════════════════ */

echo "\n── REJECT #11 HIGH — family-aware suppression ──\n";

// Scenario A: same-family identical-repeat. Both calls dispatch under the
// SAME literal hook name — this family's own count only ever grows when
// recording, so the other family's count never exceeds it. Both proceed.
snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'desktop_mode_agent_tool_result' );
ok( false === snt_os_compat_seen_once( 'evt-A' ), 'same-family repeat: the FIRST desktop_mode_* firing for a key proceeds' );
ok( false === snt_os_compat_seen_once( 'evt-A' ), 'same-family repeat: the SECOND desktop_mode_* firing for the SAME key ALSO proceeds — not suppressed' );
ok( false === snt_os_compat_seen_once( 'evt-A' ), 'same-family repeat: a THIRD firing still proceeds' );
snt_os_compat_pop_filter();

snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'openstation_agent_tool_result' );
ok( false === snt_os_compat_seen_once( 'evt-B' ), 'same-family repeat (post-#475 name): the FIRST openstation_* firing proceeds' );
ok( false === snt_os_compat_seen_once( 'evt-B' ), 'same-family repeat (post-#475 name): the SECOND openstation_* firing for the SAME key ALSO proceeds' );
snt_os_compat_pop_filter();

// Scenario B: a true future both-families transition shim. The SAME event
// fires once per family — exactly ONE of the two proceeds.
snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'desktop_mode_agent_tool_result' );
ok( false === snt_os_compat_seen_once( 'evt-C' ), 'cross-family shim: the desktop_mode_* firing records (this family 0, other family 0 — not a shadow)' );
snt_os_compat_pop_filter();
snt_os_compat_push_filter( 'openstation_agent_tool_result' );
ok( true === snt_os_compat_seen_once( 'evt-C' ), 'cross-family shim: the SUBSEQUENT openstation_* firing for the SAME key IS a shadow — suppressed' );
snt_os_compat_pop_filter();

// Reversed order — order must not matter, only which family fired more.
snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'openstation_agent_tool_result' );
ok( false === snt_os_compat_seen_once( 'evt-D' ), 'cross-family shim (reversed): the openstation_* firing records first' );
snt_os_compat_pop_filter();
snt_os_compat_push_filter( 'desktop_mode_agent_tool_result' );
ok( true === snt_os_compat_seen_once( 'evt-D' ), 'cross-family shim (reversed): the SUBSEQUENT desktop_mode_* firing is suppressed' );
snt_os_compat_pop_filter();

// A DIFFERENT key is unaffected by another key's family bookkeeping.
snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'desktop_mode_agent_tool_result' );
snt_os_compat_seen_once( 'evt-E' );
snt_os_compat_pop_filter();
snt_os_compat_push_filter( 'openstation_agent_tool_result' );
ok( false === snt_os_compat_seen_once( 'evt-F' ), 'a DIFFERENT key is unaffected by evt-E having already fired cross-family' );
snt_os_compat_pop_filter();

// No hook context (current_filter() === false, e.g. a direct call outside
// any apply_filters()/do_action() dispatch): falls back to the original
// simple per-key boolean guard — section 5 above already pins this, this
// re-confirms it holds unchanged after the family-aware rewrite.
snt_os_compat_reset_seen_once();
ok( false === snt_os_compat_seen_once( 'no-hook-key' ), 'no hook context: first call proceeds (same as the plain guard)' );
ok( true === snt_os_compat_seen_once( 'no-hook-key' ), 'no hook context: second call is suppressed (same as the plain guard)' );

// A hook name belonging to NEITHER family (current_filter() returns
// something, but not desktop_mode_*/openstation_*) also falls back to the
// simple guard — family cannot be derived, so there is nothing to be
// family-aware ABOUT.
snt_os_compat_reset_seen_once();
snt_os_compat_push_filter( 'some_unrelated_hook' );
ok( false === snt_os_compat_seen_once( 'unrelated-key' ), 'an unrelated hook name: first call proceeds' );
ok( true === snt_os_compat_seen_once( 'unrelated-key' ), 'an unrelated hook name: second call is suppressed (simple guard fallback)' );
snt_os_compat_pop_filter();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
