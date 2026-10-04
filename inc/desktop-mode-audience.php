<?php
/**
 * Signal & Noise Tools: SN Audience, who reads and where they come from.
 * Countries, devices, source categories, campaigns, Hacker News, search.
 * Every figure is read from a table or option another view already fills;
 * nothing here collects.
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
 * The groups, read live.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array<int,array<string,mixed>>
 */
function snt_desktop_audience_groups( array $win ) {
	$dim = static fn( $d ) => function_exists( 'sn_analytics_top_dimension' ) ? sn_analytics_top_dimension( $d, $win['from'], $win['to'], 'human', 500 ) : null; // every row: the shares divide by all of them, the tile shows the top few.
	$hn  = defined( 'SN_HN_OPT' ) ? (array) get_option( SN_HN_OPT, array() ) : array();
	$out = array(
		snt_desktop_group( 'Countries', snt_desktop_audience_rows( $dim( 'country' ), 'value', 5 ), 'No views in this window.' ),
		snt_desktop_group( 'Devices', snt_desktop_audience_rows( $dim( 'device' ), 'value', 3 ), 'No views in this window.' ),
		snt_desktop_group( 'Sources', snt_desktop_audience_rows( function_exists( 'sn_analytics_referrer_categories' ) ? sn_analytics_referrer_categories( $win['from'], $win['to'], 'human' ) : null, 'label', 5 ), 'No views in this window.' ),
	);
	$camp = snt_desktop_audience_rows( function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $win['from'], $win['to'], 'human', 3 ) : null, 'value', 3 );
	if ( $camp ) {
		$out[] = snt_desktop_group( 'Campaigns', $camp, '' ); // only when a tagged link was followed.
	}
	// A failed discovery read keeps the stories already known; the heading says
	// the list may be missing new ones.
	$out[] = snt_desktop_group( 'Hacker News · latest stories' . ( '' !== (string) ( $hn['error'] ?? '' ) ? ' · last check failed' : '' ), snt_desktop_audience_hn_rows( (array) ( $hn['items'] ?? array() ), 3 ), 'No story links here yet.' );
	$gsc        = function_exists( 'snt_gsc_sync_last_status' ) ? snt_gsc_sync_last_status() : null;
	$gsc_failed = is_array( $gsc ) && empty( $gsc['ok'] );
	$out[]      = snt_desktop_group( 'Search', snt_desktop_audience_search_rows( function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null, function_exists( 'sn_bing_data' ) ? sn_bing_data() : null, $gsc_failed ), 'No search reading stored yet.' );
	return $out;
}
