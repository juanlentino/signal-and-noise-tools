<?php
/**
 * Agent pairing, observe only (owner brief 2026-11-17). A person sometimes
 * asks an AI agent to read a page while they read it too: the agent's fetch
 * comes from a data center (a bot- or suspect-class page view) and the person
 * arrives with no referrer. A human session whose source is (direct) and that
 * overlaps in time with, or starts or ends within 60 seconds of, any bot- or
 * suspect-class page view is `paired`. Joined by TIME ONLY: never by visitor
 * code, place or network. Nothing is subtracted; the flag only says so.
 *
 * Real cases: a Niigata session 22:45:43 to 22:50:30 ET and an Ashburn read at
 * 22:50:44 (2026-11-16); a Yerevan session 09:06:52 to 09:17:02 ET and an
 * Ashburn read at 09:09:20 (2026-11-17).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_PAIRING_SLACK   = 60;    // seconds either side of a session.
const SN_ANALYTICS_PAIRING_ROW_CAP = 50000; // distinct seconds read; a capped read is not read.

/**
 * Whether one visit is paired. PURE.
 *
 * @param int    $start  Unix time of the visit's first event.
 * @param int    $end    Unix time of its last.
 * @param string $source The canonical source of its first page view ('(direct)' when none was referred).
 * @param int[]  $hits   Unix times of bot- and suspect-class page views, sorted ascending.
 * @return bool
 */
function sn_analytics_paired( $start, $end, $source, array $hits ) {
	if ( '(direct)' !== (string) $source || array() === $hits ) {
		return false;
	}
	$lo = (int) $start - SN_ANALYTICS_PAIRING_SLACK;
	$hi = max( (int) $start, (int) $end ) + SN_ANALYTICS_PAIRING_SLACK;
	// The first hit at or after $lo, by binary search; paired when it is no later than $hi.
	$a = 0;
	$b = count( $hits );
	while ( $a < $b ) {
		$m = intdiv( $a + $b, 2 );
		if ( (int) $hits[ $m ] < $lo ) {
			$a = $m + 1;
		} else {
			$b = $m;
		}
	}
	return $a < count( $hits ) && (int) $hits[ $a ] <= $hi;
}

/**
 * Session rows (inc/analytics-sessions-list.php) with `paired` on each. PURE.
 * Null hits (not read) mark every session null: not read, never "no".
 *
 * @param array      $sessions Session rows: start and end in ISO 8601, source.
 * @param int[]|null $hits     Unix times of bot- and suspect-class page views.
 * @return array
 */
function sn_analytics_pairing_flag( array $sessions, $hits ) {
	if ( is_array( $hits ) ) {
		$hits = array_map( 'intval', $hits );
		sort( $hits );
	}
	return array_map(
		static fn( $s ) => $s + array( 'paired' => is_array( $hits ) ? sn_analytics_paired( (int) strtotime( (string) $s['start'] ), (int) strtotime( (string) $s['end'] ), (string) $s['source'], $hits ) : null ),
		$sessions
	);
}

/**
 * Paired sessions per site day of their start. PURE.
 *
 * @param array $sessions Flagged session rows.
 * @return array<int,array{day:string,sessions:int,direct:int,paired:int}> Oldest first.
 */
function sn_analytics_pairing_by_day( array $sessions ) {
	$by = array();
	foreach ( $sessions as $s ) {
		$d          = substr( (string) $s['start'], 0, 10 );
		$by[ $d ]   = $by[ $d ] ?? array( 'day' => $d, 'sessions' => 0, 'direct' => 0, 'paired' => 0 );
		$by[ $d ]['sessions'] += 1;
		$by[ $d ]['direct']   += '(direct)' === $s['source'] ? 1 : 0;
		$by[ $d ]['paired']   += true === ( $s['paired'] ?? null ) ? 1 : 0;
	}
	ksort( $by );
	return array_values( $by );
}

/**
 * The cheapest read: one row per second holding a bot- or suspect-class page view.
 *
 * @param string $lo     UTC instant, inclusive (Y-m-d H:i:s).
 * @param string $hi     UTC instant, exclusive.
 * @param string $source Dataset.
 * @param string $range  The stitch's range clause.
 * @return string AE SQL.
 */
function sn_analytics_pairing_sql( $lo, $hi, $source, $range = '' ) {
	$ts = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
	if ( ! preg_match( $ts, (string) $lo ) || ! preg_match( $ts, (string) $hi ) ) {
		return '';
	}
	return implode( ' ', array(
		'SELECT toUnixTimestamp(timestamp) AS ts, count() AS n',
		'FROM ' . $source,
		"WHERE timestamp >= toDateTime('{$lo}') AND timestamp < toDateTime('{$hi}'){$range}",
		"AND blob1 = 'pv' AND (" . sn_analytics_class_where( 'bot' ) . ' OR ' . sn_analytics_class_where( 'suspect' ) . ')',
		'GROUP BY ts LIMIT ' . SN_ANALYTICS_PAIRING_ROW_CAP,
	) );
}

/**
 * The times of bot- and suspect-class page views in [$t_lo, $t_hi). Null on a
 * failed or capped read.
 *
 * @param int $t_lo Unix time, inclusive.
 * @param int $t_hi Unix time, exclusive.
 * @return int[]|null
 */
function sn_analytics_pairing_hits( $t_lo, $t_hi ) {
	$lo   = gmdate( 'Y-m-d H:i:s', (int) $t_lo );
	$hi   = gmdate( 'Y-m-d H:i:s', (int) $t_hi );
	$sets = function_exists( 'sn_analytics_stitched_rows' ) ? sn_analytics_stitched_rows( static fn( $s, $r ) => sn_analytics_pairing_sql( $lo, $hi, $s, $r ), substr( $lo, 0, 10 ), $hi ) : null;
	if ( null === $sets || array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= SN_ANALYTICS_PAIRING_ROW_CAP ) ) {
		return null;
	}
	return array_map( static fn( $r ) => (int) ( $r['ts'] ?? 0 ), array_merge( array(), ...$sets ) );
}

/**
 * Human sessions flagged against the hits around them: one read spanning the
 * sessions, 60 seconds either side. Other classes come back unchanged.
 *
 * @param array  $sessions Session rows.
 * @param string $class    Traffic class.
 * @return array
 */
function sn_analytics_pairing_on_sessions( array $sessions, $class ) {
	if ( 'human' !== $class ) {
		return array_map( static fn( $s ) => $s + array( 'paired' => null ), $sessions );
	}
	if ( array() === $sessions ) {
		return array();
	}
	$lo = min( array_map( static fn( $s ) => (int) strtotime( (string) $s['start'] ), $sessions ) ) - SN_ANALYTICS_PAIRING_SLACK;
	$hi = max( array_map( static fn( $s ) => (int) strtotime( (string) $s['end'] ), $sessions ) ) + SN_ANALYTICS_PAIRING_SLACK + 1;
	return sn_analytics_pairing_flag( $sessions, sn_analytics_pairing_hits( $lo, $hi ) );
}
