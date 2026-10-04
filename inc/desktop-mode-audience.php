<?php
/**
 * Signal & Noise Tools: who reads and where they come from, the rows SN
 * Traffic paints under its sparkline (SN Audience folded into it). Countries,
 * devices, named sources, Hacker News, search, feed subscribers. Every figure
 * is read from a table or option another view already fills; nothing here
 * collects.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows from a {value|label, views} list, with each row's share. PURE.
 *
 * @param array|null $list  Reader output; null when the read failed.
 * @param string     $key   'value' or 'label'.
 * @param int        $limit Rows kept.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_audience_rows( $list, $key, $limit ) {
	$list  = array_values( array_filter( (array) $list, static fn( $r ) => is_array( $r ) && (int) ( $r['views'] ?? 0 ) > 0 ) );
	$total = array_sum( array_map( static fn( $r ) => (int) $r['views'], $list ) );
	$rows  = array();
	foreach ( array_slice( $list, 0, (int) $limit ) as $r ) {
		$name   = (string) ( $r[ $key ] ?? '' );
		$rows[] = array( 'label' => '' !== $name ? $name : '(unknown)', 'value' => number_format_i18n( (int) $r['views'] ) . ' · ' . snt_desktop_pct( (int) $r['views'], $total ) );
	}
	return $rows;
}

/**
 * Hacker News rows: the newest stories that link here. PURE.
 *
 * @param array $items SN_HN_OPT items.
 * @param int   $limit Rows kept.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_audience_hn_rows( array $items, $limit ) {
	$rows = array();
	foreach ( array_slice( array_values( array_filter( $items, 'is_array' ) ), 0, (int) $limit ) as $s ) {
		$rank   = (int) ( $s['rank'] ?? 0 );
		$rows[] = array(
			'label' => (string) ( $s['title'] ?? $s['path'] ?? '' ),
			'value' => (int) ( $s['points'] ?? 0 ) . ' pts · ' . (int) ( $s['comments'] ?? 0 ) . ' comments' . ( $rank > 0 ? ' · #' . $rank . ' on the front page' : '' ),
		);
	}
	return $rows;
}

/**
 * Search rows from the two stored readings. PURE.
 *
 * @param array|null $google snt_gsc_window_totals().
 * @param array|null $bing   sn_bing_data().
 * @param bool       $google_failed Whether the last scheduled Search Console sync failed.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_audience_search_rows( $google, $bing, $google_failed = false ) {
	$rows = array();
	// A failed sync (either engine) keeps the last good totals with the failure beside
	// them; the row says so rather than passing an old reading off as current.
	$stale = is_array( $bing ) && '' !== (string) ( $bing['last_error'] ?? '' ) ? ' · last sync failed' : '';
	foreach ( array( 'Google' => $google, 'Bing' => is_array( $bing ) ? ( $bing['totals'] ?? null ) : null ) as $name => $t ) {
		if ( is_array( $t ) && isset( $t['clicks'] ) ) {
			$rows[] = array( 'label' => $name . ( ! empty( $t['days'] ) ? ' · ' . (int) $t['days'] . 'd' : '' ) . ( 'Bing' === $name ? $stale : ( $google_failed ? ' · last sync failed' : '' ) ), 'value' => number_format_i18n( (int) $t['clicks'] ) . ' clicks · ' . number_format_i18n( (int) ( $t['impressions'] ?? 0 ) ) . ' impressions' );
		}
	}
	return $rows;
}

/**
 * Feed subscriber rows: distinct readers per window, then requests. PURE.
 *
 * @param array|null $stats sn_rss_tracker_window_stats_multi( [1, 7, 30] ); null when the read failed.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_traffic_feed_rows( $stats ) {
	$rows = array();
	foreach ( array( 1 => '24h', 7 => '7d', 30 => '30d' ) as $d => $label ) {
		$w = is_array( $stats ) ? ( $stats['windows'][ $d ] ?? null ) : null;
		if ( is_array( $w ) ) {
			$rows[] = array( 'label' => $label, 'value' => number_format_i18n( (int) ( $w['uniques'] ?? 0 ) ) . ' unique · ' . number_format_i18n( (int) ( $w['total'] ?? 0 ) ) . ' requests' );
		}
	}
	return $rows;
}

/**
 * SN Traffic's groups below the sparkline, read live: who came and from
 * where. SN Audience's rows, cut to what one card holds.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array<int,array<string,mixed>>
 */
function snt_desktop_traffic_groups( array $win ) {
	$dim = static fn( $d ) => function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( $d, $win['from'], $win['to'], 'human', 500 ) : null; // every row: the shares divide by all of them, the tile shows the top few.
	$hn  = defined( 'SN_HN_OPT' ) ? (array) get_option( SN_HN_OPT, array() ) : array();
	$gsc = function_exists( 'snt_gsc_sync_last_status' ) ? snt_gsc_sync_last_status() : null;
	$rss = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) ) : null;
	if ( snt_desktop_db_failed() ) {
		$rss = null; // a missing feed table reads as zeros; say it could not be read.
	}
	return array(
		snt_desktop_group( 'Countries', snt_desktop_audience_rows( $dim( 'country' ), 'value', 3 ), 'No views in this window.' ),
		// Named sources (Hacker News, LinkedIn, direct): a name says more than a category.
		snt_desktop_group( 'Sources', snt_desktop_audience_rows( function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $win['from'], $win['to'], 'human', 500 ) : null, 'value', 4 ), 'No views in this window.' ),
		// A failed discovery read keeps the stories already known; the heading says
		// the list may be missing new ones.
		snt_desktop_group( 'Hacker News · latest story' . ( '' !== (string) ( $hn['error'] ?? '' ) ? ' · last check failed' : '' ), snt_desktop_audience_hn_rows( (array) ( $hn['items'] ?? array() ), 1 ), 'No story links here yet.' ),
		// Devices, search and the feed, one row each: the card is a glance, the
		// detail lives in Analytics.
		snt_desktop_group(
			'Devices, search, feed',
			array_values( array_filter( array(
				snt_desktop_traffic_fold( 'Devices', snt_desktop_audience_rows( $dim( 'device' ), 'value', 2 ), static fn( $r ) => $r['label'] . ' ' . preg_replace( '/^.* · /', '', $r['value'] ) ),
				snt_desktop_traffic_fold( 'Search', snt_desktop_audience_search_rows( function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null, function_exists( 'sn_bing_data' ) ? sn_bing_data() : null, is_array( $gsc ) && empty( $gsc['ok'] ) ), static fn( $r ) => preg_replace( '/ · \d+d/', '', $r['label'] ) . ' ' . preg_replace( '/ clicks · .*$/', ' clicks', $r['value'] ) ),
				// A feed table that cannot be read says so; it never reads as zero subscribers.
				null === $rss ? array( 'label' => 'Feed', 'value' => 'could not be read' ) : snt_desktop_traffic_fold( 'Feed, unique 24h · 7d · 30d', snt_desktop_traffic_feed_rows( $rss ), static fn( $r ) => preg_replace( '/ unique · .*$/', '', $r['value'] ) ),
			) ) ),
			'No views in this window.'
		),
	);
}

/**
 * Several rows as one: "Devices · desktop 70% · mobile 30%". Null when there
 * are none, so the row is left out rather than shown empty. PURE.
 *
 * @param string   $label Row label.
 * @param array    $rows  Rows to fold.
 * @param callable $part  fn( row ): string, one row's part of the value.
 * @return array{label:string,value:string}|null
 */
function snt_desktop_traffic_fold( $label, array $rows, callable $part ) {
	return array() === $rows ? null : array( 'label' => (string) $label, 'value' => implode( ' · ', array_map( $part, $rows ) ) );
}
