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
 * @return array<int,array{label:string,value:string,share:float}> share: percent of ALL rows, for a share bar.
 */
function snt_desktop_audience_rows( $list, $key, $limit ) {
	$list  = array_values( array_filter( (array) $list, static fn( $r ) => is_array( $r ) && (int) ( $r['views'] ?? 0 ) > 0 ) );
	$total = array_sum( array_map( static fn( $r ) => (int) $r['views'], $list ) );
	$rows  = array();
	foreach ( array_slice( $list, 0, (int) $limit ) as $r ) {
		$name   = (string) ( $r[ $key ] ?? '' );
		$rows[] = array( 'label' => '' !== $name ? $name : '(unknown)', 'value' => number_format_i18n( (int) $r['views'] ) . ' · ' . snt_desktop_pct( (int) $r['views'], $total ), 'share' => $total > 0 ? round( 100 * (int) $r['views'] / $total, 1 ) : 0.0 );
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
		$points = (int) ( $s['points'] ?? 0 );
		$notes  = (int) ( $s['comments'] ?? 0 );
		$rows[] = array(
			'label' => (string) ( $s['title'] ?? $s['path'] ?? '' ),
			'value' => $points . ( 1 === $points ? ' point · ' : ' points · ' ) . $notes . ( 1 === $notes ? ' comment' : ' comments' ) . ( $rank > 0 ? ' · #' . $rank . ' on the front page' : '' ),
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
	// Countries and Sources are short and split a whole: each draws a share
	// bar over its rows (`share`), and the two sit side by side (`pair`: this
	// group and the next share a row). Both keys are hints a client may ignore.
	$out = array(
		array( 'share' => true, 'pair' => true ) + snt_desktop_group( 'Countries', snt_desktop_audience_rows( $dim( 'country' ), 'value', 3 ), 'No views in this window.' ),
		// Named sources (Hacker News, LinkedIn, direct): a name says more than a category.
		array( 'share' => true ) + snt_desktop_group( 'Sources', snt_desktop_audience_rows( function_exists( 'sn_analytics_top_sources' ) ? sn_analytics_top_sources( $win['from'], $win['to'], 'human', 500 ) : null, 'value', 4 ), 'No views in this window.' ),
	);
	// Campaigns, only when a tagged link was followed, as SN Audience showed
	// them. '(none)' is the rollup's bucket for a tagged link that named no
	// campaign: not a campaign.
	$named = array_filter( (array) ( function_exists( 'sn_analytics_top_utm_campaigns' ) ? sn_analytics_top_utm_campaigns( $win['from'], $win['to'], 'human', 25 ) : null ), static fn( $r ) => is_array( $r ) && '(none)' !== (string) ( $r['value'] ?? '' ) );
	// Two at most, the rest counted (a growing list never pushes the link out).
	$camp  = snt_desktop_audience_rows( $named, 'value', 2 );
	if ( count( $named ) > 2 ) {
		$camp[] = array( 'label' => '+' . ( count( $named ) - 2 ) . ' more in Analytics', 'value' => '' );
	}
	if ( $camp ) {
		$out[] = snt_desktop_group( 'Campaigns', $camp, '' );
	}
	$devices = snt_desktop_audience_rows( $dim( 'device' ), 'value', 2 );
	$google  = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
	$bing    = function_exists( 'sn_bing_data' ) ? sn_bing_data() : null;
	// Google's end date lives on the stored reading, not on the totals (whose shape the insights twin carries).
	$gwin    = function_exists( 'snt_gsc_data' ) ? ( snt_gsc_data()['window'] ?? null ) : null;
	array_push( $out,
		// A failed discovery read keeps the stories already known; the heading says
		// the list may be missing new ones.
		snt_desktop_group( 'Hacker News · latest story' . ( '' !== (string) ( $hn['error'] ?? '' ) ? ' · last check failed' : '' ), snt_desktop_audience_hn_rows( (array) ( $hn['items'] ?? array() ), 1 ), 'No story links here yet.' ),
		// Devices, search and the feed, one row each: the card is a glance, the
		// detail lives in Analytics.
		snt_desktop_group(
			'Devices, search, feed',
			array_values( array_filter( array(
				// `split`: the shares again as numbers, for the bar under the row.
				snt_desktop_traffic_split( snt_desktop_traffic_fold( 'Devices', $devices, static fn( $r ) => $r['label'] . ' ' . preg_replace( '/^.* · /', '', $r['value'] ) ), $devices ),
				// Clicks and impressions per engine: "Google 5 clicks · 478 impr".
				// The label carries the engines' own window ("Search · 28 days to Oct 7"):
				// neither is the card's 14 days.
				snt_desktop_traffic_fold( snt_desktop_traffic_search_label( $google, $bing, $gwin ), snt_desktop_audience_search_rows( $google, $bing, is_array( $gsc ) && empty( $gsc['ok'] ) ), static fn( $r ) => preg_replace( '/ · \d+d/', '', $r['label'] ) . ' ' . str_replace( ' impressions', ' impr', $r['value'] ) ),
				// A feed table that cannot be read says so; it never reads as zero subscribers.
				null === $rss ? array( 'label' => 'Feed', 'value' => 'could not be read' ) : snt_desktop_traffic_fold( snt_desktop_traffic_feed_label(), snt_desktop_traffic_feed_rows( $rss ), static fn( $r ) => preg_replace( '/ unique · .*$/', '', $r['value'] ) ),
			) ) ),
			'No views in this window.'
		)
	);
	return $out;
}

/**
 * The Feed row's label. 2026-10-25: since the day 304 replies began to count
 * (2026-10-24, sn_rss_tracker_counts_304_since()) the figures step up, so
 * while the 30-day window still reaches back before that day the label says
 * so, as the RSS screens do; after that the change is in every window and
 * the note retires itself.
 *
 * @param int|null $now Unix time (test seam).
 * @return string
 */
function snt_desktop_traffic_feed_label( $now = null ) {
	$label = 'Feed, unique 24h · 7d · 30d';
	$day   = function_exists( 'sn_rss_tracker_counts_304_since' ) ? (string) sn_rss_tracker_counts_304_since() : '';
	$since = '' === $day ? false : strtotime( $day . ' 00:00:00 UTC' );
	if ( false === $since || ( null === $now ? time() : (int) $now ) - $since >= 30 * DAY_IN_SECONDS ) {
		return $label;
	}
	return $label . ' (304s counted since ' . gmdate( 'M j', $since ) . ')';
}

/**
 * The Search row's label with the engines' windows: "Search · 28 days to Oct 7",
 * or each engine's own when they differ. Only an engine with totals counts; one
 * with no stored end date says its length alone. PURE.
 *
 * @param array|null $google     snt_gsc_window_totals() ({clicks, days}).
 * @param array|null $bing       sn_bing_data() ({totals, window:{days, end}}).
 * @param array|null $google_win snt_gsc_data()['window'] ({start, end}); null when not stored.
 * @return string
 */
function snt_desktop_traffic_search_label( $google, $bing, $google_win = null ) {
	$wins = array();
	foreach ( array( 'Google' => is_array( $google ) && isset( $google['clicks'] ) ? array( 'days' => $google['days'] ?? 0, 'end' => is_array( $google_win ) ? ( $google_win['end'] ?? '' ) : '' ) : null, 'Bing' => is_array( $bing ) && is_array( $bing['totals'] ?? null ) && isset( $bing['totals']['clicks'] ) ? (array) ( $bing['window'] ?? array() ) + array( 'days' => $bing['totals']['days'] ?? 0 ) : null ) as $name => $w ) {
		$days = is_array( $w ) ? (int) ( $w['days'] ?? 0 ) : 0;
		if ( $days > 0 ) {
			$end           = (string) ( $w['end'] ?? '' );
			$t             = '' !== $end ? strtotime( $end . ' 00:00:00 UTC' ) : false;
			$wins[ $name ] = array( $days . ' days', false !== $t ? 'to ' . gmdate( 'M j', $t ) : '' );
		}
	}
	if ( ! $wins ) {
		return 'Search';
	}
	$lens = array_unique( array_column( $wins, 0 ) );
	$ends = array_unique( array_column( $wins, 1 ) );
	if ( 1 === count( $lens ) && 1 === count( $ends ) ) {
		return trim( 'Search · ' . reset( $lens ) . ' ' . reset( $ends ) );
	}
	// The engines end on different days (the usual case: each reports a day or
	// three back): say the shared length once, then each end.
	if ( 1 === count( $lens ) ) {
		return 'Search · ' . reset( $lens ) . ', ' . implode( ', ', array_map( static fn( $n, $w ) => trim( $n . ' ' . $w[1] ), array_keys( $wins ), $wins ) );
	}
	return 'Search · ' . implode( ', ', array_map( static fn( $n, $w ) => trim( $n . ' ' . $w[0] . ' ' . $w[1] ), array_keys( $wins ), $wins ) );
}

/**
 * How many distinct countries and named sources had views. PURE.
 *
 * @param array|null $countries sn_analytics_top_dimension( 'country' ) rows; null when the read failed.
 * @param array|null $sources   sn_analytics_top_sources() rows; null when the read failed.
 * @return array{countries:int,sources:int}|null Null when either read failed.
 */
function snt_desktop_traffic_reach_counts( $countries, $sources ) {
	if ( ! is_array( $countries ) || ! is_array( $sources ) ) {
		return null;
	}
	// '(unknown)' is the dims rollup's bucket for a missing value, not a country or a source.
	$n = static fn( $list ) => count( array_filter( $list, static fn( $r ) => is_array( $r ) && (int) ( $r['views'] ?? 0 ) > 0 && ! in_array( (string) ( $r['value'] ?? '' ), array( '', '(unknown)' ), true ) ) );
	// The reads are top-500 lists: a full list is a floor, painted "500+", not a count.
	return array(
		'countries'        => $n( $countries ),
		'sources'          => $n( $sources ),
		'countries_capped' => count( $countries ) >= 500,
		'sources_capped'   => count( $sources ) >= 500,
	);
}

/**
 * SN Traffic's reach row: distinct countries and named sources with views in
 * the window, and the same two counts over the prior window of equal length
 * (the two rollup reads asked again for the earlier dates; no new tracking).
 * Null when the window cannot be read or had no views: the row is left out,
 * never painted as 0. `prior` is null when that window cannot be read.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array{countries:int,sources:int,prior:array{countries:int,sources:int}|null}|null
 */
function snt_desktop_traffic_reach( array $win ) {
	if ( ! function_exists( 'sn_analytics_top_dimension' ) || ! function_exists( 'sn_analytics_top_sources' ) ) {
		return null;
	}
	$read = static function ( $from, $to ) {
		$c = sn_analytics_top_dimension( 'country', $from, $to, 'human', 500 );
		$f = snt_desktop_db_failed();
		$s = sn_analytics_top_sources( $from, $to, 'human', 500 );
		return $f || snt_desktop_db_failed() ? null : snt_desktop_traffic_reach_counts( $c, $s );
	};
	$now = $read( $win['from'], $win['to'] );
	if ( null === $now || 0 === $now['countries'] + $now['sources'] ) {
		return null;
	}
	$days       = max( 1, (int) $win['days'] );
	$prior_to   = gmdate( 'Y-m-d', (int) strtotime( $win['from'] . ' -1 day' ) );
	$prior_from = gmdate( 'Y-m-d', (int) strtotime( $win['from'] . ' -' . $days . ' days' ) );
	return $now + array( 'prior' => $read( $prior_from, $prior_to ) );
}

/**
 * A folded row with its parts' shares as numbers (`split`), so the card can
 * draw a bar under it. Null stays null. PURE.
 *
 * @param array|null $row  snt_desktop_traffic_fold() output.
 * @param array      $rows The rows it folded (snt_desktop_audience_rows()).
 * @return array{label:string,value:string,split:array<int,float>}|null
 */
function snt_desktop_traffic_split( $row, array $rows ) {
	return null === $row ? null : $row + array( 'split' => array_map( static fn( $r ) => (float) ( $r['share'] ?? 0 ), $rows ) );
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
