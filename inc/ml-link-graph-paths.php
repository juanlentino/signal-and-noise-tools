<?php
/**
 * Signal & Noise: the link graph by PATH (2026-10-22).
 *
 * Split from inc/ml-link-isolation.php, which requires it. Pages joined the
 * graph as sources and targets, matched by their whole path so a tag archive
 * (/tag/uses/) never counts as a link to a page (/about/uses/). The isolation
 * report itself stays notes-only. No hooks, no network.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lookup form of a path: the weave join key, lowercased and URL-decoded,
 * so `/About/Uses/` and `/about/uses` are one node. PURE.
 *
 * @param string $path A path or URL.
 * @return string
 */
function snt_ml_path_key( $path ) {
	$key = function_exists( 'sn_path_join_key' ) ? sn_path_join_key( (string) $path ) : '/' . trim( (string) $path, '/' );
	return strtolower( rawurldecode( $key ) );
}


/**
 * Every internal path an href set points at, from one body (lookup form).
 *
 * @param string $content Post content.
 * @param string $our_host Site host.
 * @return string[]
 */
function snt_ml_outbound_paths( $content, $our_host = null ) {
	$content = (string) $content;
	if ( '' === trim( $content ) || false === stripos( $content, '<a' ) || ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*["\']([^"\']*)["\']#i', $content, $m ) ) {
		return array();
	}
	return array_values( array_filter( array_map( static function ( $href ) use ( $our_host ) {
		return snt_ml_link_target_path( $href, $our_host );
	}, $m[1] ) ) );
}


/**
 * Inbound counts keyed by the WEAVE join key (v13.65.0), so the coverage map
 * and the disagreement scan join without a third spelling.
 *
 * 2026-10-22: published PAGES are nodes too, as sources and as targets (core
 * pages, the Spanish twins, /provenance and its children), matched by their
 * whole path. Every published note and page is a key, so an absent path is
 * not a node (a tag archive): unknown, not zero. linked_from_paths names the
 * sources by the same join key, for joins against the coverage map.
 *
 * @return array<string,array{inbound:int,linked_from:int[],linked_from_paths:string[]}>
 */
function snt_ml_inbound_by_path() {
	if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
		return array();
	}
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$nodes = array();
	$keys  = array(); // lookup path => the join key the coverage map uses.
	foreach ( array_merge( snt_corpus_fetch_posts( 'publish', 'post' ), snt_corpus_fetch_posts( 'publish', 'page' ) ) as $post ) {
		$url = function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '';
		$key = '' !== $url && function_exists( 'sn_path_join_key' ) ? sn_path_join_key( $url ) : '/' . (string) ( $post->post_name ?? '' );
		if ( '' === $key || '/' === $key ) {
			continue; // the front page is never a body-link target here.
		}
		$node          = clone $post; // never mutate the corpus objects.
		$node->sn_path = snt_ml_path_key( $key );
		$nodes[]       = $node;
		$keys[ $node->sn_path ] = $key;
	}
	$graph = snt_ml_link_graph( $nodes, $host );
	$path_of = array();
	foreach ( $graph as $lookup => $row ) {
		$path_of[ (int) $row['post_id'] ] = $keys[ $lookup ] ?? $lookup;
	}
	$out = array();
	foreach ( $graph as $lookup => $row ) {
		$out[ $keys[ $lookup ] ?? $lookup ] = array(
			'inbound'           => (int) $row['inbound'],
			'linked_from'       => $row['linked_from'],
			'linked_from_paths' => array_values( array_map( static function ( $id ) use ( $path_of ) {
				return (string) ( $path_of[ (int) $id ] ?? '' );
			}, $row['linked_from'] ) ),
		);
	}
	return $out;
}

