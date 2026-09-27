<?php
/**
 * 301 redirect map for merged-away Notes tags. sn_tag_merge() records
 * old-slug -> canonical-slug here; the template_redirect handler 301s
 * /notes/tag/<old>/ to /notes/tag/<canonical>/. Plugin-hosted (the plugin owns the
 * merge + map); the theme's priority-0 notes handler only exits for REAL tag
 * requests, so a deleted slug falls through to this priority-9 handler.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/tag-retired-map.php';

if ( ! defined( 'SN_TAG_REDIRECTS_OPT' ) ) {
	define( 'SN_TAG_REDIRECTS_OPT', 'sn_tag_redirects' );
}
if ( ! defined( 'SN_TAG_REDIRECTS_MAX' ) ) {
	define( 'SN_TAG_REDIRECTS_MAX', 500 );
}

/**
 * Record old-slug -> canonical-slug mappings. Collapses chains at write time: if any
 * existing entry pointed at a slug now being merged away, rewrite it to the new
 * canonical so redirects never chain at request time. Capped FIFO.
 *
 * @param array  $old_slugs      Merged-away slugs.
 * @param string $canonical_slug Surviving slug.
 * @return void
 */
function sn_tag_redirects_record( array $old_slugs, $canonical_slug ) {
	$map = get_option( SN_TAG_REDIRECTS_OPT, array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}
	$canonical_slug = (string) $canonical_slug;
	foreach ( $old_slugs as $old ) {
		$old = (string) $old;
		if ( '' === $old || $old === $canonical_slug ) {
			continue;
		}
		$map[ $old ] = $canonical_slug;
	}
	// Collapse: any entry pointing at one of the now-merged-away slugs follows to canonical.
	$merged_away = array_map( 'strval', $old_slugs );
	foreach ( $map as $from => $to ) {
		if ( in_array( $to, $merged_away, true ) ) {
			$map[ $from ] = $canonical_slug;
		}
	}
	if ( count( $map ) > SN_TAG_REDIRECTS_MAX ) {
		$map = array_slice( $map, -SN_TAG_REDIRECTS_MAX, null, true );
	}
	update_option( SN_TAG_REDIRECTS_OPT, $map );
}

/**
 * Pure resolver: given a request path/URI, return the canonical archive URL to 301
 * to, or '' for no redirect. Testable without exit(). Ignores live slugs (re-created
 * terms), unmapped slugs, and non tag-archive URLs.
 *
 * @param string $uri Request URI (may include a query string).
 * @return string Absolute redirect URL, or '' when no redirect applies.
 */
function sn_tag_redirect_target( $uri ) {
	$path = strtok( (string) $uri, '?' );
	// Both archive forms: /tag/<slug>/ (canonical) and /notes/tag/<slug>/,
	// and their /page/N/ pages, which land on the target's first page.
	if ( ! preg_match( '#^(/notes)?/tag/([^/]+)(?:/page/\d+)?/?$#', (string) $path, $mm ) ) {
		return '';
	}
	// 19.3.1: always the canonical /tag/ form. Live tags do not serve at
	// /notes/tag/<slug>/ (it 404s), so keeping the request's form sent a
	// retired /notes/tag/ link to a dead page.
	$base = '/tag/';
	$slug = $mm[2];
	$map  = get_option( SN_TAG_REDIRECTS_OPT, array() );
	$map  = is_array( $map ) ? $map : array();
	if ( ! isset( $map[ $slug ] ) && ! isset( SN_TAG_RETIRED_MAP[ $slug ] ) ) {
		return '';
	}
	if ( term_exists( $slug, 'post_tag' ) ) {
		return ''; // a live term with that slug wins
	}
	if ( isset( $map[ $slug ] ) ) {
		return home_url( $base . $map[ $slug ] . '/' );
	}
	// A retired tag: its survivor while that term lives, else the notes index.
	$to = SN_TAG_RETIRED_MAP[ $slug ];
	if ( 0 === strpos( $to, '/' ) ) {
		return home_url( $to );
	}
	return '' !== $to && term_exists( $to, 'post_tag' ) ? home_url( $base . $to . '/' ) : home_url( '/notes/' );
}

/**
 * template_redirect handler: 301 a merged-away tag archive to its survivor.
 *
 * @return void
 */
function sn_tag_redirect_maybe() {
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$target = sn_tag_redirect_target( $uri );
	if ( '' === $target ) {
		return;
	}
	wp_safe_redirect( $target, 301 );
	exit;
}

add_action( 'template_redirect', 'sn_tag_redirect_maybe', 9 );
