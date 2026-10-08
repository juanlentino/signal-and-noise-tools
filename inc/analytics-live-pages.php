<?php
/**
 * Signal & Noise Tools: "being read now", the pages behind the live count.
 *
 * One grouped query on each realtime refresh: distinct readers per path over
 * the same 5-minute window as "Reading now", human only, admin/login/asset
 * paths excluded. A path is listed only when it resolves to a published,
 * unprotected page or note the owner has not hidden from search (or is
 * Home): a draft, a private or noindex page, another post type or a junk
 * path never appears, even when someone is on it. Top five. Aggregate counts,
 * cookieless as the beacon is; nothing new is collected.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_PAGES_MAX = 5;

/**
 * @return string AE SQL.
 */
function sn_analytics_live_pages_sql() {
	$mins = max( 1, (int) SN_ANALYTICS_REALTIME_WINDOW_MIN );
	return implode( ' ', array(
		'SELECT blob2 AS path, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '{$mins}' MINUTE AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY path ORDER BY readers DESC LIMIT 40',
	) );
}

/**
 * Rows to the public list. PURE given the resolver: canonical path (no query,
 * no fragment, trailing slash), counts summed per page, unresolved paths
 * dropped, top five by readers.
 *
 * @param array    $rows    AE rows { path, readers }.
 * @param callable $resolve path => array{0:string label,1:string url}|null.
 * @return array<int,array{label:string,url:string,readers:int}>
 */
function sn_analytics_live_pages_from_rows( array $rows, callable $resolve ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['path'], $row['readers'] ) ) {
			continue;
		}
		$path = (string) preg_replace( '/[?#].*$/', '', (string) $row['path'] );
		if ( '' === $path || '/' !== $path[0] ) {
			continue;
		}
		$path = rtrim( $path, '/' ) . '/';
		$by[ $path ] = ( $by[ $path ] ?? 0 ) + max( 0, (int) $row['readers'] );
	}
	// Keyed by the page the path resolves to, not the path string: /About/
	// and /about/ (or a /2/ suffix) are one page, listed once with one count,
	// and a forged case variant cannot duplicate an entry (review on #1964).
	$out = array();
	foreach ( $by as $path => $readers ) {
		$hit = $resolve( $path );
		if ( ! is_array( $hit ) || $readers < 1 ) {
			continue;
		}
		$url = (string) $hit[1];
		if ( isset( $out[ $url ] ) ) {
			$out[ $url ]['readers'] += (int) $readers;
		} else {
			$out[ $url ] = array( 'label' => (string) $hit[0], 'url' => $url, 'readers' => (int) $readers );
		}
	}
	$out = array_values( $out );
	usort( $out, static fn( $a, $b ) => $b['readers'] <=> $a['readers'] ?: strcmp( $a['label'], $b['label'] ) );
	return array_slice( $out, 0, SN_ANALYTICS_LIVE_PAGES_MAX );
}

/**
 * The site's resolver: Home, or a published page or note with no password.
 *
 * @param string $path Canonical path.
 * @return array{0:string,1:string}|null
 */
function sn_analytics_live_pages_resolve( $path ) {
	if ( '/' === $path ) {
		return array( __( 'Home', 'signal-and-noise-tools' ), home_url( '/' ) );
	}
	$id = url_to_postid( home_url( $path ) );
	if ( $id < 1 || 'publish' !== get_post_status( $id ) || post_password_required( $id ) || '' !== (string) get_post_field( 'post_password', $id ) ) {
		return null;
	}
	// Notes and pages only, and never one the owner hides from search: an
	// unlisted page sent to one person must not show up, with its reader, on a
	// public page (review on #1964).
	if ( ! in_array( get_post_type( $id ), array( 'post', 'page' ), true ) ) {
		return null;
	}
	if ( function_exists( 'sn_post_settings_get_noindex' ) ? sn_post_settings_get_noindex( $id ) : '1' === (string) get_post_meta( $id, '_sn_noindex', true ) ) {
		return null;
	}
	$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	return '' === $title ? null : array( $title, (string) get_permalink( $id ) );
}

/**
 * The read, for the realtime refresh. Null on a failed query (the list is
 * then absent, never empty), an array (possibly empty) on an answer.
 *
 * @return array|null
 */
function sn_analytics_live_pages_read() {
	$rows = sn_analytics_query( sn_analytics_live_pages_sql() );
	return is_array( $rows ) ? sn_analytics_live_pages_from_rows( $rows, 'sn_analytics_live_pages_resolve' ) : null;
}
