<?php
/**
 * Signal & Noise — the core fingerprint.
 *
 * CVE-2026-87902 (GHSA-7hp8-65ch-5whp) let an anonymous request walk an encoded
 * ../ in `pagename` into an include. The site ran 7.1.2, already patched, but
 * the home page and /wp-login.php both announced it: wp-emoji-release.min.js
 * carried ?ver=7.1.2. An attacker matching sites to a CVE reads exactly that.
 *
 * What this file hides:
 *   - the core version in `ver` on assets core itself registers (src under
 *     /wp-includes/ or /wp-admin/, ver === $wp_version). It is REPLACED by a
 *     salted token, not stripped, so the URL still changes on a core update and
 *     a browser holding a long-cached copy refetches.
 *   - the_generator, for every type (html, xhtml, rss2, atom, rdf, comment,
 *     export). The theme already strips it (inc/frontend-filters.php); this is
 *     the plugin's copy, so a theme swap cannot bring it back.
 *
 * Emoji is stock on the public site (front end, embeds, feeds, email) and off
 * in wp-admin only: the admin detection script, admin styles and the TinyMCE
 * wpemoji plugin, as core's own block editor already does. Its
 * three script URLs (concatemoji, and wpemoji + twemoji under SCRIPT_DEBUG) are
 * built in _print_emoji_detection_script() (wp-includes/formatting.php, 7.1)
 * as includes_url( "js/...?ver=$wp_version" ) and passed through
 * `script_loader_src`, so the token filter below covers them; it keys on the
 * path and the version, never the handle. The s.w.org emoji_url / svgUrl carry
 * the emoji set's own version (17.0.2), not core's.
 *
 * What it keeps on purpose: plugin and theme `ver` cache-busters (our own
 * release numbers are public on GitHub anyway), and core assets whose ver is
 * not the core version (jquery 3.7.1 and friends).
 *
 * The `core` / `runtime` readings for get-deploy-status live beside it, in
 * inc/deploy-core-status.php. docs/SECURITY.md, "Core fingerprint".
 *
 * @package SignalNoiseTools
 * @since 19.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'snt_core_fp_unhook' );
// admin-filters.php adds the admin emoji hooks AFTER init, so admin_init.
add_action( 'admin_init', 'snt_core_fp_admin_emoji_off' );
add_filter( 'tiny_mce_plugins', 'snt_core_fp_tinymce_plugins' );
add_filter( 'the_generator', '__return_empty_string' );
add_filter( 'script_loader_src', 'snt_core_fp_ver' );
add_filter( 'style_loader_src', 'snt_core_fp_ver' );
// The concat URLs (/wp-admin/load-scripts.php, load-styles.php, used by
// wp-login.php and wp-admin) take ver from default_version and never pass
// through the src filters. Late priority: after core sets it.
add_action( 'wp_default_scripts', 'snt_core_fp_default_version', 999 );
add_action( 'wp_default_styles', 'snt_core_fp_default_version', 999 );

/** @param WP_Scripts|WP_Styles $deps Core's dependency registry. */
function snt_core_fp_default_version( $deps ) {
	$version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	if ( '' !== $version && is_object( $deps ) ) {
		$deps->default_version = snt_core_fp_token( $version );
	}
}

/** Remove the wp_head generator. */
function snt_core_fp_unhook() {
	remove_action( 'wp_head', 'wp_generator' );
}

/** Emoji off in wp-admin only; the public site keeps core's hooks. */
function snt_core_fp_admin_emoji_off() {
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}

/** @param array $plugins TinyMCE plugin slugs. */
function snt_core_fp_tinymce_plugins( $plugins ) {
	return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
}

/** Opaque, per-site token for a core version: changes with core, reveals nothing. */
function snt_core_fp_token( $version ) {
	$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
	return substr( hash( 'sha256', $version . '|' . $salt ), 0, 10 );
}

/** Replace ver=$wp_version on core-registered assets; everything else untouched. */
function snt_core_fp_ver( $src ) {
	$version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	if ( '' === $version || ! is_string( $src ) || false === strpos( $src, 'ver=' ) ) {
		return $src;
	}
	$path = (string) parse_url( $src, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- path only; no WP dependency, so the guard runs standalone.
	if ( false === strpos( $path, '/wp-includes/' ) && false === strpos( $path, '/wp-admin/' ) ) {
		return $src;
	}
	$pattern = '/([?&])ver=' . preg_quote( $version, '/' ) . '(?=&|#|$)/';
	return (string) preg_replace( $pattern, '${1}ver=' . snt_core_fp_token( $version ), $src, 1 );
}
