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
 * INTENDED to run before:
 *   - any sn-desktop-mode-widget* script (they read/write
 *     window.desktopModeWidgets[ id ] — the mount contract)
 *   - assets/desktop-mode.js (it reads window.wp.desktop.*)
 * ...and IS guaranteed to, on the boot path that goes through WordPress's
 * own print pipeline: the wp_register_script dependency arrays in
 * inc/desktop-mode-integration.php declare this handle as a dependency of
 * every one of them, and WP prints dependencies before dependents.
 *
 * REJECT #11 MEDIUM correction (this file's ordering guarantee is NOT
 * universal): that dependency edge only applies when WordPress's own
 * enqueue/print pipeline is what delivers a script. desktop-mode's OWN lazy
 * widget/command loader (server-sync.ts / command-sync — verified against
 * openstation_resolve_script_payload(), upstream payload.php:1371-1449)
 * resolves only the handle's own src and injects one bare
 * <script src="..."> tag per URL, walking NO dependency graph at all. Under
 * a post-#475 mid-session shell activation, a widget script or
 * desktop-mode.js can therefore load and run BEFORE this prelude ever does
 * — this file being "registered first" guarantees nothing on that path. The
 * real fix (see docs/openstation-compat.md) is that every one of those
 * consumers now aliases both names ITSELF, so none of them actually
 * DEPENDS on this file running first anymore. This prelude still runs
 * first on the ordinary WP-enqueued boot path (print-order tidiness, one
 * fewer place a stray direct property write could diverge), it just is no
 * longer the ONLY thing making the alias correct.
 *
 * The previous claim that "src/widgets/server-sync.ts awaits our script's
 * <script> load before it reads window.openStationWidgets" was also FALSE:
 * server-sync awaits the WIDGET's own URL load, not this compat script's —
 * upstream has no awareness this file exists at all. Ordering upstream
 * reads the global correctly is upstream's own responsibility either way,
 * unaffected by this file.
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
