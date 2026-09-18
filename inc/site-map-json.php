<?php
/**
 * Signal & Noise Tools — /notes/index.json, the machine twin of the site.
 *
 * 15.5.0 (WebMCP bridge v2, arc one). What the site is, as structure, for the
 * bridge's `get-site-map` tool and any agent that asks: the pillars with their
 * notes, every published note (title, url, dates, tags, pillar, signed), every
 * published page, the provenance papers, the feeds and the rights pointer.
 * Owner decision 2026-09-16: "the sitemap should have everything." Drafts,
 * private and password-protected posts and `_sn_noindex` pages stay out by
 * the same rule that keeps them out of the XML sitemap.
 *
 * Built at each artifact rebuild (the same triggers as related notes) into one
 * option, never per request; served on a flush-free virtual route
 * (`template_redirect` priority 0, the did.json mechanism) with a short
 * public max-age. Never built answers a truthful 404, not an empty map.
 *
 * @package SignalNoiseTools
 * @since 15.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_SITE_MAP_OPT  = 'sn_site_map';
const SN_SITE_MAP_PATH = '/notes/index.json';

/**
 * The papers, the one hand-kept list here (they live on SSRN, not in WP).
 * Filterable through `sn_site_map_papers`.
 *
 * @return array<int,array{title:string,venue:string,status:string,url:string,id:string}>
 */
function sn_site_map_papers() {
	$papers = array(
		array( 'title' => 'Provenance Over Detection', 'venue' => 'SSRN', 'status' => 'published', 'id' => '6402298', 'url' => 'https://papers.ssrn.com/sol3/papers.cfm?abstract_id=6402298' ),
		array( 'title' => 'Provenance as Substrate', 'venue' => 'SSRN', 'status' => 'published', 'id' => '6730343', 'url' => 'https://papers.ssrn.com/sol3/papers.cfm?abstract_id=6730343' ),
		array( 'title' => 'Journal of the Audio Engineering Society manuscript', 'venue' => 'JAES', 'status' => 'in submission', 'id' => '', 'url' => '' ),
	);
	return (array) apply_filters( 'sn_site_map_papers', $papers );
}

/**
 * Is a published post visible to the public index? Password, noindex out.
 *
 * @param WP_Post $p
 * @return bool
 */
function sn_site_map_visible( $p ) {
	if ( '' !== (string) ( $p->post_password ?? '' ) ) {
		return false;
	}
	return '1' !== (string) get_post_meta( (int) $p->ID, '_sn_noindex', true );
}

/**
 * The pillar pages: `_sn_pillar` meta, in designation order. A note belongs
 * to a pillar when one of its tags is the first path segment of that
 * pillar's URL (the theme's rule: the `provenance` tag → /provenance/...).
 *
 * @param array<int,WP_Post> $pages
 * @return array<int,array{id:int,title:string,url:string,designation:string,tag:string}>
 */
function sn_site_map_pillars( array $pages ) {
	$out = array();
	foreach ( $pages as $p ) {
		if ( '1' !== (string) get_post_meta( (int) $p->ID, '_sn_pillar', true ) ) {
			continue;
		}
		$url   = (string) get_permalink( $p->ID );
		$path  = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$out[] = array(
			'id'          => (int) $p->ID,
			'title'       => html_entity_decode( (string) get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ),
			'url'         => $url,
			'designation' => (string) get_post_meta( (int) $p->ID, '_sn_pillar_designation', true ),
			'doi'         => function_exists( 'sn_zenodo_doi_for' ) ? sn_zenodo_doi_for( (int) $p->ID ) : '', // 15.11.0
			'tag'         => (string) strtok( $path, '/' ),
		);
	}
	usort( $out, static function ( $a, $b ) { return strcmp( $a['designation'], $b['designation'] ) ?: ( $a['id'] <=> $b['id'] ); } );
	return $out;
}

/**
 * Build the map. Reads WP; writes nothing.
 *
 * @return array<string,mixed>
 */
function sn_site_map_build() {
	$home  = home_url( '/' );
	$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
	$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
	$pages = array_values( array_filter( $pages, 'sn_site_map_visible' ) );
	$posts = array_values( array_filter( $posts, 'sn_site_map_visible' ) );

	$pillars = sn_site_map_pillars( $pages );
	$by_tag  = array();
	foreach ( $pillars as $i => $pl ) {
		// Several pillar pages share a path prefix (/provenance/...); the first in
		// designation order owns the tag, as the theme's map points it.
		if ( '' !== $pl['tag'] && ! isset( $by_tag[ $pl['tag'] ] ) ) {
			$by_tag[ $pl['tag'] ] = $i;
		}
		$pillars[ $i ]['notes'] = array();
	}

	$notes = array();
	foreach ( $posts as $p ) {
		$tags   = array_map( 'strval', (array) wp_get_post_tags( (int) $p->ID, array( 'fields' => 'slugs' ) ) );
		$pillar = '';
		foreach ( $tags as $t ) {
			if ( isset( $by_tag[ $t ] ) ) {
				$pillar = $t;
				$pillars[ $by_tag[ $t ] ]['notes'][] = (int) $p->ID;
				break;
			}
		}
		$notes[] = array(
			'id'        => (int) $p->ID,
			'title'     => html_entity_decode( (string) get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ),
			'url'       => (string) get_permalink( $p->ID ),
			'published' => (string) get_post_time( 'c', true, $p ),
			'updated'   => (string) get_post_modified_time( 'c', true, $p ),
			'tags'      => $tags,
			'pillar'    => $pillar,
			'signed'    => function_exists( 'sn_prov_machine_pointers_manifest' ) && null !== sn_prov_machine_pointers_manifest( (int) $p->ID ),
			// 15.11.0: the production DOI, '' until minted (a sandbox DOI never lands here).
			'doi'       => function_exists( 'sn_zenodo_doi_for' ) ? sn_zenodo_doi_for( (int) $p->ID ) : '',
		);
	}

	$page_rows = array();
	foreach ( $pages as $p ) {
		$page_rows[] = array( 'id' => (int) $p->ID, 'title' => html_entity_decode( (string) get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ), 'url' => (string) get_permalink( $p->ID ), 'updated' => (string) get_post_modified_time( 'c', true, $p ) );
	}

	return array(
		'version'  => 1,
		'built_at' => gmdate( 'c' ),
		'site'     => array( 'name' => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), 'url' => $home, 'author' => 'Juan Lentino', 'orcid' => 'https://orcid.org/0009-0006-8151-5920' ),
		'counts'   => array( 'notes' => count( $notes ), 'pages' => count( $page_rows ), 'pillars' => count( $pillars ) ),
		'pillars'  => $pillars,
		'notes'    => $notes,
		'pages'    => $page_rows,
		'papers'   => sn_site_map_papers(),
		'feeds'    => array( 'notes' => $home . 'notes/feed/', 'site' => get_feed_link() ),
		'rights'   => array( 'terms' => $home . 'tdm-policy/', 'tdmrep' => $home . '.well-known/tdmrep.json', 'license' => $home . 'license.xml' ),
	);
}

/**
 * Build and store. Rides the ML rebuild hooks (daily backstop + the coalesced
 * publish event) at priority 20, after the artifacts.
 *
 * @return array<string,mixed>
 */
function sn_site_map_refresh() {
	$map = sn_site_map_build();
	update_option( SN_SITE_MAP_OPT, $map, false );
	return $map;
}
add_action( SNT_ML_REBUILD_HOOK, 'sn_site_map_refresh', 20 );
add_action( SNT_ML_REBUILD_ASYNC_HOOK, 'sn_site_map_refresh', 20 );

/**
 * The stored map, or null when never built. Never builds.
 *
 * @return array<string,mixed>|null
 */
function sn_site_map_read() {
	$stored = get_option( SN_SITE_MAP_OPT );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Is this request for the map? Path only; query strings ignored.
 *
 * @param string $request_uri
 * @return bool
 */
function sn_site_map_is_request( $request_uri ) {
	$path = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
	return rtrim( $path, '/' ) === SN_SITE_MAP_PATH;
}

/** Serve it: 200 + JSON with a short public max-age, or a truthful 404. */
function sn_site_map_send() {
	$map = sn_site_map_read();
	if ( null === $map ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		return;
	}
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'Access-Control-Allow-Origin: *' );
	}
	echo wp_json_encode( $map, JSON_UNESCAPED_SLASHES );
}

function sn_site_map_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_site_map_is_request( $req ) ) {
		sn_site_map_send();
		exit;
	}
}
if ( ! defined( 'SN_SITE_MAP_TEST' ) || ! SN_SITE_MAP_TEST ) {
	add_action( 'template_redirect', 'sn_site_map_maybe_serve', 0 );
}
