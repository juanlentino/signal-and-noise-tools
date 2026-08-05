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
 *     harmless. FAMILY-AWARE as of v10.43.0 REJECT #11 (the HIGH finding): a
 *     plain per-request boolean guard suppressed the SECOND of ANY two calls
 *     sharing an identity key — including two genuinely DISTINCT, LEGITIMATE
 *     events that merely hash identically within one request. That is not
 *     hypothetical: openstation_agent_tool_result (runner.php:579) carries no
 *     call_id, so two identical tool calls with byte-identical output in one
 *     agent run are indistinguishable by payload, and a Copilot $request_id
 *     is per-RUN (search.php:888-890, reused across the iteration loop), so a
 *     same-tool same-args repeat within one turn hashes identically too. The
 *     old guard silently dropped the second row on TODAY's v0.9.8, single-
 *     hook-family, no-shim-in-play — corrupting the very telemetry the
 *     consolidation program's retirement decisions depend on. The guard now
 *     counts firings per (key, hook family) — family derived from
 *     current_filter()'s prefix (desktop_mode_ vs openstation_) — and
 *     suppresses a firing only when it is a SHADOW of an already-recorded
 *     event: the OTHER family has fired more times for this key than THIS
 *     family has. Same-family repeats always proceed (see
 *     snt_os_compat_seen_once()'s own docblock for the walk-through); a true
 *     future both-families transition shim still collapses to exactly one
 *     recorded row per event, which is the guard's whole point.
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
 * Which hook family is CURRENTLY dispatching, derived from
 * current_filter()'s prefix. This is the only signal snt_os_compat_seen_once()
 * has for "which literal hook name just fired the callback I'm guarding" —
 * the dual-registered callback itself is identical either way, so it cannot
 * tell on its own.
 *
 * null when family cannot be derived: no hook is currently dispatching
 * (current_filter() returns false — a direct call outside any
 * apply_filters()/do_action(), including a test fixture invoking a callback
 * directly), or the dispatching hook belongs to neither naming family. Both
 * fall back to snt_os_compat_seen_once()'s plain per-key boolean guard.
 *
 * @since 10.43.0
 * @return string|null 'desktop_mode', 'openstation', or null.
 */
function snt_os_compat_current_family() {
	if ( ! function_exists( 'current_filter' ) ) {
		return null;
	}
	$hook = current_filter();
	if ( ! is_string( $hook ) || '' === $hook ) {
		return null;
	}
	if ( 0 === strpos( $hook, 'openstation_' ) ) {
		return 'openstation';
	}
	if ( 0 === strpos( $hook, 'desktop_mode_' ) ) {
		return 'desktop_mode';
	}
	return null;
}

/**
 * True when this firing is a SHADOW of an already-recorded event — a
 * cross-family double-fire of the same underlying call — and should be
 * skipped; false when it should be recorded (and $key's per-family count is
 * incremented as a side effect). $key identifies the underlying event
 * (e.g. a hash of ability slug + args + actor + output for a tool-result
 * row); callers never see family bookkeeping, only the boolean.
 *
 * FAMILY-AWARE as of v10.43.0 REJECT #11 (see this file's top docblock for
 * why the plain boolean predecessor was wrong). Family is derived from
 * current_filter()'s prefix, via snt_os_compat_current_family(). Two
 * worked scenarios:
 *
 *   A. Same-family identical-repeat (the bug this fixes): two byte-identical
 *      desktop_mode_agent_tool_result firings for the same $key. First call:
 *      other(openstation)=0 is not > this(desktop_mode)=0 → records, this
 *      family's count becomes 1. Second call: other(openstation)=0 is still
 *      not > this(desktop_mode)=1 → records too. BOTH proceed — this
 *      family's own count only ever grows when IT records, so the other
 *      family's count can never exceed it from same-family firings alone.
 *
 *   B. A true future both-families transition shim (hypothetical — no such
 *      release exists today): the SAME logical event fires once per family.
 *      First firing, say desktop_mode: other(openstation)=0 is not >
 *      this(desktop_mode)=0 → records (desktop_mode count → 1). Second
 *      firing, openstation: other(desktop_mode)=1 IS > this(openstation)=0
 *      → suppressed as a shadow. Exactly one of the two proceeds, order-
 *      independent (the reverse firing order suppresses the other side).
 *
 * Production semantics: "this request" — PHP resets function statics/globals
 * fresh on every real request, so this is naturally a per-request guard with
 * zero standing state and zero cleanup.
 *
 * @since 10.43.0
 * @param string $key
 * @return bool
 */
function snt_os_compat_seen_once( $key ) {
	if ( ! isset( $GLOBALS['__snt_os_compat_seen'] ) || ! is_array( $GLOBALS['__snt_os_compat_seen'] ) ) {
		$GLOBALS['__snt_os_compat_seen'] = array();
	}
	$key    = (string) $key;
	$family = snt_os_compat_current_family();

	// No derivable hook family: the original simple per-key boolean guard.
	if ( null === $family ) {
		if ( true === ( $GLOBALS['__snt_os_compat_seen'][ $key ] ?? null ) ) {
			return true;
		}
		$GLOBALS['__snt_os_compat_seen'][ $key ] = true;
		return false;
	}

	if ( ! isset( $GLOBALS['__snt_os_compat_seen'][ $key ] ) || ! is_array( $GLOBALS['__snt_os_compat_seen'][ $key ] ) ) {
		$GLOBALS['__snt_os_compat_seen'][ $key ] = array(
			'desktop_mode' => 0,
			'openstation'  => 0,
		);
	}
	$other = 'desktop_mode' === $family ? 'openstation' : 'desktop_mode';

	if ( $GLOBALS['__snt_os_compat_seen'][ $key ][ $other ] > $GLOBALS['__snt_os_compat_seen'][ $key ][ $family ] ) {
		return true; // Shadow of an event already recorded via the other family.
	}
	++$GLOBALS['__snt_os_compat_seen'][ $key ][ $family ];
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
