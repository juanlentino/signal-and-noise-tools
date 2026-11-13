<?php
/**
 * Beacon bot signals per UTC day, the store behind the "likely automated"
 * count for any analytics range (2026.10.1; its reader and schema are in
 * inc/analytics-likely-automated.php).
 *
 * The nightly readout (inc/analytics-bot-signals.php) is one 14-day figure;
 * get-analytics-summary asks for 7 to 365 days. This keeps the same human
 * rule and score per UTC day, stored nightly, so a range sums the days it
 * covers and says how many it could not. OBSERVE-ONLY like the readout:
 * nothing is subtracted from any count, and the basis is `inferred`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding day => {visitor_days, likely_automated}. */
const SNT_BOT_SIGNAL_BY_DAY_OPTION = 'sn_bot_signals_by_day';

/** Days of per-day reading kept: a year and a month, enough for range 365. */
const SNT_BOT_SIGNAL_BY_DAY_KEEP = 400;

/** Option holding the backfill cursor: the oldest UTC day (exclusive end of the next chunk) already read. */
const SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION = 'sn_bot_signals_by_day_backfill_v2'; // v2 (2026-11-13): walk the window again so every day gains the no-engagement count.

/** Days per backfill chunk: the nightly read's own span, which stays under the row cap. */
const SNT_BOT_SIGNAL_BY_DAY_CHUNK = 14;

/** Seconds the whole nightly bot-signals job may take, well inside WP-Cron's ~60 s. */
const SNT_BOT_SIGNAL_TICK_BUDGET = 35.0;

/** Seconds a backfill chunk needs in hand to start: two halves at a 6 s query timeout, and slack. */
const SNT_BOT_SIGNAL_BY_DAY_MIN_LEFT = 8.0;

/**
 * One row per UTC day and visitor-day that carried signals: the bits, page
 * views and the human flag. The day is a SELECT alias grouped by name, the
 * shape Analytics Engine accepts (never a function in GROUP BY). PURE.
 *
 * @param int    $days   Trailing window.
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split.
 * @param string $span   'Y-m-d|Y-m-d': a backfill chunk's first day and exclusive end, read instead of the trailing window.
 * @param bool   $engage Add the no-engagement columns (inc/analytics-strict-no-engagement.php) and read
 *                       presumed-human visitor-days with or without signals; only the store's own reads.
 * @return string
 */
function sn_bot_signals_by_day_sql( $days = SNT_BOT_SIGNAL_DAYS, $source = '', $range = '', $span = '', $engage = false ) {
	$days   = max( 1, min( 92, (int) $days ) );
	$source = '' !== (string) $source ? (string) $source : sn_analytics_source( sn_analytics_trailing_from( $days ) );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$net    = sn_analytics_network_human_sql();
	$cols   = array( "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day", 'index1 AS vid' );
	foreach ( SNT_BOT_SIGNAL_BITS as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	$cols[] = "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views";
	$cols[] = "max(if(blob7 != 'bot' AND ({$net}), 1, 0)) AS human";
	$present = 'bitAnd(toUInt32(double8), ' . SNT_BOT_SIGNAL_PRESENT . ') > 0';
	if ( $engage ) {
		$cols    = array_merge( $cols, sn_strict_no_engagement_cols() );
		$present = "({$present} OR (blob7 != 'bot' AND ({$net})))";
	}
	return 'SELECT ' . implode( ', ', $cols )
		. ' FROM ' . $source
		. ' WHERE ' . ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})$/', (string) $span, $m )
			? "timestamp >= toDateTime('{$m[1]} 00:00:00') AND timestamp < toDateTime('{$m[2]} 00:00:00')"
			: "timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)" )
		. "{$range} AND {$present}"
		. ' GROUP BY day, vid LIMIT ' . SNT_BOT_SIGNAL_LIMIT;
}

/**
 * Per day: human visitor-days under the page-view cap that carried signals,
 * how many scored at the threshold, and the visitor-days and page views each
 * strict removal takes out (inc/analytics-strict-human.php): automation
 * signals first, then no engagement recorded, so a visitor-day is removed
 * once. A read without the no-engagement columns stores no such count (the
 * day stays unmeasured for it). The readout's human rule. PURE.
 *
 * @param array $rows sn_bot_signals_by_day_sql() rows.
 * @return array<string,array> Day ascending.
 */
function sn_bot_signals_by_day( array $rows ) {
	$quiet  = function_exists( 'sn_strict_no_engagement_vids' ) ? sn_strict_no_engagement_vids( $rows ) : array();
	$engage = array() !== $rows && array_key_exists( 'pv_rows', (array) $rows[0] );
	$out    = array();
	foreach ( $rows as $r ) {
		$r   = (array) $r;
		$day = substr( (string) ( $r['day'] ?? '' ), 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || (int) ( $r['human'] ?? 0 ) <= 0 || (float) ( $r['views'] ?? 0 ) > SNT_ANALYTICS_VDAY_PV_CAP ) {
			continue;
		}
		$out[ $day ] = $out[ $day ] ?? array( 'visitor_days' => 0, 'likely_automated' => 0, 'strict_removed' => 0, 'strict_removed_views' => 0 )
			+ ( $engage ? array( 'no_engagement_removed' => 0, 'no_engagement_removed_views' => 0 ) : array() );
		if ( (int) ( $r['present'] ?? 1 ) > 0 ) { // the signal counts stay over the visitor-days that carried signals
			++$out[ $day ]['visitor_days'];
			$out[ $day ]['likely_automated'] += (int) ( sn_bot_signals_score( $r ) >= SNT_BOT_SIGNAL_THRESHOLD );
		}
		$views = (int) round( (float) ( $r['views'] ?? 0 ) );
		if ( sn_bot_signals_strict_removes( $r ) ) {
			++$out[ $day ]['strict_removed'];
			$out[ $day ]['strict_removed_views'] += $views;
		} elseif ( $engage && 1 === (int) ( $r['pv_rows'] ?? 0 ) && isset( $quiet[ strtolower( (string) ( $r['vid'] ?? '' ) ) ] ) ) {
			++$out[ $day ]['no_engagement_removed'];
			$out[ $day ]['no_engagement_removed_views'] += $views;
		}
	}
	ksort( $out );
	return $out;
}

/**
 * The stored days with a fresh read laid over them: every day from $from on
 * is REPLACED by the read (a day it no longer returns is dropped), older days
 * are kept, and the map is cut to SNT_BOT_SIGNAL_BY_DAY_KEEP days. PURE.
 *
 * @param array  $stored Stored day map.
 * @param array  $fresh  sn_bot_signals_by_day() of the read.
 * @param string $from   First day the read covered, YYYY-MM-DD.
 * @return array
 */
function sn_bot_signals_by_day_merge( array $stored, array $fresh, $from ) {
	$kept = array_filter( $stored, static fn( $day ) => (string) $day < (string) $from, ARRAY_FILTER_USE_KEY );
	$out  = $kept + $fresh;
	ksort( $out );
	return array_slice( $out, -SNT_BOT_SIGNAL_BY_DAY_KEEP, null, true );
}
