<?php
/**
 * Signal & Noise Tools: the analytics rows read from the sessions engine
 * (owner brief "MCP full analytics access", 2026-10-31: entry page, exit page
 * and returning in the query).
 *
 * Entry, exit and returning belong to a session (a within-day visit split at
 * the idle gap, inc/analytics-sessions-list.php), not to a page view, so no
 * rollup holds them: the rows read groups the sessions the Sessions tab lists.
 * A session's day and hour are its start's, in site time; its place and
 * device are its first page view's. Each group counts sessions, the page views
 * in them, the visitor-days they belong to, and the visible time and deepest
 * scroll of those views. Analytics Engine keeps about 90 days, as for events.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sessions into the rows read's raw rows, one per group. PURE.
 *
 * @param array $sessions sn_analytics_sessions_build() rows, with `_vid`.
 * @param array $dims     Validated dimensions (a subset of SNT_AROWS_SESSION_WITH).
 * @return array<int,array<string,mixed>> Rows in snt_arows_shape()'s input shape; no visitor code.
 */
function snt_arows_sessions_group( array $sessions, array $dims ) {
	$value = static fn( $s, $d ) => 'returning' === $d ? ( $s['returning'] ? 'returning' : 'new' ) : ( 'day' === $d ? substr( $s['start'], 0, 10 ) : ( 'hour' === $d ? substr( $s['start'], 11, 2 ) : (string) $s[ $d ] ) );
	$by    = array();
	foreach ( $sessions as $s ) {
		$keys = array();
		foreach ( $dims as $d ) {
			$keys[ $d ] = $value( $s, $d );
		}
		$k = implode( "\x1f", $keys );
		$m = $by[ $k ] ?? $keys + array( 'views' => 0, 'sessions' => 0, 'time_sum' => 0, 'time_events' => 0, 'scroll_events' => 0, 'vdays' => array() );
		$m['views']    += (int) $s['pageviews'];
		$m['sessions'] += 1;
		$m['vdays'][ (string) ( $s['_vid'] ?? '' ) ] = true;
		foreach ( (array) $s['pages'] as $p ) {
			$m['time_sum']      += (int) $p['time_ms'];
			$m['time_events']   += null === $p['time_ms'] ? 0 : 1;
			$m['scroll_events'] += (int) $p['scroll_pct'] / 25; // the stored rule: 25 points a milestone.
		}
		$by[ $k ] = $m;
	}
	return array_map( static fn( $m ) => array_diff_key( $m, array( 'vdays' => 1 ) ) + array( 'visits' => count( $m['vdays'] ), 'pageview_visits' => count( $m['vdays'] ) ), array_values( $by ) );
}

/**
 * The rows for a validated session query over [$from,$to] (site days).
 *
 * @param array  $q    From snt_arows_validate().
 * @param string $from Y-m-d, site time.
 * @param string $to   Y-m-d, site time.
 * @return array{rows:array,capped:bool}|WP_Error
 */
function snt_arows_sessions_fetch( array $q, $from, $to ) {
	list( $lo, $hi ) = snt_arows_utc_bounds( $from, $to );
	$read            = sn_analytics_sessions_read( $lo, $hi, $q['class'], 0, true );
	if ( null === $read ) {
		return new WP_Error( 'sn_analytics_read_failed', 'The Analytics Engine read failed; this is not an empty answer.', array( 'status' => 500 ) );
	}
	return array( 'rows' => snt_arows_sessions_group( $read['rows'], $q['dims'] ), 'capped' => $read['capped'] );
}
