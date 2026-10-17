<?php
/**
 * Signal & Noise Tools — Sitemap URL redirect.
 *
 * After Phase 13 TSF cutover, the canonical sitemap moves from TSF's
 * /sitemap.xml route to WP core's /wp-sitemap.xml. Google Search
 * Console may have /sitemap.xml registered; the 301 here preserves
 * crawl continuity by redirecting old requests to the new location.
 *
 * Routes covered:
 *   /sitemap.xml          — TSF's main sitemap
 *   /sitemap_index.xml    — TSF's index variant
 *   /sitemap.xsl          — TSF's stylesheet (404s harmlessly without
 *                            redirect, but explicit is cleaner)
 *   /sitemap/             (2026-10: a Search Console 404, a guessed address)
 *
 * Gated on TSF: while TSF is active, its own routes serve directly and
 * this redirect doesn't fire. The instant TSF deactivates, this kicks in.
 *
 * Added in v2.0.0 (Phase 13 TSF cutover, 2026-05-17).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this request URI one of the old sitemap addresses? Pure, so it is
 * testable without the exit (tests/sitemap-redirect.php).
 *
 * @param string $uri Request URI (may carry a query string).
 * @return bool
 */
function sn_sitemap_legacy_redirect_matches( $uri ) {
	$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return false;
	}

	$legacy_routes = array(
		'sitemap.xml',
		'sitemap_index.xml',
		'sitemap.xsl',
		'sitemap',
	);

	return in_array( trim( $path, '/' ), $legacy_routes, true );
}

add_action( 'init', function() {
	// While TSF is active, let TSF serve its own sitemap routes.
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! sn_sitemap_legacy_redirect_matches( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) {
		return;
	}

	// 301 to WP core's sitemap index.
	wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 301 );
	exit;
}, 1 );
