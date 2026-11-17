<?php
/**
 * Reads: proven reading, the headline in place of page loads (owner decision
 * 2026-11-14). A scraper that loads a page and kills it never produces one,
 * wherever it rents servers.
 *
 * A read is a page view with at least 10 seconds of visible time, or a scroll
 * to 50 percent or more, or a custom event (outbound, download, any goal
 * click: every custom event the beacon sends follows a click). One rule,
 * sn_analytics_is_read(), used by the daily store and the per-visit flag in
 * Sessions and Shares, so the two never disagree.
 *
 * Counted once per visitor-day, page and site day: the beacons of two views of
 * the same page that day add up, so two short views can make one read and two
 * long ones count one. The read rides on the visitor code, which rotates on
 * the site's day, so the store is keyed by site day, never by UTC day.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-vid.php'; // SN_AE_VID: the visitor-day hash, the first 16 characters of index1.

require_once __DIR__ . '/analytics-reads-store.php';
require_once __DIR__ . '/analytics-reads-parts.php';

/** Visible time that makes a read, ms: the engaged rate's own threshold. */
const SN_ANALYTICS_READ_TIME_MS = 10000;

/** Scroll depth that makes a read, percent. */
const SN_ANALYTICS_READ_SCROLL_PCT = 50;

/** Rows one read may return; a read at the cap is not stored. */
const SN_ANALYTICS_READS_LIMIT = 5000; // the bot-signal read's cap, well under Analytics Engine's 10,000-row strict limit

/** The definition, in one sentence, for every surface that carries the count. */
const SN_ANALYTICS_READS_DEFINITION = 'A read is a page view with at least 10 seconds of visible time, a scroll to 50 percent or more, or a custom event (an outbound click, a download, a goal click); counted once per visitor-day, page and site day.';

/**
 * Is this view a read? PURE.
 *
 * @param int|null $time_ms    Visible time, the sum of the view's time beacons.
 * @param int|null $scroll_pct Deepest scroll.
 * @param bool     $custom     A custom event on the view.
 * @return bool
 */
function sn_analytics_is_read( $time_ms, $scroll_pct, $custom ) {
	return (int) $time_ms >= SN_ANALYTICS_READ_TIME_MS || (int) $scroll_pct >= SN_ANALYTICS_READ_SCROLL_PCT || (bool) $custom;
}

/**
 * One row per site day, visitor-day, page and class that sent a page view or
 * an engagement beacon. Only the shapes already live on Analytics Engine: sumIf over a
 * Double, max(if(cond, 1, 0)) with integer branches, a zoned formatDateTime,
 * toDateTime bounds, GROUP BY aliases. PURE.
 *
 * @param string $source One half of a stitched read.
 * @param string $range  That half's side of the split.
 * @param string $lo     First instant, UTC 'Y-m-d H:i:s' (inclusive).
 * @param string $hi     Last instant, UTC 'Y-m-d H:i:s' (exclusive).
 * @param string $tz     The site's zone ('' = UTC days).
 * @param string $class  The class expression (sn_analytics_rollup_class_parts()).
 * @return string '' when a bound is malformed.
 */
function sn_analytics_reads_sql( $source, $range, $lo, $hi, $tz, $class ) {
	$ts = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
	if ( 1 !== preg_match( $ts, (string) $lo ) || 1 !== preg_match( $ts, (string) $hi ) ) {
		return '';
	}
	$tz    = ( '' !== $tz && preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ) ? (string) $tz : '';
	$day   = '' !== $tz ? "formatDateTime(timestamp, '%Y-%m-%d', '{$tz}')" : "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')";
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return implode( ' ', array(
		"SELECT {$day} AS day, " . SN_AE_VID . " AS vid, blob2 AS path, {$class} AS class,",
		"sumIf(double2, blob1 = 'tm') AS time_ms,",
		"max(if(blob1 = 'sc' AND double1 >= " . SN_ANALYTICS_READ_SCROLL_PCT . ', 1, 0)) AS scrolled,',
		"max(if(blob1 = 'ce', 1, 0)) AS custom,",
		"sumIf(_sample_interval, blob1 = 'pv') AS views",
		'FROM ' . (string) $source,
		"WHERE timestamp >= toDateTime('{$lo}') AND timestamp < toDateTime('{$hi}'){$range}",
		// The page views too: a read is a page VIEW, and a beacon-only visitor-day
		// (about 30 of 100 in the fortnight to 2026-11-14) sends time and scroll
		// with none. Bot rows are left out to keep the read under its cap.
		"AND blob1 IN ('pv','sc','tm','ce') AND blob7 != 'bot'",
		'GROUP BY day, vid, path, class',
		'LIMIT ' . SN_ANALYTICS_READS_LIMIT,
	) );
}

/**
 * Rows to reads per day, page and class, over the days the read covered: a
 * covered day with no read is stored as measured with none. PURE.
 *
 * @param array    $rows sn_analytics_reads_sql() rows.
 * @param string[] $days The site days the read covered completely.
 * @return array<string,array<string,array<string,int>>> day => path => class => reads.
 */
function sn_analytics_reads_fold( array $rows, array $days ) {
	$out = array_fill_keys( $days, array() );
	foreach ( $rows as $r ) {
		$r   = (array) $r;
		$day = (string) ( $r['day'] ?? '' );
		if ( ! array_key_exists( $day, $out ) || (int) ( $r['views'] ?? 0 ) < 1 || ! sn_analytics_is_read( $r['time_ms'] ?? 0, ! empty( $r['scrolled'] ) ? SN_ANALYTICS_READ_SCROLL_PCT : 0, ! empty( $r['custom'] ) ) ) {
			continue;
		}
		$path  = (string) ( $r['path'] ?? '' );
		$class = in_array( $r['class'] ?? '', array( 'human', 'suspect', 'bot' ), true ) ? (string) $r['class'] : 'human';
		$out[ $day ][ $path ][ $class ] = (int) ( $out[ $day ][ $path ][ $class ] ?? 0 ) + 1;
	}
	return $out;
}
