<?php
/**
 * Signal & Noise Tools: the "Signal through noise" OpenStation desktop theme.
 *
 * A LIGHT personalization, owner-approved 2026-10-12: one red signal line
 * through gray noise is the only brand mark. It touches four things and
 * nothing else:
 *
 *   1. Icons. The three S&N apps wear one family (white strokes on charcoal,
 *      one #ff4c47 mark each) through their `APP:<slug>` slots.
 *   2. The brand constants. The purple meshes, holo films and edges that do
 *      not follow the accent become flat charcoal and neutral grays, with the
 *      red where a line or an edge reads (the holo edge, the rail of Station
 *      Home and S&N Home, the marker on a hovered dock-submenu row).
 *   3. Two wallpapers, Signal and Quiet, OFFERED in the picker. A theme
 *      wallpaper is a pick; the owner's starfield stays until they choose.
 *   4. Bebas Neue for window titles. Body text and the clock stay as they are.
 *
 * What it must not touch (the owner: "some things are OpenStation settings or
 * components"), pinned by tests/openstation-desktop-theme.php: the accent and
 * everything derived from it, the toolbar, window corners, dock size, the
 * wallpaper, surfaces and text colors, and the `recommendedOsSettings` block,
 * which would write into the owner's settings. Activation is per browser, in
 * OS Settings, Appearance; registering changes nothing until then.
 *
 * Where a constant cannot be charcoal, the reason is a fixed upstream ink:
 * step chips, ribbons and count badges carry #0c0b0f text this theme may not
 * set, so they take a light gray (16:1) or the red (6:1); the holo fill stays
 * light because its ink is also MIO's primary label on the accent. The
 * off-state track edge keeps the 3:1 boundary WCAG 1.4.11 asks of a control.
 *
 * Bebas Neue (Ryoichi Tsunekawa, SIL Open Font License 1.1) is the Latin
 * subset the site's theme serves, copied here so the desktop does not depend
 * on the theme's file paths.
 *
 * @package SignalNoiseTools
 * @since 2026.10.5
 */

defined( 'ABSPATH' ) || exit;

/**
 * The theme, as a pure function so the tests can read it without WordPress.
 *
 * @return array{id:string,args:array<string,mixed>}
 */
function snt_desktop_theme_manifest() {
	$base     = defined( 'SNT_URL' ) ? SNT_URL . 'assets/desktop-theme/' : '';
	$charcoal = 'linear-gradient(#161616, #161616)';
	$signal   = $base . 'wallpapers/signal.svg';
	$faders   = array(
		'type' => 'image',
		'path' => $base . 'icons/sn-home.svg',
	);

	return array(
		'id'   => 'sn/signal-through-noise',
		'args' => array(
			'name'        => 'Signal through noise',
			'version'     => '1.0.0',
			'author'      => 'Signal & Noise Tools',
			'description' => 'One red signal line through gray noise: S&N app icons, charcoal for the purple meshes, Bebas Neue window titles, and two wallpapers to pick.',
			'preview'     => $signal,
			'tokens'      => array(
				// Fields: flat charcoal. Gradients, not hex, because consumers
				// paint them as background-image layers.
				'--os-mesh-holo'          => $charcoal,
				'--os-mesh-pulse'         => $charcoal,
				'--os-mesh-auro'          => $charcoal,
				'--os-mesh-star'          => $charcoal,
				'--os-mesh-mio'           => $charcoal,
				// A lit control stays LIGHT with dark ink, as upstream's is: MIO's
				// chat paints its primary label in --os-ui-holo-ink on the accent
				// (variables.css, .os-mio-chat), where white on #ff0000 is 4.0:1 and
				// #0a0a0a is 4.9:1. A hovered dock-submenu row (unsized) also shows
				// a 2px red marker; the 200 to 220% sized fills never reach it.
				'--os-ui-holo-fill'       => 'linear-gradient(90deg, #ff4c47 0 2px, transparent 2px), linear-gradient(#e8e8e8, #e8e8e8)',
				'--os-ui-holo-ink'        => '#0a0a0a',
				// Station Home's and S&N Home's rail (and OS Settings' About card):
				// charcoal, with the red trace at its inline end.
				'--os-ui-hero-mesh'       => 'linear-gradient(90deg, transparent calc(100% - 1px), #ff4c47 calc(100% - 1px)), ' . $charcoal,
				// Edges: red when lit or hovered, a gray hairline at rest.
				'--os-ui-holo-edge'       => 'linear-gradient(#ff4c47, #ff4c47)',
				'--os-ui-holo-edge-quiet' => 'linear-gradient(rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0.24))',
				'--os-ui-holo-sheen'      => 'linear-gradient(124deg, rgba(255, 255, 255, 0.04) 0%, rgba(255, 255, 255, 0.1) 58%, rgba(255, 255, 255, 0.04) 100%)',
				// The off state's well and its 3:1 boundary (#6b6b6b on #1a1721 is 3.3:1).
				'--os-ui-holo-track'      => 'rgba(255, 255, 255, 0.16)',
				'--os-ui-holo-track-edge' => '#6b6b6b',
				// Painted with a fixed #0c0b0f ink: light gray (16:1). Not red: a
				// danger progress bar is red, and the default must stay distinct.
				'--os-ui-step-chip-bg'    => '#e8e8e8',
				'--os-ui-ribbon-bg'       => '#e8e8e8',
				'--os-ui-progress-fill'   => '#e8e8e8',
				'--os-ui-rating-fill'     => '#e8e8e8',
				// A count asking for attention is the signal (#0c0b0f on it: 6:1).
				'--os-dock-badge-bg'      => '#ff4c47',
				'--os-icon-badge-bg'      => '#ff4c47',
				// The active classic tab is white with #0c0b0f text: lose the lavender only.
				'--os-tabs-active-frost'  => 'linear-gradient(180deg, #f4f4f4 0%, #ffffff 58%)',
				'--os-titlebar-font'      => '"Bebas Neue", "Geist", system-ui, sans-serif',
			),
			'icons'       => array(
				'APP:sn-dashboard'      => $faders,
				'APP:sn-icon-dashboard' => $faders,
				'APP:signal-noise'      => array(
					'type' => 'image',
					'path' => $base . 'icons/signal-noise.svg',
				),
				'APP:sn-analytics'      => array(
					'type' => 'image',
					'path' => $base . 'icons/sn-analytics.svg',
				),
			),
			'fonts'       => array(
				array(
					'family'       => 'Bebas Neue',
					'weight'       => '400',
					'style'        => 'normal',
					'display'      => 'swap',
					'src'          => array( $base . 'fonts/bebas-neue-latin.woff2' ),
					'unicodeRange' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
				),
			),
			'wallpapers'  => array(
				'signal' => array(
					'path'        => $signal,
					'label'       => 'Signal',
					'size'        => 'cover',
					'position'    => 'center',
					'description' => 'Faint gray noise with one red line and its pulse.',
				),
				'quiet'  => array(
					'path'        => $base . 'wallpapers/quiet.svg',
					'label'       => 'Quiet',
					'size'        => 'cover',
					'position'    => 'center',
					'description' => 'The same faint gray noise, without the line.',
				),
			),
		),
	);
}

/**
 * Register the theme with OpenStation, when OpenStation is here to take it.
 *
 * Skips requests OpenStation itself would not paint (the front end), the gate
 * its own Legacy theme uses, so a public page never sanitizes a manifest.
 *
 * @return bool|WP_Error False when skipped; otherwise OpenStation's answer.
 */
function snt_desktop_theme_register() {
	if ( ! function_exists( 'openstation_register_desktop_theme' ) ) {
		return false;
	}
	if ( function_exists( 'openstation_request_needs_admin_modules' ) && ! openstation_request_needs_admin_modules() ) {
		return false;
	}
	$theme = snt_desktop_theme_manifest();
	return openstation_register_desktop_theme( $theme['id'], $theme['args'] );
}
add_action( 'init', 'snt_desktop_theme_register', 20 );
