<?php
/**
 * Signal & Noise: legacy 301s for old page paths (first the maturity family's).
 *
 * 2026-07-30 the family re-parented under /maturity/, killing the previously
 * shared top-level URLs (/analytics/, /proof-of-origin/, /ai-maturity/) with
 * 404s, and nothing caught them. Core's old-slug redirect skips pages (they
 * are hierarchical). Core's 404 slug guessing, redirect_guess_404_permalink(),
 * is on: nothing hooks do_redirect_guess_404_permalink (this note used to say
 * it was disabled site-wide; neither this plugin nor the theme has ever hooked
 * it), and it did not catch these paths. Whether to keep it on is an open
 * owner question. Since 2026-10-24 a page renamed or re-parented in place
 * records its own 301 in the redirect store (inc/page-rename-redirects.php).
 * This module is the NARROW exception for paths that predate that: a fixed,
 * filterable map of old page paths → page slugs, resolved through
 * get_permalink() at request time (per the v10.11.2 rule: never hardcode a
 * page path). It fires only on a 404, only for mapped paths, and only
 * when the target resolves to a publicly viewable published page — no
 * generic guessing, no existence oracle beyond pages that are public anyway.
 *
 * 2026-10: the map became the plugin's one code-level map of old PAGE paths,
 * since Search Console listed three more as 404s. A key is the whole path
 * (one segment or several), matched exactly, so a deeper path under a mapped
 * one is still a 404. The Spanish targets are the slugs the bilingual
 * pairing seeds for the English pages (inc/bilingual-seed.php), the same
 * pages hreflang es names. The notes have no Spanish page, so /notas/,
 * /es/notes/ and /es/notas/ go to the English /notes/ (owner, 2026-10-18).
 *
 * @package SignalNoiseTools
 * @since 10.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Old page path → target page slug. Values are SLUGS resolved
 * at request time, so future re-parenting keeps working. Filterable for the
 * next moat (or removal once a redirect has outlived its inbound links).
 *
 * @return array<string,string>
 */
function sn_legacy_page_redirect_map() {
	$map = array(
		'analytics'           => 'analytics',
		'proof-of-origin'     => 'proof-of-origin',
		'ai-maturity'         => 'ai-maturity',
		'machine-readability' => 'machine-readability',
		'ops-maturity'        => 'ops-maturity',
		'a11y-maturity'       => 'a11y-maturity',
		// 2026-10, Search Console 404s.
		'provenance/verify'   => 'verify',
		'es'                  => 'inicio',   // the home page's hreflang es
		'es/about'            => 'sobre-mi', // /about/'s hreflang es
		// 2026-10-18 (owner): the notes are only in English, and a Spanish
		// reader goes to them anyway (a browser translator covers the rest).
		'notas'               => 'notes',
		'es/notes'            => 'notes',
		'es/notas'            => 'notes',
	);
	/**
	 * Deprecated alias of `sn_legacy_page_redirects`, from when the map held
	 * only the maturity family's paths. Still applied (first), so an existing
	 * callback keeps working; new code hooks `sn_legacy_page_redirects`.
	 *
	 * @deprecated Use sn_legacy_page_redirects.
	 * @param array<string,string> $map
	 */
	$map = (array) apply_filters( 'sn_maturity_legacy_redirects', $map );
	/**
	 * Filter the legacy-redirect map. Keys are whole paths without the outer
	 * slashes, matched exactly and ONLY on 404 responses; values are page
	 * slugs (never paths).
	 *
	 * @param array<string,string> $map
	 */
	return (array) apply_filters( 'sn_legacy_page_redirects', $map );
}

/**
 * Resolve a mapped slug to its live permalink, or '' when it does not
 * resolve to a publicly viewable published page.
 *
 * @param string $slug Page slug.
 * @return string
 */
function sn_legacy_page_redirect_target( $slug ) {
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
function sn_legacy_page_redirect_decision( $request_path, $is_404 ) {
	if ( ! $is_404 ) {
		return '';
	}
	$key = implode( '/', array_filter( explode( '/', (string) wp_parse_url( $request_path, PHP_URL_PATH ) ) ) );
	$map = sn_legacy_page_redirect_map();
	if ( '' === $key || ! isset( $map[ $key ] ) ) {
		return ''; // Exact paths only: a deeper path under a mapped one was never shared.
	}
	$target = sn_legacy_page_redirect_target( (string) $map[ $key ] );
	// 2026-10: the query rides along, so an old /provenance/verify?note=&v=
	// link still verifies that note. Parsed and re-encoded, never raw.
	$query = (string) wp_parse_url( $request_path, PHP_URL_QUERY );
	if ( '' === $target || '' === $query ) {
		return $target;
	}
	parse_str( $query, $args );
	if ( ! $args ) {
		return $target;
	}
	// Keys are encoded too: add_query_arg() leaves them raw, so a decoded `a&b` key would split.
	$encoded = array();
	foreach ( urlencode_deep( $args ) as $arg_key => $value ) {
		$encoded[ rawurlencode( (string) $arg_key ) ] = $value;
	}
	return add_query_arg( $encoded, $target );
}

/**
 * template_redirect handler: 301 the mapped legacy URLs to their pages.
 */
function sn_legacy_page_redirect_handler() {
	if ( ! is_404() ) {
		return;
	}
	$request = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';
	$target  = sn_legacy_page_redirect_decision( $request, true );
	if ( '' === $target ) {
		return;
	}
	wp_safe_redirect( $target, 301 );
	exit;
}
add_action( 'template_redirect', 'sn_legacy_page_redirect_handler' );
