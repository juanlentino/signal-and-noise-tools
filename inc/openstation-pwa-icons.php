<?php
/**
 * OpenStation PWA: replace the manifest icon set with opaque, correctly-sized art.
 *
 * OpenStation builds the manifest from the WordPress Site Icon when one is set,
 * and its docs are explicit that a Site Icon "takes priority and goes out as
 * `any` only". Measured against the live manifest on 2026-09-04, that produced
 * three defects at once:
 *
 *   1. A DECLARED SIZE THAT LIES. The 192 entry pointed at core's
 *      `-300x300` crop: `sizes: "192x192"` on a file that is 300x300. A browser
 *      picking an icon trusts `sizes`, so it chooses this one for a 192 slot and
 *      then downscales, or skips it when it wants a true 192.
 *   2. ALPHA. Both entries were RGBA with 61.6% fully transparent pixels. iOS
 *      composites home-screen transparency to BLACK, and the mark measures
 *      luminance 23/255 — dark ink. Dark ink on black is the black tile the
 *      owner photographed. (Same root cause as the apple-touch-icon fix in the
 *      theme; core's Site Icon keeps the source alpha.)
 *   3. NO `maskable` PURPOSE. Both were `purpose: "any"`, so Android's adaptive
 *      mask has no full-bleed art to crop and falls back to a shrunken tile on
 *      a system-drawn backdrop.
 *
 * The replacements are flattened onto WHITE, not onto the manifest's
 * `background_color` (#0c0b0f): the mark is dark ink, so a dark ground would
 * erase it. That is a measurement, not a preference — see the luminance above.
 *
 * The icons ship with THIS PLUGIN rather than the theme. The manifest describes
 * a wp-admin surface (`scope=/wp-admin/`), and wp-admin has to keep working
 * across a theme switch; sourcing admin art from the active theme would make the
 * installed app's icon depend on something the admin does not control.
 *
 * `openstation_pwa_manifest` is documented Stable in the plugin's hook
 * reference. When OpenStation is not active the filter simply never fires.
 *
 * @package Signal_And_Noise_Tools
 * @since 13.96.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The icon set: full-bleed for `any`, inset to 80% for `maskable`.
 *
 * The maskable pair is the same art inside a 20% margin, which is what lets
 * Android crop a circle or squircle out of it without clipping the mark.
 *
 * @return array[] Manifest icon entries.
 */
function snt_openstation_pwa_icons() {
	$base = SNT_URL . 'assets/pwa/';

	return array(
		array(
			'src'     => $base . 'icon-192.png',
			'sizes'   => '192x192',
			'type'    => 'image/png',
			'purpose' => 'any',
		),
		array(
			'src'     => $base . 'icon-512.png',
			'sizes'   => '512x512',
			'type'    => 'image/png',
			'purpose' => 'any',
		),
		array(
			'src'     => $base . 'maskable-192.png',
			'sizes'   => '192x192',
			'type'    => 'image/png',
			'purpose' => 'maskable',
		),
		array(
			'src'     => $base . 'maskable-512.png',
			'sizes'   => '512x512',
			'type'    => 'image/png',
			'purpose' => 'maskable',
		),
	);
}

/**
 * Swap the manifest's icon array for ours.
 *
 * Only `icons` is touched: name, scope, start_url and the colours are
 * OpenStation's to decide, and a filter that rewrites more than it fixes is a
 * filter that silently reverts an upstream improvement.
 *
 * @param array $manifest Manifest, as assembled by OpenStation.
 * @return array
 */
function snt_openstation_pwa_manifest_icons( $manifest ) {
	if ( ! is_array( $manifest ) ) {
		return $manifest;
	}
	$manifest['icons'] = snt_openstation_pwa_icons();

	return $manifest;
}
add_filter( 'openstation_pwa_manifest', 'snt_openstation_pwa_manifest_icons' );

/**
 * The 180x180 tile iOS uses for a home-screen install.
 *
 * #1017 fixed the MANIFEST, which is Android's path. iOS reads
 * `<link rel="apple-touch-icon">` instead, and OpenStation prints one into the
 * admin head (`includes/pwa.php`, on `admin_head` priority 1) because core
 * hooks `wp_site_icon()` to `wp_head` and `login_head` but not `admin_head` —
 * without it an iPhone installing from wp-admin would have no tile at all.
 *
 * That href comes from `openstation_pwa_apple_touch_icon_url()`, which returns
 * `get_site_icon_url( 180 )` whenever a Site Icon is set. Ours is PNG colour
 * type 6 with 61.6% fully transparent pixels, and iOS composites home-screen
 * transparency to BLACK behind a mark measuring luminance 23/255 — the black
 * tile. That function has no `apply_filters()`, so it cannot be overridden
 * directly; the seam is CORE's `get_site_icon_url`, which it calls.
 *
 * Scoped hard: admin only, and only the 180 request. Every other consumer of
 * the Site Icon — the browser-tab favicon above all, where transparency is
 * usually what you want — is untouched.
 *
 * @param string $url  Site Icon URL at this size.
 * @param int    $size Requested size in px.
 * @return string
 */
function snt_openstation_apple_touch_icon_url( $url, $size ) {
	if ( ! is_admin() || 180 !== (int) $size ) {
		return $url;
	}

	return SNT_URL . 'assets/pwa/icon-180.png';
}
add_filter( 'get_site_icon_url', 'snt_openstation_apple_touch_icon_url', 10, 2 );
