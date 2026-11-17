<?php
/**
 * Returning to an open tab (owner brief 2026-11-17). ADMIN ONLY.
 *
 * The visitor code rotates at midnight in New York, so someone who comes back
 * to a tab opened yesterday sends today's "still here" timing and scroll rows
 * under a new code with no page view today. They are a person reading, but not
 * a new reader and not a page view: the admin live answer counts them apart
 * (`open_tab`), takes them out of `now`, and marks their line in the readers
 * list. A visitor-day with any page view today is a reader as before.
 * `today` counts page views, so it never held them. The hour bars, stored
 * slots, surge and Being read now still count every engaged visitor.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-vid.php'; // SN_AE_VID: the visitor-day hash, the first 16 characters of index1.

const SN_ANALYTICS_LIVE_OPEN_TAB_CAP = 1000;

/**
 * Human visitor-days engaged in the live window with no page view since the
 * visitor-day began: one grouped read, no rows beyond the qualifying codes.
 *
 * @return string AE SQL.
 */
function sn_analytics_live_open_tab_sql() {
	$mins = max( 1, (int) SN_ANALYTICS_REALTIME_WINDOW_MIN );
	$tz   = defined( 'SN_ANALYTICS_SPLIT_TZ' ) && preg_match( '#^[A-Za-z0-9_/+-]+$#', SN_ANALYTICS_SPLIT_TZ ) ? SN_ANALYTICS_SPLIT_TZ : 'UTC';
	return implode( ' ', array(
		'SELECT ' . SN_AE_VID . " AS vid, sum(if(blob1 = 'pv', 1, 0)) AS views, max(if(" . SN_ANALYTICS_LIVE_ENGAGED_SQL . " AND timestamp >= now() - INTERVAL '{$mins}' MINUTE, 1, 0)) AS live",
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= toStartOfInterval(now(), INTERVAL '1' DAY, '{$tz}') AND " . sn_analytics_class_where( 'human' ),
		'GROUP BY vid HAVING views = 0 AND live = 1',
		'LIMIT ' . SN_ANALYTICS_LIVE_OPEN_TAB_CAP,
	) );
}

/**
 * The read's rows to the set of open-tab visitor codes. PURE: a code counts
 * only with no page view today and an engagement signal in the window,
 * whatever the read's HAVING did.
 *
 * @param array $rows { vid, views, live }.
 * @return array<string,true>
 */
function sn_analytics_live_open_tab_vids( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		if ( is_array( $r ) && '' !== (string) ( $r['vid'] ?? '' ) && 0 === (int) ( $r['views'] ?? 1 ) && 1 <= (int) ( $r['live'] ?? 0 ) ) {
			$out[ (string) $r['vid'] ] = true;
		}
	}
	return $out;
}

/**
 * The open-tab codes, for the realtime refresh. Null on a failed or capped
 * read: not read, so nobody is moved out of `now`.
 *
 * @return array<string,true>|null
 */
function sn_analytics_live_open_tab_read() {
	$rows = sn_analytics_query( sn_analytics_live_open_tab_sql() );
	return is_array( $rows ) && count( $rows ) < SN_ANALYTICS_LIVE_OPEN_TAB_CAP ? sn_analytics_live_open_tab_vids( $rows ) : null;
}

/**
 * The admin `now` and class counts with the open tabs taken out. PURE.
 *
 * @param array $out      The admin live payload.
 * @param mixed $open_tab The cached count (int), or null when not read.
 * @return array
 */
function sn_analytics_live_open_tab_apply( array $out, $open_tab ) {
	$out['open_tab'] = is_int( $open_tab ) ? $open_tab : null;
	if ( is_int( $open_tab ) && is_int( $out['now'] ?? null ) ) {
		$out['now'] = max( 0, $out['now'] - $open_tab );
		if ( is_array( $out['classes'] ?? null ) && is_int( $out['classes']['human'] ?? null ) ) {
			$out['classes']['human'] = $out['now'];
		}
	}
	return $out;
}
