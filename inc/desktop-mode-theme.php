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
				/*
				 * ═══ DERIVED FROM THE FSE THEME, NOT HAND-TUNED (v13.7.5) ═══
				 * The owner's correction, verbatim: "you didn't use the theme
				 * to do this since it has all the design." Earlier passes
				 * treated signal-and-noise as seven hexes; it is a design
				 * system. Every value below now names its source token from
				 * the theme's dark block (assets/css/critical.css,
				 * `:root[data-theme="dark"]`) or its palette. Recorded
				 * literals, cross-repo — the parity test is the re-sync bell.
				 */

				// ── Type: the estate's ONE admin mono (v13.6.2 ratchet). ──
				'--os-font'                   => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
				'--os-titlebar-font'          => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',

				// ── Panels. The site's panel idiom, transplanted whole:
				// --sn-panel #161616, --sn-panel-edge #3a3a3a,
				// --sn-panel-ink #ffffff, --sn-panel-ink-dim #a3a3a3.
				// (v13.7.0-4 used asphalt #171717 / concrete #383838 /
				// rust #9e9e9e — near misses; panels have their OWN tokens.) ──
				'--os-window-bg'              => '#161616', // ← --sn-panel
				'--os-window-border'          => '#3a3a3a', // ← --sn-panel-edge
				'--os-ui-surface'             => '#161616', // ← --sn-panel
				'--os-ui-surface-elevated'    => '#161616', // ← --sn-panel (no elevation ramp — readme)
				'--os-ui-surface-sunken'      => '#0a0a0a', // ← void (dark)
				'--os-ui-fg'                  => '#ffffff', // ← --sn-panel-ink
				'--os-ui-fg-muted'            => '#a3a3a3', // ← --sn-panel-ink-dim
				'--os-ui-fg-faint'            => 'rgba( 255, 255, 255, 0.4 )',
				'--os-ui-border'              => '#3a3a3a', // ← --sn-panel-edge
				'--os-ui-hover'               => 'rgba( 255, 255, 255, 0.06 )',
				'--os-ui-scrim'               => 'rgba( 10, 10, 10, 0.72 )', // ← --sn-veil (dark)
				'--os-ui-context-menu-bg'     => '#161616', // ← --sn-panel (was brand Obsidian)
				// Popover plate mix base + desk base. Two runtime facts,
				// measured live: inside .os-shell an inline wallpaper
				// shorthand overrides this token, so it only matters on the
				// BODY scope (dock peek, menus); and upstream's own value is
				// a GRADIENT, which makes the peek's color-mix() invalid —
				// the popup never had a tint until a flat color sat here.
				'--os-bg'                     => '#161616', // ← --sn-panel
				'--os-backstop'               => '#0a0a0a', // ← void: pre-wallpaper boot backdrop

				// ── Shell chrome on void. ──
				'--os-titlebar-bg'            => '#0a0a0a', // ← void, unfocused
				'--os-titlebar-color'         => '#9e9e9e', // ← rust (dark): chrome text on void
				'--os-titlebar-bg-focused'    => '#000000',
				'--os-titlebar-color-focused' => '#ffffff', // ← bone (dark)
				'--os-titlebar-btn-focused-outline' => '#ff4c47', // ← blood (dark); was brand purple
				'--os-dock-bg'                => 'rgba( 10, 10, 10, 0.9 )',
				'--os-dock-border'            => 'rgba( 255, 255, 255, 0.1 )',

				// ── The reds. Site links are BLOOD; signal is the live/
				// attention color ("the same red re-pointed so it clears AA
				// against black" — the theme's own dark-palette note). ──
				'--os-ui-accent'              => '#ff6b66', // ← signal (dark)
				'--os-ui-accent-strong'       => '#ff4c47', // ← blood (dark)
				'--os-accent'                 => '#ff6b66',
				'--os-link'                   => '#ff6b66',
				'--os-ui-color-accent'        => '#ff6b66',
				'--os-ui-notice-link'         => '#ff6b66',
				'--os-ui-danger'              => '#ff4c47',
				'--os-ui-danger-hover'        => '#e00404', // ← blood (light) as pressed state
				'--os-ui-holo-fill'           => '#ff4c47',
				'--os-ui-holo-ink'            => '#0a0a0a',
				'--os-ui-holo-track'          => '#3a3a3a', // ← --sn-panel-edge
				'--os-ui-selection-bg'        => 'rgba( 255, 76, 71, 0.4 )', // was brand periwinkle
				'--os-ui-badge-danger-bg'     => 'rgba( 255, 76, 71, 0.16 )',
				'--os-dock-item-bg-hover'     => 'rgba( 255, 76, 71, 0.18 )',
				'--os-drop-preview-bg'        => 'rgba( 255, 76, 71, 0.1 )',
				'--os-drop-preview-border'    => 'rgba( 255, 76, 71, 0.55 )',
				'--os-tile-selected-bg'       => 'rgba( 255, 76, 71, 0.24 )',
				'--os-dock-badge-bg'          => 'linear-gradient( 180deg, #ff6b66 0%, #e00404 100% )',
				'--os-icon-badge-bg'          => 'linear-gradient( 180deg, #ff6b66 0%, #e00404 100% )',
				'--os-window-link-color'      => '#ff6b66', // splines: signal
				'--os-window-link-accent'     => '#ff4c47', // splines: blood
				'--os-window-link-color-active' => '#ff4c47',
				'--os-window-link-glow'       => 'rgba( 255, 76, 71, 0.45 )',
				'--wp-admin-theme-color'      => '#ff4c47',

				// ── Semantics, estate temperature. Meaning keeps its hue;
				// only the tint moves from brand pastels to the estate's
				// status colors (--sn-ok / --sn-warn lineage). Info has no
				// estate hue: neutral gray, the brutalist answer. ──
				'--os-ui-success-fg'          => '#3fb950',
				'--os-ui-warning'             => '#dba617',
				'--os-ui-warning-fg'          => '#dba617',
				'--os-ui-warning-bg'          => 'rgba( 219, 166, 23, 0.1 )',
				'--os-ui-warning-border'      => 'rgba( 219, 166, 23, 0.24 )',
				'--os-ui-info-fg'             => '#a3a3a3', // ← --sn-panel-ink-dim
				'--os-ui-info-bg'             => 'rgba( 158, 158, 158, 0.1 )',

				// ── Meshes: upstream's exact geometry, estate hues. NOT
				// accent-derived (measured: user accent #ff0000, meshes
				// stayed purple — they are brand constants), and hero-mesh
				// is the single most visible surface in Station Home's rail.
				// Alphas lowered from brand: aurora, not neon. ──
				'--os-ui-hero-mesh'           => 'radial-gradient( ellipse 62% 38% at 34% 20%, rgba( 255, 76, 71, 0.3 ) 0%, rgba( 255, 76, 71, 0 ) 74% ), radial-gradient( ellipse 54% 33% at 58% 36%, rgba( 255, 107, 102, 0.2 ) 0%, rgba( 255, 107, 102, 0 ) 76% ), radial-gradient( ellipse 68% 30% at 26% 50%, rgba( 158, 158, 158, 0.12 ) 0%, rgba( 158, 158, 158, 0 ) 78% )',
				'--os-mesh-holo'              => 'radial-gradient( ellipse 21.7% 39.6% at 96.5% 13.5%, rgba( 255, 107, 102, 0.4 ) 0%, rgba( 255, 107, 102, 0 ) 100% ), radial-gradient( ellipse 26.3% 57.2% at 83.3% 56.2%, rgba( 255, 76, 71, 0.45 ) 0%, rgba( 255, 76, 71, 0 ) 100% ), radial-gradient( ellipse 18.2% 47.3% at 73.6% 64.5%, rgba( 255, 255, 255, 0.35 ) 0%, rgba( 255, 255, 255, 0 ) 100% )',
				'--os-tabs-active-crown'      => 'radial-gradient( ellipse 21.7% 39.6% at 96.5% 13.5%, rgba( 255, 107, 102, 0.4 ) 0%, rgba( 255, 107, 102, 0 ) 100% ), radial-gradient( ellipse 26.3% 57.2% at 83.3% 56.2%, rgba( 255, 76, 71, 0.45 ) 0%, rgba( 255, 76, 71, 0 ) 100% ), radial-gradient( ellipse 18.2% 47.3% at 73.6% 64.5%, rgba( 255, 255, 255, 0.35 ) 0%, rgba( 255, 255, 255, 0 ) 100% )',
				'--os-ui-holo-edge-quiet'     => 'linear-gradient( 124deg, rgba( 255, 107, 102, 0.22 ) 0%, rgba( 255, 255, 255, 0.14 ) 38%, rgba( 255, 76, 71, 0.22 ) 62%, rgba( 158, 158, 158, 0.2 ) 100% )',
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
