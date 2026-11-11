<?php
/**
 * The flagged-humans readout: the visitor-days a "strict human" figure would
 * take out, listed so the owner can decide whether to build one (2026-11-11).
 *
 * Every visitor-day the bot-signals readout counts as human (its human flag,
 * not over the cap) whose score reaches SNT_BOT_SIGNAL_THRESHOLD, and apart
 * the near misses one point below it. The readout's own read
 * (sn_bot_signals_sql()) is reused unchanged; a second read over the same
 * visitor-days (same window, split bound and presence bit) names the first
 * page view's source, place, device, browser, OS and network, and sums the
 * visible time. A third counts the window's visitor-days, so a result that
 * comes back short of them says truncated whatever its row count.
 *
 * Read on demand, never stored, never subtracted from anything. No visitor
 * code, IP address or user agent leaves this file.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The readout's WHERE for one half: window, split bound, presence bit. PURE.
 *
 * @param int    $days  Trailing window.
 * @param string $range One side of the split, or ''.
 * @return string
 */
function sn_bot_signals_flagged_where( $days, $range ) {
	$days  = max( 1, min( 92, (int) $days ) );
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return "timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY){$range} AND bitAnd(toUInt32(double8), " . SNT_BOT_SIGNAL_PRESENT . ') > 0';
}

/**
 * One row per visitor-day, site-local day and place: the first page view's
 * instant (4102444800, a time no row carries, when the group has none) and
 * the visible time. PURE.
 *
 * @param int    $days   Trailing window.
 * @param string $source One half's dataset.
 * @param string $range  That half's side of the split.
 * @param string $tz     Site zone ('' = UTC days).
 * @return string
 */
function sn_bot_signals_flagged_details_sql( $days, $source, $range, $tz ) {
	list( $day_col ) = sn_analytics_rollup_window_exprs( 1, (string) $tz );
	return 'SELECT index1 AS vid, ' . $day_col . ' AS day, ' . sn_analytics_referrer_value_sql( 'blob3' ) . ' AS ref, blob4 AS country, blob10 AS region, blob11 AS city, blob5 AS device, blob8 AS browser, blob9 AS os, blob12 AS network, '
		. "min(if(blob1 = 'pv', toUnixTimestamp(timestamp), 4102444800)) AS first_pv, sum(if(blob1 = 'tm', double2 * _sample_interval, 0)) AS time_ms"
		. ' FROM ' . (string) $source . ' WHERE ' . sn_bot_signals_flagged_where( $days, $range )
		. ' GROUP BY vid, day, ref, country, region, city, device, browser, os, network LIMIT ' . SNT_BOT_SIGNAL_LIMIT;
}

/**
 * The window's visitor-days, one row: what a whole read returns. PURE.
 *
 * @param int    $days   Trailing window.
 * @param string $source One half's dataset.
 * @param string $range  That half's side of the split.
 * @return string
 */
function sn_bot_signals_flagged_count_sql( $days, $source, $range ) {
	return 'SELECT count(DISTINCT index1) AS n FROM ' . (string) $source . ' WHERE ' . sn_bot_signals_flagged_where( $days, $range );
}

/**
 * One visitor-day as the readout lists it. PURE.
 *
 * @param array    $r     The readout's row.
 * @param array    $g     Its first page view's details group ([] when none came back).
 * @param int|null $time  Visible time summed over its groups, ms; null when none came back.
 * @param int      $score sn_bot_signals_score().
 * @return array
 */
function sn_bot_signals_flagged_row( array $r, array $g, $time, $score ) {
	$real = array( '/' => true ); // no path is listed, so no path allowlist is needed
	$c    = static fn( $dim, $key ) => snt_arows_clean( $dim, (string) ( $g[ $key ] ?? '' ), $real );
	return array(
		'day'     => '' === (string) ( $g['day'] ?? '' ) ? '(unknown)' : $c( 'day', 'day' ),
		'source'  => sn_analytics_session_source( (string) ( $g['ref'] ?? '' ) ),
		'country' => $c( 'country', 'country' ),
		'region'  => $c( 'region', 'region' ),
		'city'    => $c( 'city', 'city' ),
		'device'  => $c( 'device', 'device' ),
		// Cloudflare's and the worker's names, under the place names' rule: printable, at most 128.
		'browser' => $c( 'city', 'browser' ),
		'os'      => $c( 'city', 'os' ),
		'network' => $c( 'city', 'network' ),
		'views'   => (int) round( (float) ( $r['views'] ?? 0 ) ),
		'time_ms' => null === $time ? null : (int) $time,
		'signals' => array_values( array_filter( SNT_BOT_SIGNAL_BITS, static fn( $name ) => (float) ( $r[ $name ] ?? 0 ) > 0 ) ),
		'score'   => (int) $score,
		'relay'   => (int) ( $r['relay'] ?? 0 ) > 0,
		'intent'  => (int) ( $r['intent'] ?? 0 ) > 0,
	);
}

/**
 * The two lists from the reads, newest day first. PURE.
 *
 * @param array $main    sn_bot_signals_sql() rows.
 * @param array $details sn_bot_signals_flagged_details_sql() rows.
 * @return array{flagged:array,near_miss:array,read_visitor_days:int,human_visitor_days:int}
 */
function sn_bot_signals_flagged_derive( array $main, array $details ) {
	$first = array();
	$time  = array();
	foreach ( $details as $g ) {
		$g            = (array) $g;
		$vid          = strtolower( (string) ( $g['vid'] ?? '' ) );
		$time[ $vid ] = ( $time[ $vid ] ?? 0 ) + (int) round( (float) ( $g['time_ms'] ?? 0 ) );
		if ( ! isset( $first[ $vid ] ) || (float) ( $g['first_pv'] ?? 0 ) < (float) ( $first[ $vid ]['first_pv'] ?? 0 ) ) {
			$first[ $vid ] = $g;
		}
	}
	$out   = array( 'flagged' => array(), 'near_miss' => array() );
	$human = 0;
	foreach ( $main as $r ) {
		$r = (array) $r;
		if ( ! sn_bot_signals_counted_human( $r ) ) {
			continue;
		}
		++$human;
		$score = sn_bot_signals_score( $r );
		$list  = $score >= SNT_BOT_SIGNAL_THRESHOLD ? 'flagged' : ( SNT_BOT_SIGNAL_THRESHOLD - 1 === $score ? 'near_miss' : '' );
		if ( '' !== $list ) {
			$vid            = strtolower( (string) ( $r['vid'] ?? '' ) );
			$out[ $list ][] = sn_bot_signals_flagged_row( $r, $first[ $vid ] ?? array(), $time[ $vid ] ?? null, $score );
		}
	}
	foreach ( $out as $k => $rows ) {
		usort( $rows, static fn( $a, $b ) => strcmp( $b['day'], $a['day'] ) ?: $b['score'] <=> $a['score'] );
		$out[ $k ] = $rows;
	}
	return $out + array( 'read_visitor_days' => count( $main ), 'human_visitor_days' => $human );
}
