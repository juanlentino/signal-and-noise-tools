<?php
/**
 * Signal & Noise Tools: how far the readers of each "Being read now" page
 * have scrolled, right now. Admin only.
 *
 * The beacon sends a scroll event the moment a reader passes each milestone
 * (25, 50, 75, 100), once each and every one it crosses, so the readers with
 * a 50 event are the readers who reached halfway. One grouped query per
 * realtime refresh, over the same 5-minute window, human rule and excluded
 * paths as the list (inc/analytics-live-pages.php), counts distinct readers
 * per path and milestone; no reader key leaves Analytics Engine. A milestone
 * whose event fell just outside the window is read from the deeper ones, so
 * the counts never dip. Null on a failed read (not measured), 0 on an answer
 * with no scroll rows (nobody past a quarter yet).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string AE SQL.
 */
function sn_analytics_live_scroll_sql() {
	$mins = max( 1, (int) SN_ANALYTICS_REALTIME_WINDOW_MIN );
	return implode( ' ', array(
		'SELECT blob2 AS path, double1 AS pct, count(DISTINCT index1) AS readers',
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE blob1 = 'sc' AND timestamp >= now() - INTERVAL '{$mins}' MINUTE AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY path, pct ORDER BY readers DESC LIMIT 200',
	) );
}

/**
 * Attach `scroll` to each listed page. PURE given the resolver.
 *
 * @param array|null $pages   The live list, { label, url, readers }.
 * @param array|null $rows    AE rows { path, pct, readers }; null = the read failed.
 * @param callable   $resolve The list's resolver, path => [label, url]|null.
 * @return array|null The list, each page with scroll { reached_25, reached_50, reached_75, reached_100, readers_with_scroll } or null.
 */
function sn_analytics_live_scroll_attach( $pages, $rows, callable $resolve ) {
	if ( ! is_array( $pages ) ) {
		return $pages;
	}
	$by = array(); // url => milestone => readers.
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$path = is_array( $row ) && isset( $row['path'], $row['pct'], $row['readers'] ) ? sn_analytics_live_pages_canon( $row['path'] ) : null;
		$hit  = null === $path ? null : $resolve( $path );
		$m    = is_array( $row ) ? (int) ( $row['pct'] ?? 0 ) : 0;
		if ( ! is_array( $hit ) || ! in_array( $m, array( 25, 50, 75, 100 ), true ) ) {
			continue;
		}
		$by[ (string) $hit[1] ][ $m ] = ( $by[ (string) $hit[1] ][ $m ] ?? 0 ) + max( 0, (int) $row['readers'] );
	}
	foreach ( $pages as $i => $page ) {
		if ( ! is_array( $rows ) ) {
			$pages[ $i ]['scroll'] = null;
			continue;
		}
		$cap  = max( 0, (int) ( $page['readers'] ?? 0 ) );
		$deep = 0;
		$s    = array();
		foreach ( array( 100, 75, 50, 25 ) as $m ) {
			$deep                    = min( $cap, max( $deep, (int) ( $by[ (string) ( $page['url'] ?? '' ) ][ $m ] ?? 0 ) ) );
			$s[ 'reached_' . $m ] = $deep;
		}
		$pages[ $i ]['scroll'] = array(
			'reached_25'          => $s['reached_25'],
			'reached_50'          => $s['reached_50'],
			'reached_75'          => $s['reached_75'],
			'reached_100'         => $s['reached_100'],
			'readers_with_scroll' => $s['reached_25'],
		);
	}
	return $pages;
}

/**
 * The read, for the realtime refresh: one query, and none when nobody is on
 * a listed page.
 *
 * @param array|null $pages The list just read.
 * @return array|null
 */
function sn_analytics_live_scroll_read( $pages ) {
	if ( ! is_array( $pages ) || ! $pages ) {
		return $pages;
	}
	$rows = sn_analytics_query( sn_analytics_live_scroll_sql() );
	return sn_analytics_live_scroll_attach( $pages, is_array( $rows ) ? $rows : null, 'sn_analytics_live_pages_resolve' );
}
