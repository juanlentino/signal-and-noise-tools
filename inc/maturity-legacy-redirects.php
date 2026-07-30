<?php
/**
 * Signal & Noise — legacy 301s for the maturity family's old top-level URLs.
 *
 * 2026-07-30 the family re-parented under /maturity/, killing the previously
 * shared top-level URLs (/analytics/, /proof-of-origin/, /ai-maturity/) with
 * 404s — and general 404-slug-guessing is deliberately disabled site-wide,
 * so nothing caught them. This module is the NARROW exception: a fixed,
 * filterable map of old first-path-segments → page slugs, resolved through
 * get_permalink() at request time (per the v10.11.2 rule: never hardcode a
 * page path). It fires only on a 404, only for mapped segments, and only
 * when the target resolves to a publicly viewable published page — no
 * generic guessing, no existence oracle beyond pages that are public anyway.
 *
 * @package SignalNoiseTools
 * @since 10.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Old top-level path segment → target page slug. Values are SLUGS resolved
 * at request time, so future re-parenting keeps working. Filterable for the
 * next moat (or removal once a redirect has outlived its inbound links).
 *
 * @return array<string,string>
 */
function sn_maturity_legacy_redirect_map() {
	$map = array(
		'analytics'           => 'analytics',
		'proof-of-origin'     => 'proof-of-origin',
		'ai-maturity'         => 'ai-maturity',
		'machine-readability' => 'machine-readability',
		'ops-maturity'        => 'ops-maturity',
		'a11y-maturity'       => 'a11y-maturity',
	);
	/**
	 * Filter the legacy-redirect map. Keys are first path segments matched
	 * ONLY on 404 responses; values are page slugs (never paths).
	 *
	 * @param array<string,string> $map
	 */
	return (array) apply_filters( 'sn_maturity_legacy_redirects', $map );
}

/**
 * Resolve a mapped slug to its live permalink, or '' when it does not
 * resolve to a publicly viewable published page.
 *
 * @param string $slug Page slug.
 * @return string
 */
function sn_maturity_legacy_redirect_target( $slug ) {
	$pages = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'name'           => (string) $slug,
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );
	if ( ! $pages ) {
		return '';
	}
	$page = $pages[0];
	if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $page ) ) {
		return '';
	}
	return (string) get_permalink( $page );
}

/**
 * PURE decision: given the request path and a 404 state, which URL (if any)
 * should this request 301 to? Separated from the hook for testability.
 *
 * @param string $request_path The request path (no query string).
 * @param bool   $is_404       Whether WP resolved this request to a 404.
 * @return string Redirect URL, or '' for no redirect.
 */
function sn_maturity_legacy_redirect_decision( $request_path, $is_404 ) {
	if ( ! $is_404 ) {
		return '';
	}
	$segments = array_values( array_filter( explode( '/', (string) wp_parse_url( $request_path, PHP_URL_PATH ) ) ) );
	if ( 1 !== count( $segments ) ) {
		return ''; // Only the family's old TOP-LEVEL urls; deeper paths were never shared.
	}
	$map = sn_maturity_legacy_redirect_map();
	if ( ! isset( $map[ $segments[0] ] ) ) {
		return '';
	}
	return sn_maturity_legacy_redirect_target( (string) $map[ $segments[0] ] );
}

/**
 * template_redirect handler: 301 the mapped legacy URLs to their pages.
 */
function sn_maturity_legacy_redirect_handler() {
	if ( ! is_404() ) {
		return;
	}
	$target = sn_maturity_legacy_redirect_decision( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), true );
	if ( '' === $target ) {
		return;
	}
	wp_safe_redirect( $target, 301 );
	exit;
}
add_action( 'template_redirect', 'sn_maturity_legacy_redirect_handler' );
