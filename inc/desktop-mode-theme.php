<?php
/**
 * Signal & Noise Tools — the "Signal & Noise" OpenStation desktop theme.
 *
 * A DATA-ONLY reskin of the OpenStation shell, registered from code via
 * `snt_os_register_desktop_theme()` (inc/openstation-compat.php). No CSS,
 * no JS, no font files ship from here: upstream's sanitizer compiles the
 * stylesheet from this manifest, and every value outside its grammar
 * silently does not apply. Registration is site-wide; ACTIVATION is
 * per-user in OpenStation Preferences -> Themes, so shipping this changes
 * nothing until a user picks it — and picking it back off is one click.
 *
 * DELIBERATELY SMALL — three identity moves, nothing else (owner
 * direction 2026-08-27: "not too big, not too brutalist"):
 *
 *   1. The accent goes Signal & Noise red — links, primary actions and
 *      the holographic "on" state stop being brand blue.
 *   2. Shell chrome type goes monospace — the SAME stack the plugin's
 *      wp-admin surfaces use (tests/token-governance.php owns that
 *      vocabulary; the parity test here pins this file to it).
 *   3. Surfaces anchor to the theme's dark palette literals (asphalt /
 *      void / concrete / rust / bone, dark-mode values) so the shell and
 *      the site read as one estate.
 *
 * Equally deliberate ABSENCES, pinned by tests/desktop-mode-theme.php:
 *   - No radius/geometry tokens: the shell keeps its own rounding.
 *   - No accent-DERIVED tokens (washes, blooms, glows, focus rings,
 *     `--os-ui-accent-dim`): those resolve through the accent at runtime,
 *     and pinning one would sever that chain and opt the surface out of
 *     the user's accent pick. One accent in, everything derived follows.
 *   - No `fonts` array: nothing to serve, nothing to sanitize.
 *   - No `recommendedOsSettings`: the owner's layout is not this file's
 *     opinion.
 *
 * Palette literals are the FSE theme's dark-mode values (signal-and-noise
 * assets/css/critical.css, `:root[data-theme="dark"]`). Cross-repo, so
 * the parity test pins RECORDED literals — if the theme repo re-tunes its
 * dark palette, that test failing here is the reminder to re-sync, not a
 * bug in either repo.
 *
 * @package SignalNoiseTools
 * @since 13.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The manifest, as a pure function so tests can read it without WP.
 *
 * @return array{id:string,args:array<string,mixed>}
 */
function snt_desktop_theme_manifest() {
	// One monospace vocabulary (v13.6.2) — the admin-chrome stack, verbatim.
	$mono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

	return array(
		'id'   => 'signal-noise/asphalt',
		'args' => array(
			'name'        => 'Signal & Noise',
			'version'     => '1.1.0',
			'author'      => 'Signal & Noise Tools',
			'description' => 'The juanlentino.com estate, on the shell: asphalt surfaces, monospace chrome, one red.',
			// Absolute URL per the registry contract ("must be an absolute
			// http(s) URL the plugin already serves"). Guarded so the
			// standalone test harness can read the manifest without WP.
			'preview'     => function_exists( 'plugins_url' ) && defined( 'SNT_PATH' )
				? plugins_url( 'assets/desktop-theme-preview.svg', SNT_PATH . 'signal-and-noise-tools.php' )
				: '',
			'tokens'      => array(
				// ── Type: the estate's admin mono, for shell chrome. ──
				'--os-font'                   => $mono,
				'--os-titlebar-font'          => $mono,

				// ── Shell: window + title bar + dock on the dark palette. ──
				'--os-window-bg'              => '#171717', // asphalt (dark)
				'--os-window-border'          => '#383838', // concrete (dark)
				'--os-titlebar-bg'            => '#0a0a0a', // void (dark), unfocused
				'--os-titlebar-color'         => '#9e9e9e', // rust (dark)
				'--os-titlebar-bg-focused'    => '#000000',
				'--os-titlebar-color-focused' => '#ffffff', // bone (dark)
				// Focused window-control glyphs stay upstream's white-at-70%
				// defaults on purpose: legible on #000000, nothing to fix.
				'--os-dock-bg'                => 'rgba( 10, 10, 10, 0.9 )',
				'--os-dock-border'            => 'rgba( 255, 255, 255, 0.1 )',

				// ── Window bodies: the --os-ui-* palette, dark. ──
				// Elevated == surface is the estate's flat-elevation idiom
				// (readme: "no elevation ramp"); sunken drops to void.
				'--os-ui-surface'             => '#171717',
				'--os-ui-surface-elevated'    => '#171717',
				'--os-ui-surface-sunken'      => '#0a0a0a',
				'--os-ui-fg'                  => '#ffffff',
				'--os-ui-fg-muted'            => '#9e9e9e',
				'--os-ui-fg-faint'            => 'rgba( 255, 255, 255, 0.4 )',
				'--os-ui-border'              => '#383838',
				'--os-ui-hover'               => 'rgba( 255, 255, 255, 0.06 )',
				'--os-ui-scrim'               => 'rgba( 10, 10, 10, 0.72 )',

				// ── The one red. Dark-mode signal/blood, per the FSE theme:
				// "the same red re-pointed so it clears AA against black". ──
				'--os-ui-accent'              => '#ff6b66', // signal (dark)
				'--os-ui-accent-strong'       => '#ff4c47', // blood (dark)
				// --os-ui-fg-on-accent is DELIBERATELY ABSENT — field-found
				// v13.7.0, first activation. Upstream overloads that token as
				// the desktop widget card's BODY INK:
				//     .os-widgets__card { color: var( --os-ui-fg-on-accent, #fff ) }
				// (assets/css/desktop.css, the "glass backdrop" card rule).
				// Setting it to dark ink for accent-filled controls painted
				// every widget body near-black on dark glass — the clock, the
				// health line, every readout. Omitting it falls back to the
				// brand's #fffbff, which is correct on BOTH surfaces. If a
				// future release splits the card ink into its own token,
				// revisit; until then this name is a trap for dark themes.
				'--os-ui-danger'              => '#ff4c47',
				'--os-ui-danger-hover'        => '#e00404', // blood (light) as the pressed state
				'--os-ui-holo-fill'           => '#ff4c47', // the "on" state stops being Holomesh
				'--os-ui-holo-ink'            => '#0a0a0a', // fill changed => ink changed (doc rule)
				'--os-ui-holo-track'          => '#383838',

				// ── Window links: the SVG splines connecting windows. All
				// four color tokens verified consumed by window-links.css at
				// v1.1.3; the owner runs windowLinkRenderer "svg-splines",
				// so these are the most visible red on the desk. The glow is
				// an rgba() of blood, mirroring Legacy's glow-as-rgba idiom. ──
				'--os-window-link-color'      => '#ff6b66',
				'--os-window-link-accent'     => '#ff4c47',
				'--os-window-link-color-active' => '#ff4c47',
				'--os-window-link-glow'       => 'rgba( 255, 76, 71, 0.45 )',
				// The pre-wallpaper boot backdrop — void instead of WP ink.
				'--os-backstop'               => '#0a0a0a',
				// The desk base AND the tint the dock menus mix from —
				// dock-peek.css: color-mix( in srgb, var( --os-bg, … ) 76%, transparent ).
				// The brand's value is a purple-tinted gradient (why the popup
				// read purple under v13.7.1). v13.7.2 set VOID here and the
				// popup vanished: at 76% over a near-black desk, void glass is
				// optically indistinguishable from the desk behind it — the
				// menu and its icon read as floating text with no plate
				// (owner-reported, same night). A popover must sit ABOVE the
				// desk it covers, so this is asphalt, one surface step up —
				// the same answer the widget cards' own glass gives.
				'--os-bg'                     => '#171717',
				'--wp-admin-theme-color'      => '#ff4c47',
			),
		),
	);
}

/*
 * Register on init. Guarded like every other desktop-mode module: absent
 * shell => no-op. Priority 10 (default): unlike commands/widgets (init:6,
 * whose payload order is a preserved contract), the theme registry has no
 * ordering interaction with anything this plugin registers.
 */
add_action( 'init', function () {
	if ( ! snt_os_active() ) {
		return;
	}
	$m = snt_desktop_theme_manifest();
	snt_os_register_desktop_theme( $m['id'], $m['args'] );
} );
