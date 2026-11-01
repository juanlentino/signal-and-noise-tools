<?php
/**
 * Signal & Noise Tools: who is reading now, one line per reader. ADMIN ONLY
 * (owner brief "MCP full analytics access", 2026-10-31: Live gains city,
 * device and entry source per reader).
 *
 * One query on each realtime refresh: presumed-human page views, scrolls and
 * timing beacons of the last two hours, split into visits at the Sessions
 * tab's idle gap. A reader is a visit with any of them in the live window, as
 * the live count counts readers; the line says where they are
 * (city, region, country), on what (device), where the visit came in from
 * (the dashboard's source name of its first page view), the page they are on
 * now and how many pages the visit has read. A visit that began more than two
 * hours ago shows the source of its first page view inside them, and one with
 * no page view inside them shows no source.
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

/**
 * @return string AE SQL.
 */
function sn_analytics_live_readers_sql() {
	$look = max( 1, (int) SN_ANALYTICS_LIVE_READERS_LOOKBACK );
	return implode( ' ', array(
		'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts, blob1 AS ev, blob2 AS path, ' . sn_analytics_referrer_value_sql( 'blob3' ) . ' AS ref, blob4 AS country, blob10 AS region, blob11 AS city, blob5 AS device',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '{$look}' MINUTE AND blob1 IN ('pv','sc','tm') AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'LIMIT 5000',
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
	$out   = array();
	foreach ( sn_sessionize( $rows, SN_ANALYTICS_SESSION_GAP_SEC ) as $visit ) {
		$last = end( $visit );
		if ( (int) $last['ts'] < $since ) {
			continue;
		}
		$views  = array_values( array_filter( $visit, static fn( $e ) => 'pv' === ( $e['ev'] ?? '' ) ) );
		$first  = $views ? $views[0] : array();
		$start  = $views ? (int) $first['ts'] : (int) reset( $visit )['ts'];
		$who    = $views ? $first : $last; // place and device ride on every event.
		$path   = sn_analytics_live_pages_canon( $last['path'] ?? '' );
		$hit    = null === $path ? null : $resolve( $path );
		$code   = null === $path || ! function_exists( 'sn_link_code_from_path' ) ? '' : sn_link_code_from_path( rtrim( $path, '/' ) );
		$source = $views ? sn_analytics_canonical_source( (string) ( $first['ref'] ?? '' ), $self_hosts ) : '';
		$place  = static fn( $v ) => trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $v ) );
		$out[]  = array(
			'city'    => mb_substr( $place( $who['city'] ?? '' ), 0, 128 ),
			'region'  => mb_substr( $place( $who['region'] ?? '' ), 0, 128 ),
			'country' => preg_match( '/^[A-Z0-9]{2}$/', (string) ( $who['country'] ?? '' ) ) ? (string) $who['country'] : '',
			'device'  => preg_match( '/^[a-z]{1,20}$/', (string) ( $who['device'] ?? '' ) ) ? (string) $who['device'] : '',
			'source'  => '(direct)' === $source ? __( 'Direct', 'signal-and-noise-tools' ) : $source,
			/* translators: %s: the code of a coded résumé link. */
			'page'    => is_array( $hit ) ? (string) $hit[0] : ( '' !== $code ? sprintf( __( 'Resume via %s', 'signal-and-noise-tools' ), $code ) : '(unmatched)' ),
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
	return is_array( $rows ) && function_exists( 'sn_sessionize' ) ? sn_analytics_live_readers_from_rows( $rows, time(), 'sn_analytics_live_pages_resolve' ) : null;
}
