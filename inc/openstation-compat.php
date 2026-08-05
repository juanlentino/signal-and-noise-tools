<?php
/**
 * Signal & Noise Tools — WordPress/openstation rename compatibility layer.
 *
 * WordPress/openstation PR #475 (merged 2026-08-03, in trunk, NOT yet in any
 * tagged release — the owner runs v0.9.8 today, which predates the rename)
 * renames the plugin from "Desktop Mode" to "OpenStation":
 * `desktop_mode_*()` functions/hooks → `openstation_*()` (NOT `open_station_*`
 * — verified against real post-#475 source; there is no underscore between
 * "open" and "station" anywhere: functions, hooks, or constants),
 * `DESKTOP_MODE_*` constants → `OPENSTATION_*`, `Desktop_Mode_*` classes →
 * `Open_Station_*`, JS `wp.desktop` → `wp.os`, `window.desktopModeWidgets` →
 * `window.openStationWidgets`. THERE IS NO BACK-COMPAT SHIM: a fresh grep of
 * post-#475 source for any `desktop_mode_*` name returns zero hits, so
 * exactly one naming family is ever active for a given OpenStation install,
 * decided entirely by which release the owner is running.
 *
 * This file is the single seam that lets the rest of the plugin stop caring
 * which family is live:
 *
 *   - snt_os_active() / snt_os_is_post_rename() — presence detection.
 *   - snt_os_register_command() / snt_os_register_widget() /
 *     snt_os_register_icon() / snt_os_is_enabled() — dispatch wrappers for
 *     the direct function CALLS this plugin makes into upstream (register_*,
 *     is_enabled). No back-compat alias exists for these, so calling the
 *     wrong one on a given install is simply a no-op / fatal-avoided-by-guard,
 *     not a silent success — the wrapper always calls whichever one is real.
 *   - snt_os_compat_add_filter() / snt_os_compat_add_action() — dual
 *     registration for the 9 upstream hooks this plugin CONSUMES (dock
 *     items/placement, AI tools/appendix/tool-called, agent completed/
 *     tool-result, living-tree traffic, plugins-window icon URL). Attaches
 *     the SAME callback under both the pre-rename and post-#475 hook name;
 *     harmless today (only one name ever fires), and correct if a future
 *     OpenStation release ever ships a transition shim that fires both.
 *   - snt_os_compat_seen_once() — the double-fire guard for handlers with
 *     real side effects (a DB insert, an option increment). Idempotent
 *     filters (schema normalizer, icon URL, dock placement/items, living-tree
 *     traffic) do NOT need this — computing the same pure transform twice is
 *     harmless. A per-request identity guard, keyed by the caller, so a
 *     hypothetical simultaneous old-name+new-name delivery of the SAME event
 *     records exactly one row/increment instead of two. Two genuinely
 *     DISTINCT calls that happen to carry byte-identical payloads within one
 *     request are treated as one — a documented, deliberate trade-off:
 *     telemetry here is already coarse-by-design (see
 *     inc/mcp/mcp-telemetry-agents.php), and undercounting one edge case is
 *     safer than a double-fire corrupting the row count.
 *   - snt_os_compat_reset_seen_once() — TEST SEAM ONLY. Production never
 *     needs it: a real PHP request starts every static fresh. The standalone
 *     test harnesses run many logically-distinct cases inside ONE PHP
 *     process, so their reset helpers call this between cases (mirrors
 *     resetting $wpdb->insert_calls, $GLOBALS['__opts'], etc.).
 *
 * Every consumer file (inc/desktop-mode-integration.php,
 * inc/desktop-mode-attention.php, inc/desktop-mode-dropzone.php,
 * inc/ai-tool-invocation-log.php, inc/mcp/mcp-telemetry-agents.php) keeps its
 * OWN identifiers (snt_*, sn_*) untouched — only the seams that touch
 * UPSTREAM names route through here. See docs/openstation-compat.md for the
 * full old-hook → new-hook → consumer audit trail, source-verified against
 * https://github.com/WordPress/openstation trunk @ 2026-08-04.
 *
 * @package SignalNoiseTools
 * @since 10.43.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * Presence detection.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * True when EITHER the pre-rename or post-#475 plugin is active. Checked via
 * a function every registration flow already depends on
 * (register_command()) — same detector both families ship.
 *
 * @since 10.43.0
 * @return bool
 */
function snt_os_active() {
	return function_exists( 'openstation_register_command' ) || function_exists( 'desktop_mode_register_command' );
}

/**
 * True when the ACTIVE install is post-#475 OpenStation. Verified real:
 * `openstation_register_command()` is defined at includes/commands.php:115
 * in post-rename trunk (there is no `desktop_mode_register_command` there at
 * all — zero hits, no shim). Useful for anything that must branch on the
 * naming family rather than just "is either one here"; not required by the
 * dual-registration/dispatch helpers below, which handle both families
 * without needing to know which is live.
 *
 * @since 10.43.0
 * @return bool
 */
function snt_os_is_post_rename() {
	return function_exists( 'openstation_register_command' );
}

/* ════════════════════════════════════════════════════════════════════════
 * Dispatch wrappers — the direct function CALLS this plugin makes into
 * upstream. No back-compat alias exists on either side of the rename, so
 * these always resolve to whichever name is REAL on the active install.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Register a Cmd+K command under whichever naming family is active.
 *
 * @since 10.43.0
 * @param array $args See openstation_register_command() / desktop_mode_register_command().
 * @return mixed Upstream's own return value, or null when neither is active.
 */
function snt_os_register_command( $args = array() ) {
	if ( function_exists( 'openstation_register_command' ) ) {
		return openstation_register_command( $args );
	}
	if ( function_exists( 'desktop_mode_register_command' ) ) {
		return desktop_mode_register_command( $args );
	}
	return null;
}

/**
 * True when a widget-registration function exists under either name —
 * mirrors the pre-compat `function_exists( 'desktop_mode_register_widget' )`
 * gate at the call site.
 *
 * @since 10.43.0
 * @return bool
 */
function snt_os_register_widget_available() {
	return function_exists( 'openstation_register_widget' ) || function_exists( 'desktop_mode_register_widget' );
}

/**
 * Register a desktop widget under whichever naming family is active.
 *
 * @since 10.43.0
 * @param string $id
 * @param array  $args
 * @return mixed Upstream's own return value, or null when neither is active.
 */
function snt_os_register_widget( $id, $args = array() ) {
	if ( function_exists( 'openstation_register_widget' ) ) {
		return openstation_register_widget( $id, $args );
	}
	if ( function_exists( 'desktop_mode_register_widget' ) ) {
		return desktop_mode_register_widget( $id, $args );
	}
	return null;
}

/**
 * True when an icon-registration function exists under either name.
 *
 * @since 10.43.0
 * @return bool
 */
function snt_os_register_icon_available() {
	return function_exists( 'openstation_register_icon' ) || function_exists( 'desktop_mode_register_icon' );
}

/**
 * Register a desktop icon under whichever naming family is active.
 *
 * @since 10.43.0
 * @param string $id
 * @param array  $args
 * @return mixed Upstream's own return value, or null when neither is active.
 */
function snt_os_register_icon( $id, $args = array() ) {
	if ( function_exists( 'openstation_register_icon' ) ) {
		return openstation_register_icon( $id, $args );
	}
	if ( function_exists( 'desktop_mode_register_icon' ) ) {
		return desktop_mode_register_icon( $id, $args );
	}
	return null;
}

/**
 * Per-user shell-enabled check, under whichever naming family is active.
 * false when neither exists — the same "no shell, nothing to gate for"
 * default every pre-compat call site already assumed.
 *
 * @since 10.43.0
 * @return bool
 */
function snt_os_is_enabled() {
	if ( function_exists( 'openstation_is_enabled' ) ) {
		return (bool) openstation_is_enabled();
	}
	if ( function_exists( 'desktop_mode_is_enabled' ) ) {
		return (bool) desktop_mode_is_enabled();
	}
	return false;
}

/**
 * Ability-name → Copilot tool-name transform, under whichever naming family
 * is active. Verified real: `openstation_ai_ability_tool_name()` is defined
 * at includes/ai-copilot/abilities.php:93 post-#475, byte-identical logic to
 * `desktop_mode_ai_ability_tool_name()`.
 *
 * @since 10.43.0
 * @param string $ability_name
 * @return string|null null when neither family is active — callers must
 *                      treat that as "cannot compute, skip" (mirrors the
 *                      pre-compat function_exists() guard).
 */
function snt_os_ai_ability_tool_name( $ability_name ) {
	if ( function_exists( 'openstation_ai_ability_tool_name' ) ) {
		return openstation_ai_ability_tool_name( $ability_name );
	}
	if ( function_exists( 'desktop_mode_ai_ability_tool_name' ) ) {
		return desktop_mode_ai_ability_tool_name( $ability_name );
	}
	return null;
}

/* ════════════════════════════════════════════════════════════════════════
 * Dual hook registration — the 9 (+1 JS) upstream hooks this plugin
 * CONSUMES. See docs/openstation-compat.md for the source-verified
 * old-name → new-name → firing-site mapping.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Register $callback on BOTH $old_hook and $new_hook. Registering twice on
 * the SAME literal name (when a caller passes identical old/new by mistake)
 * is avoided so a test asserting "registered once" for an unaffected hook
 * still holds.
 *
 * Safe for idempotent filters unconditionally. A filter with real side
 * effects must guard its OWN body with snt_os_compat_seen_once() — this
 * helper only handles the registration, never the double-fire risk.
 *
 * @since 10.43.0
 * @param string   $old_hook Pre-rename `desktop_mode_*` hook name.
 * @param string   $new_hook Post-#475 `openstation_*` hook name.
 * @param callable $callback
 * @param int      $priority
 * @param int      $accepted_args
 * @return void
 */
function snt_os_compat_add_filter( $old_hook, $new_hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $old_hook, $callback, $priority, $accepted_args );
	if ( $new_hook !== $old_hook ) {
		add_filter( $new_hook, $callback, $priority, $accepted_args );
	}
}

/**
 * Action twin of snt_os_compat_add_filter().
 *
 * @since 10.43.0
 * @param string   $old_hook
 * @param string   $new_hook
 * @param callable $callback
 * @param int      $priority
 * @param int      $accepted_args
 * @return void
 */
function snt_os_compat_add_action( $old_hook, $new_hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $old_hook, $callback, $priority, $accepted_args );
	if ( $new_hook !== $old_hook ) {
		add_action( $new_hook, $callback, $priority, $accepted_args );
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * Double-fire guard for stateful handlers.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * True the SECOND time (and every time after) a given $key is seen within
 * this PHP process; false the first time, and $key is remembered.
 *
 * Production semantics: "this request" — PHP resets function statics fresh
 * on every real request, so this is naturally a per-request guard with zero
 * standing state and zero cleanup.
 *
 * Callers key on a hash of whatever uniquely identifies the underlying
 * event (e.g. ability slug + args + actor + output for a tool-result row).
 * Two genuinely distinct calls that happen to hash identically within one
 * request collapse to one recorded row — an accepted, documented trade-off;
 * see this file's docblock.
 *
 * @since 10.43.0
 * @param string $key
 * @return bool
 */
function snt_os_compat_seen_once( $key ) {
	if ( ! isset( $GLOBALS['__snt_os_compat_seen'] ) || ! is_array( $GLOBALS['__snt_os_compat_seen'] ) ) {
		$GLOBALS['__snt_os_compat_seen'] = array();
	}
	$key = (string) $key;
	if ( isset( $GLOBALS['__snt_os_compat_seen'][ $key ] ) ) {
		return true;
	}
	$GLOBALS['__snt_os_compat_seen'][ $key ] = true;
	return false;
}

/**
 * TEST SEAM ONLY — clears snt_os_compat_seen_once()'s memory. A real request
 * never calls this; the standalone test harnesses run many logically-
 * distinct cases inside one PHP process and must reset between them, the
 * same way they reset $wpdb->insert_calls / $GLOBALS['__opts'] / etc.
 *
 * @since 10.43.0
 * @return void
 */
function snt_os_compat_reset_seen_once() {
	$GLOBALS['__snt_os_compat_seen'] = array();
}
