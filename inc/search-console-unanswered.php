<?php
/**
 * Signal & Noise Tools: the searches the corpus does not answer.
 *
 * Queries Google showed the site for, at least SNT_GSC_UNANSWERED_FLOOR
 * times, that earned zero clicks in the synced window. Arithmetic only:
 * a filter, a sum, a sort and a cap over rows the daily sync stores. No
 * model reads them; the list is for the owner's eyes.
 *
 * THE GRAIN. The window's `queries` rows carry no page, so the daily sync
 * makes one more read, query x page (snt_gsc_fetch_query_pages(), called by
 * snt_gsc_sync()), and stores it compact under `query_pages`. Unlike the two
 * reads before it, a failure here does not abort the sync: pages and queries
 * are kept, and the error is stored beside a null `query_pages`.
 *
 * AGGREGATE, THEN FILTER. A query shown on two pages is one query: its
 * clicks and impressions sum across them, position is impression-weighted,
 * and the page shown is the one with the most impressions. Filtering per
 * row first would list a query that earned a click on its second page.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_GSC_UNANSWERED_FLOOR     = 5;   // impressions; the site's scale (a 469-impression month).
const SNT_GSC_UNANSWERED_CAP       = 25;  // rows shown.
const SNT_GSC_QUERY_PAGE_ROW_LIMIT = 500; // rows read; keeps the option in kilobytes.

/**
 * The query x page read, compacted for storage. Called by snt_gsc_sync().
 *
 * @param string $property Search Console property.
 * @param array  $window   From snt_gsc_window().
 * @return array{rows:array|null,capped:bool,error:string}
 */
function snt_gsc_fetch_query_pages( $property, $window ) {
	$raw = snt_gsc_query( $property, array( 'query', 'page' ), $window, SNT_GSC_QUERY_PAGE_ROW_LIMIT );
	if ( is_wp_error( $raw ) ) {
		return array( 'rows' => null, 'capped' => false, 'error' => $raw->get_error_message() );
	}
	$rows = array();
	foreach ( $raw as $r ) {
		$keys = (array) ( $r['keys'] ?? array() );
		if ( count( $keys ) < 2 ) {
			continue;
		}
		$rows[] = array(
			'query'       => trim( (string) $keys[0] ),
			'path'        => snt_gsc_url_to_path( (string) $keys[1] ),
			'clicks'      => (int) $r['clicks'],
			'impressions' => (int) $r['impressions'],
			'position'    => round( (float) $r['position'], 1 ),
		);
	}
	return array( 'rows' => $rows, 'capped' => count( $raw ) >= SNT_GSC_QUERY_PAGE_ROW_LIMIT, 'error' => '' );
}

/**
 * True when a path is the home page or an index (no single note behind it).
 *
 * @param string $path Site-relative path.
 * @return bool
 */
function snt_gsc_path_is_index( $path ) {
	return '/' === $path || 0 === (int) url_to_postid( home_url( $path ) );
}

/**
 * The list, PURE over the stored payload and an index predicate.
 *
 * @param array|null    $data     snt_gsc_data(); null when nothing has synced.
 * @param callable|null $is_index path => bool; null marks nothing.
 * @return array
 */
function snt_gsc_unanswered( $data, $is_index = null ) {
	$out = array(
		'state'        => 'measured',
		'window'       => is_array( $data['window'] ?? null ) ? $data['window'] : null,
		'floor'        => SNT_GSC_UNANSWERED_FLOOR,
		'cap'          => SNT_GSC_UNANSWERED_CAP,
		'rows'         => array(),
		'total'        => 0,
		'more'         => 0,
		'fetch_capped' => ! empty( $data['query_pages_capped'] ),
		'note'         => '',
	);
	if ( ! is_array( $data ) ) {
		return array_merge( $out, array( 'state' => 'never_synced', 'note' => 'No synced data yet.' ) );
	}
	if ( ! array_key_exists( 'query_pages', $data ) ) {
		return array_merge( $out, array( 'state' => 'awaiting_sync', 'note' => 'No synced data yet: query rows arrive with the next daily sync.' ) );
	}
	if ( ! is_array( $data['query_pages'] ) ) {
		return array_merge( $out, array( 'state' => 'fetch_failed', 'note' => 'The query rows did not sync: ' . (string) ( $data['query_pages_error'] ?? 'unknown error' ) ) );
	}

	$by = array();
	foreach ( $data['query_pages'] as $r ) {
		$q   = (string) ( $r['query'] ?? '' );
		$imp = (int) ( $r['impressions'] ?? 0 );
		if ( '' === $q ) {
			continue;
		}
		$a = $by[ $q ] ?? array( 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'page' => '', 'page_imp' => -1 );
		$a['clicks']      += (int) ( $r['clicks'] ?? 0 );
		$a['impressions'] += $imp;
		$a['weighted']    += (float) ( $r['position'] ?? 0 ) * $imp;
		$path              = (string) ( $r['path'] ?? '' );
		if ( $imp > $a['page_imp'] || ( $imp === $a['page_imp'] && strcmp( $path, $a['page'] ) < 0 ) ) {
			$a['page']     = $path;
			$a['page_imp'] = $imp;
		}
		$by[ $q ] = $a;
	}

	$rows = array();
	foreach ( $by as $q => $a ) {
		if ( 0 !== $a['clicks'] || $a['impressions'] < SNT_GSC_UNANSWERED_FLOOR ) {
			continue;
		}
		$rows[] = array(
			'query'       => (string) $q,
			'impressions' => $a['impressions'],
			'position'    => round( $a['weighted'] / $a['impressions'], 1 ),
			'page'        => $a['page'],
			'no_note'     => is_callable( $is_index ) && (bool) call_user_func( $is_index, $a['page'] ),
		);
	}
	usort( $rows, static function ( $x, $y ) {
		return ( $y['impressions'] <=> $x['impressions'] ) ?: strcmp( $x['query'], $y['query'] );
	} );

	$out['total'] = count( $rows );
	$out['rows']  = array_slice( $rows, 0, SNT_GSC_UNANSWERED_CAP );
	$out['more']  = max( 0, $out['total'] - SNT_GSC_UNANSWERED_CAP );
	$notes        = array( 0 === $out['total'] ? 'No query with impressions and no clicks in the window.' : '' );
	if ( $out['more'] > 0 ) {
		$notes[] = sprintf( '+%d more; the list is capped, not complete.', $out['more'] );
	}
	if ( $out['fetch_capped'] ) {
		$notes[] = sprintf( 'The sync read the top %d query and page rows, so queries below them are not counted.', SNT_GSC_QUERY_PAGE_ROW_LIMIT );
	}
	$out['note'] = trim( implode( ' ', $notes ) );
	return $out;
}

/**
 * The list for the live site: the stored window, the real index predicate.
 *
 * @return array
 */
function snt_gsc_unanswered_live() {
	return snt_gsc_unanswered( function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null, 'snt_gsc_path_is_index' );
}

/**
 * The section intro both leaves print, one sentence.
 *
 * @param array $u From snt_gsc_unanswered().
 * @return string Plain text.
 */
function snt_gsc_unanswered_intro( array $u ) {
	return sprintf(
		/* translators: %d: impression floor. */
		__( 'Queries Google showed the site for at least %d times that earned no click, most shown first. Counted, not judged: no model reads them.', 'signal-and-noise-tools' ),
		(int) $u['floor']
	);
}
