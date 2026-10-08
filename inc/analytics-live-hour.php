<?php
/**
 * Signal & Noise Tools: the last hour behind "Reading now".
 *
 * One grouped query on each realtime refresh: distinct human readers per
 * 5-minute slot over the last 60 minutes, the same counting rule as Reading
 * now. Always twelve slots, oldest first, ending at the current (partial)
 * slot; a slot the answer leaves out is a real 0. Drawn on /stats as bars.
 * Aggregate counts, cookieless as the beacon is; nothing new is collected.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_LIVE_HOUR_SLOT  = 300;
const SN_ANALYTICS_LIVE_HOUR_SLOTS = 12;

/**
 * Grouped by the SELECT alias: Analytics Engine refuses a function in
 * GROUP BY but accepts an alias over one.
 *
 * @return string AE SQL.
 */
function sn_analytics_live_hour_sql() {
	return implode( ' ', array(
		"SELECT toStartOfInterval(timestamp, INTERVAL '5' MINUTE) AS slot, count(DISTINCT index1) AS readers",
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( 0 ) ),
		"WHERE timestamp >= now() - INTERVAL '60' MINUTE AND " . sn_analytics_class_where( 'human' ) . sn_analytics_excluded_path_sql() . sn_analytics_overcap_where(),
		'GROUP BY slot ORDER BY slot',
	) );
}

/**
 * Rows to twelve slots. PURE.
 *
 * @param array $rows AE rows { slot: 'Y-m-d H:i:s' UTC, readers }.
 * @param int   $now  Unix time.
 * @return array<int,array{t:int,readers:int}>
 */
function sn_analytics_live_hour_from_rows( array $rows, $now ) {
	$cur = intdiv( (int) $now, SN_ANALYTICS_LIVE_HOUR_SLOT ) * SN_ANALYTICS_LIVE_HOUR_SLOT;
	$by  = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['slot'], $row['readers'] ) ) {
			continue;
		}
		$t = strtotime( (string) $row['slot'] . ' UTC' );
		if ( false !== $t ) {
			$by[ intdiv( $t, SN_ANALYTICS_LIVE_HOUR_SLOT ) * SN_ANALYTICS_LIVE_HOUR_SLOT ] = max( 0, (int) $row['readers'] );
		}
	}
	$out = array();
	for ( $i = SN_ANALYTICS_LIVE_HOUR_SLOTS - 1; $i >= 0; $i-- ) {
		$t     = $cur - $i * SN_ANALYTICS_LIVE_HOUR_SLOT;
		$out[] = array( 't' => $t, 'readers' => $by[ $t ] ?? 0 );
	}
	return $out;
}

/**
 * The read, for the realtime refresh. Null on a failed query.
 *
 * @return array|null
 */
function sn_analytics_live_hour_read() {
	$rows = sn_analytics_query( sn_analytics_live_hour_sql() );
	return is_array( $rows ) ? sn_analytics_live_hour_from_rows( $rows, time() ) : null;
}
