<?php
/**
 * Signal & Noise Tools: who is reading now, one line per reader. ADMIN ONLY
 * (owner brief "MCP full analytics access", 2026-10-31: Live gains city,
 * device and entry source per reader).
 *
 * One query on each realtime refresh: presumed-human page views, scrolls and
 * timing beacons of the last two hours on the site's own paths, split into
 * visits at the Sessions tab's idle gap. A reader is a visit with any of them
 * in the live window (the live count also counts custom events and excluded
 * paths, so it can be a little higher); the line says where they are
 * (city, region, country), on what (device), where the visit came in from
 * (the dashboard's source name of its first page view), the page they are on
 * now and how many pages the visit has read. A visit that began more than two
 * hours ago shows the source of its first page view inside them, and one with
 * no page view inside them, or whose first one is an internal click, shows no
 * source. A read that reaches the row cap is not shown at all (null), never
 * as a partial list.
 *
 * Kept only in the realtime transient (a few minutes), never in the stored
 * slots, and only the gated admin answer carries it: the public live answer
 * is unchanged. No visitor code, IP address or user agent leaves this file.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_READERS_MAX      = 20;
const SN_ANALYTICS_LIVE_READERS_LOOKBACK = 120; // minutes: a visit's start, within reason.
const SN_ANALYTICS_LIVE_READERS_ROW_CAP  = 5000;

/**
 * @return string AE SQL.
 */
function sn_analytics_live_readers_sql() {
	$look = max( 1, (int) SN_ANALYTICS_LIVE_READERS_LOOKBACK );
	return implode( ' ', array(
		'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts, blob1 AS ev, blob2 AS path, ' . sn_analytics_referrer_value_sql( 'blob3' ) . ' AS ref, blob4 AS country, blob10 AS region, blob11 AS city, blob5 AS device',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		// The human class carries the over-cap list once.
		"WHERE timestamp >= now() - INTERVAL '{$look}' MINUTE AND blob1 IN ('pv','sc','tm') AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql(),
		'LIMIT ' . SN_ANALYTICS_LIVE_READERS_ROW_CAP,
	) );
}

/**
 * Rows to the readers. PURE given the resolver and the clock.
 *
 * @param array      $rows       AE rows.
 * @param int        $now        Unix time.
 * @param callable   $resolve    Canonical path => array{0:label,1:url}|null (sn_analytics_live_pages_resolve()).
 * @param array|null $self_hosts The site's own hosts (null reads the site's).
 * @return array<int,array{city:string,region:string,country:string,device:string,source:string,page:string,url:string,pages:int,minutes:int}>
 */
function sn_analytics_live_readers_from_rows( array $rows, $now, callable $resolve, $self_hosts = null ) {
	$since = (int) $now - 60 * max( 1, (int) SN_ANALYTICS_REALTIME_WINDOW_MIN );
	$gap   = function_exists( 'sn_analytics_session_config' ) ? sn_analytics_session_config()['gap_sec'] : SN_ANALYTICS_SESSION_GAP_SEC;
	$seen  = array(); // one resolve per page, however many readers are on it.
	$out   = array();
	foreach ( sn_sessionize( $rows, $gap ) as $visit ) {
		$last = end( $visit );
		if ( (int) $last['ts'] < $since ) {
			continue;
		}
		$views  = array_values( array_filter( $visit, static fn( $e ) => 'pv' === ( $e['ev'] ?? '' ) ) );
		$first  = $views ? $views[0] : array();
		$start  = $views ? (int) $first['ts'] : (int) reset( $visit )['ts'];
		$who    = $views ? $first : $last; // place and device ride on every event.
		$path   = sn_analytics_live_pages_canon( $last['path'] ?? '' );
		$hit    = null;
		if ( null !== $path ) {
			$seen[ $path ] = array_key_exists( $path, $seen ) ? $seen[ $path ] : $resolve( $path );
			$hit           = $seen[ $path ];
		}
		// A coded résumé link reads as the pages list reads it ("Resume via hera"), with the résumé's own title and URL.
		$coded  = null === $hit && null !== $path && function_exists( 'sn_link_codes_live_from_rows' ) ? sn_link_codes_live_from_rows( array( array( 'path' => rtrim( $path, '/' ), 'readers' => 1 ) ) ) : array();
		$hit    = $hit ?? ( $coded ? array( $coded[0]['label'], $coded[0]['url'] ) : null );
		$source = $views ? sn_analytics_live_source_label( sn_analytics_canonical_source( (string) ( $first['ref'] ?? '' ), $self_hosts ) ) : '';
		$place  = static fn( $v ) => trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $v ) );
		$out[]  = array(
			'city'    => mb_substr( $place( $who['city'] ?? '' ), 0, 128 ),
			'region'  => mb_substr( $place( $who['region'] ?? '' ), 0, 128 ),
			'country' => preg_match( '/^[A-Z0-9]{2}$/', (string) ( $who['country'] ?? '' ) ) ? (string) $who['country'] : '',
			'device'  => preg_match( '/^[a-z]{1,20}$/', (string) ( $who['device'] ?? '' ) ) ? (string) $who['device'] : '',
			'source'  => $source,
			'page'    => is_array( $hit ) ? (string) $hit[0] : '(unmatched)',
			'url'     => is_array( $hit ) ? (string) $hit[1] : '',
			'pages'   => count( $views ),
			'minutes' => max( 0, (int) floor( ( (int) $now - $start ) / 60 ) ),
			'_ts'     => (int) $last['ts'],
		);
	}
	usort( $out, static fn( $a, $b ) => $b['_ts'] <=> $a['_ts'] ?: strcmp( $a['page'], $b['page'] ) );
	return array_map( static fn( $r ) => array_diff_key( $r, array( '_ts' => 1 ) ), array_slice( $out, 0, SN_ANALYTICS_LIVE_READERS_MAX ) );
}

/**
 * The read, for the realtime refresh. Null on a failed query.
 *
 * @return array|null
 */
function sn_analytics_live_readers_read() {
	$rows = sn_analytics_query( sn_analytics_live_readers_sql() );
	if ( ! is_array( $rows ) || count( $rows ) >= SN_ANALYTICS_LIVE_READERS_ROW_CAP || ! function_exists( 'sn_sessionize' ) ) {
		return null; // not read, or capped: a partial list would name the wrong readers.
	}
	return sn_analytics_live_readers_from_rows( $rows, time(), 'sn_analytics_live_pages_resolve' );
}
