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

add_action( 'init', function() {
	// While TSF is active, let TSF serve its own sitemap routes.
	if ( function_exists( 'the_seo_framework' ) ) {
		return;
	}

	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return;
	}

	$path = trim( $path, '/' );

	$legacy_routes = array(
		'sitemap.xml',
		'sitemap_index.xml',
		'sitemap.xsl',
	);

	if ( ! in_array( $path, $legacy_routes, true ) ) {
		return;
	}

	// 301 to WP core's sitemap index.
	wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 301 );
	exit;
}, 1 );
