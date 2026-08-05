/**
 * Signal & Noise Tools — OpenStation rename compatibility prelude.
 *
 * WordPress/openstation PR #475 (merged 2026-08-03, in trunk, NOT yet in any
 * tagged release — v0.9.8 is pre-rename) renames the JS surfaces our widget
 * scripts and assets/desktop-mode.js depend on:
 *
 *   - window.desktopModeWidgets → window.openStationWidgets
 *     (the widget mount-callback registry read by src/widgets/server-sync.ts;
 *     verified against WordPress/openstation trunk @ 2026-08-04 —
 *     `( window as unknown as WidgetGlobals ).openStationWidgets || {}`)
 *   - window.wp.desktop           → window.wp.os
 *     (the public API namespace installed at src/api/facade.ts:858,
 *     `window.wp.os = api`)
 *
 * There is no shim upstream — a fresh grep of post-#475 source for any
 * `desktop_mode_*`/`desktopMode*` name returns zero hits, so exactly ONE of
 * the two globals exists for any given OpenStation install, decided entirely
 * by which release is active. Rather than touch every one of our widget
 * files' `window.desktopModeWidgets[ id ] = mount` calls and every
 * `window.wp.desktop.*` call in desktop-mode.js (65 references across both),
 * this file aliases the two names onto the SAME object ONCE, before any of
 * those files run — it is registered as an explicit dependency of every
 * sn-desktop-mode* script handle in inc/desktop-mode-integration.php, so
 * every existing call site keeps working unchanged on either OpenStation
 * line with zero further edits.
 *
 * MUST run before:
 *   - any sn-desktop-mode-widget* script (they read/write
 *     window.desktopModeWidgets[ id ] — the mount contract)
 *   - assets/desktop-mode.js (it reads window.wp.desktop.*)
 * Ordering is guaranteed by the wp_register_script dependency arrays in
 * inc/desktop-mode-integration.php — never enqueue this script standalone,
 * and never rely on load order without that dependency edge.
 *
 * Ordering the OTHER direction (does OUR script run before UPSTREAM reads
 * the global?) is upstream's own responsibility and unaffected by this file:
 * src/widgets/server-sync.ts awaits our script's <script> load before it
 * reads window.openStationWidgets, and by then our widget file's top-level
 * code (which depends on THIS compat script via WP's dependency chain) has
 * already run and set the property.
 *
 * @package SignalNoiseTools
 * @since 10.43.0
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	// Widget mount-callback registry: alias both names onto ONE object so a
	// write through either name is visible through the other, regardless of
	// which one the active OpenStation release actually reads.
	var widgets = window.desktopModeWidgets || window.openStationWidgets || {};
	window.desktopModeWidgets = widgets;
	window.openStationWidgets = widgets;

	// Public API namespace: whichever the shell already installed (wp.os on
	// post-rename OpenStation, wp.desktop on pre-rename Desktop Mode) becomes
	// the value of BOTH names. Only runs when the shell has installed one —
	// on a page without OpenStation/Desktop Mode active, neither exists and
	// both stay undefined, matching pre-compat behavior exactly.
	if ( window.wp ) {
		if ( window.wp.os && ! window.wp.desktop ) {
			window.wp.desktop = window.wp.os;
		} else if ( window.wp.desktop && ! window.wp.os ) {
			window.wp.os = window.wp.desktop;
		}
	}
} )();
