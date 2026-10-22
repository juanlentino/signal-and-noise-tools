<?php
/**
 * Signal & Noise Tools: the indexing guard's site links, the PURE side
 * (2026-10-22). Who links a path from outside the post bodies, read from
 * sn_indexing_site_links(): the chrome (every page), the single and page
 * templates (every indexed note or page) and the front-page template (the
 * home page). Counts are SETS of source paths, so one page linking in its
 * body and in its template is one source, never two.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site links with every key present. PURE.
 *
 * @param array $site sn_indexing_site_links(), or a part of it.
 * @return array{chrome:string[],template:array{post:string[],page:string[]},home:string[]}
 */
function sn_indexing_site_norm( $site ) {
	$site = (array) $site;
	return array(
		'chrome'   => array_values( (array) ( $site['chrome'] ?? array() ) ),
		'template' => array(
			'post' => array_values( (array) ( $site['template']['post'] ?? array() ) ),
			'page' => array_values( (array) ( $site['template']['page'] ?? array() ) ),
		),
		'home'     => array_values( (array) ( $site['home'] ?? array() ) ),
	);
}

/**
 * The chrome set widened by the Spanish twins of chrome-linked pages. PURE.
 *
 * @param string[] $chrome   Lookup paths.
 * @param array    $subjects Rows with path and en_path.
 * @return string[]
 */
function sn_indexing_chrome_expand( $chrome, $subjects ) {
	$set = array_fill_keys( array_map( 'snt_ml_path_key', (array) $chrome ), true );
	if ( array() === $set ) {
		return array();
	}
	foreach ( (array) $subjects as $s ) {
		if ( '' !== (string) ( $s['en_path'] ?? '' ) && isset( $set[ snt_ml_path_key( $s['en_path'] ) ] ) ) {
			$set[ snt_ml_path_key( $s['path'] ) ] = true;
		}
	}
	$out = array_keys( $set );
	sort( $out );
	return $out;
}

/**
 * Is this path (or its English counterpart) in a lookup-path set? PURE.
 *
 * @param string[] $set     Lookup paths.
 * @param string   $path    Join path.
 * @param string   $en_path English counterpart's join path, or ''.
 * @return bool
 */
function sn_indexing_chrome_has( $set, $path, $en_path = '' ) {
	foreach ( array_filter( array( (string) $path, (string) $en_path ) ) as $p ) {
		if ( in_array( snt_ml_path_key( $p ), (array) $set, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Which templates link this path: any of post, page, home. PURE.
 *
 * @param array  $site sn_indexing_site_norm().
 * @param string $path Join path.
 * @return string[]
 */
function sn_indexing_template_types( $site, $path ) {
	$sets = array( 'post' => $site['template']['post'], 'page' => $site['template']['page'], 'home' => $site['home'] );
	return array_keys( array_filter( $sets, static function ( $set ) use ( $path ) {
		return sn_indexing_chrome_has( $set, $path );
	} ) );
}

/**
 * Indexed source pages per template: notes (single), pages but the front page
 * (page), the front page (home). PURE.
 *
 * ponytail: "page" counts every indexed page, though pages with their own
 * page-{slug} template do not print page.html; resolve each page's template
 * if page.html ever carries links (it carries none on 2026-10-22).
 *
 * @param array $subjects Rows with path and type.
 * @param array $entries  Coverage map entries.
 * @return array<string,array<string,bool>>
 */
function sn_indexing_indexed_by_type( $subjects, $entries ) {
	$out = array( 'post' => array(), 'page' => array(), 'home' => array() );
	foreach ( (array) $subjects as $s ) {
		$path = (string) $s['path'];
		if ( '/' !== $path && isset( $out[ $s['type'] ] ) && true === ( $entries[ $path ]['indexed'] ?? null ) ) {
			$out[ $s['type'] ][ $path ] = true;
		}
	}
	if ( true === ( $entries['/']['indexed'] ?? null ) ) {
		$out['home']['/'] = true;
	}
	return $out;
}

/**
 * Indexed pages that link this path, body and templates as one set. PURE.
 * null when the graph holds no node for it and no template links it.
 *
 * @param string     $path     Join path.
 * @param array|null $inbound  snt_ml_inbound_by_path().
 * @param array      $entries  Coverage map entries.
 * @param string[]   $types    sn_indexing_template_types().
 * @param array      $by_type  sn_indexing_indexed_by_type().
 * @return int|null
 */
function sn_indexing_inbound_total( $path, $inbound, $entries, $types, $by_type ) {
	$node = is_array( $inbound ) && isset( $inbound[ $path ] );
	if ( ! $node && array() === $types ) {
		return null;
	}
	$sources = array();
	foreach ( $node ? (array) ( $inbound[ $path ]['linked_from_paths'] ?? array() ) : array() as $src ) {
		if ( true === ( $entries[ $src ]['indexed'] ?? null ) ) {
			$sources[ $src ] = true;
		}
	}
	foreach ( $types as $type ) {
		$sources += $by_type[ $type ] ?? array();
	}
	unset( $sources[ $path ] ); // a page never links itself into the count.
	return count( $sources );
}
