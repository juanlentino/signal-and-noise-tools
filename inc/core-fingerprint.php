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
 *   - emoji, entirely: the detection script, its styles, the TinyMCE plugin,
 *     the s.w.org SVG URL, and the feed/mail staticize filters. The site has
 *     no use for it and it was the one asset still printing the core version.
 *   - the core version in `ver` on assets core itself registers (src under
 *     /wp-includes/ or /wp-admin/, ver === $wp_version). It is REPLACED by a
 *     salted token, not stripped, so the URL still changes on a core update and
 *     a browser holding a long-cached copy refetches.
 *   - the_generator, for every type (html, xhtml, rss2, atom, rdf, comment,
 *     export). The theme already strips it (inc/frontend-filters.php); this is
 *     the plugin's copy, so a theme swap cannot bring it back.
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

// admin-filters.php (admin_print_scripts, admin_enqueue_scripts, ...) loads
// AFTER init in wp-admin, so an init-only removal leaves the admin hooks on.
add_action( 'init', 'snt_core_fp_unhook' );
add_action( 'admin_init', 'snt_core_fp_unhook' );
add_filter( 'the_generator', '__return_empty_string' );
// emoji_svg_url false covers the old s.w.org dns-prefetch too; wp_resource_hints
// no longer adds one for emoji in 7.1, so there is no hint filter to add.
add_filter( 'emoji_svg_url', '__return_false' );
add_filter( 'tiny_mce_plugins', 'snt_core_fp_tinymce_plugins' );
add_filter( 'script_loader_src', 'snt_core_fp_ver' );
add_filter( 'style_loader_src', 'snt_core_fp_ver' );

/** Remove every emoji hook core registers, plus the wp_head generator. */
function snt_core_fp_unhook() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'embed_head', 'print_emoji_detection_script' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
	// Back-compat hooks that wp_enqueue_emoji_styles would have unhooked itself.
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	remove_action( 'wp_head', 'wp_generator' );
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
