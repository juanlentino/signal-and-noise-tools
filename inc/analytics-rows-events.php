<?php
/**
 * Signal & Noise Tools: the analytics rows read beyond the stored rollups
 * (owner brief "MCP full analytics access", 2026-11-01).
 *
 * The stored rollups keep one dimension per row and day, forever. A query
 * they cannot answer (two or more dimensions other than day, the hour, or a
 * filter the rollups do not carry) reads Analytics Engine instead, which keeps
 * every field together for about 90 days. snt_arows_plan() picks the source
 * and refuses a window Analytics Engine no longer holds, naming the first day
 * it does; the answer always says which source it came from.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AROWS_EVENTS_DAYS = 90;   // what Analytics Engine keeps, about.
const SNT_AROWS_EVENTS_CAP  = 5000; // rows per query half, under AE's 10,000-row strict cap.
const SNT_AROWS_EVENTS_COLS = array( 'path' => 'blob2', 'link_code' => 'blob2', 'country' => 'blob4', 'device' => 'blob5', 'region' => 'blob10', 'city' => 'blob11' ); // referrer: sn_analytics_referrer_value_sql(), as the dims rollup.

/**
 * Whether the stored rollups hold this query as one row per value. PURE.
 *
 * @param array $q From snt_arows_validate().
 * @return bool
 */
function snt_arows_stored_holds( array $q ) {
	$page  = array_intersect( $q['dims'], array( 'path', 'link_code' ) );
	$other = $q['other'];
	if ( ! array_diff( $q['dims'], SNT_AROWS_STORED_PAGE ) && count( $page ) <= 1 && null === $q['referrer'] ) {
		return true;
	}
	if ( array() === $other ) {
		return null === $q['path'] && null !== $q['referrer']; // day, filtered by referrer: the dims table.
	}
	return 1 === count( $other ) && in_array( $other[0], SNT_AROWS_STORED_DIMS, true ) && null === $q['path'] && ( null === $q['referrer'] || 'referrer' === $other[0] );
}

/**
 * The source for a query over [$from, ...], or why it cannot be answered. PURE.
 *
 * @param array  $q     From snt_arows_validate().
 * @param string $from  First day asked for (site time).
 * @param string $today Today (site time).
 * @return string|WP_Error 'stored', 'events' or 'sessions'.
 */
function snt_arows_plan( array $q, $from, $today ) {
	$stored = empty( $q['sessions'] ) && snt_arows_stored_holds( $q );
	if ( 'stored' === $q['source'] && ! $stored ) {
		return new WP_Error( 'ability_invalid_input', 'this combination is not stored as one row per day: ask with source "events" (Analytics Engine, about 90 days).', array( 'status' => 400 ) );
	}
	if ( 'stored' === $q['source'] || ( 'auto' === $q['source'] && $stored ) ) {
		return 'stored';
	}
	$floor = ( new DateTimeImmutable( $today ) )->modify( '-' . ( SNT_AROWS_EVENTS_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
	if ( (string) $from < $floor ) {
		return new WP_Error( 'ability_invalid_input', sprintf( 'this combination is read from Analytics Engine, which keeps about %d days: the window must start on %s or later.', SNT_AROWS_EVENTS_DAYS, $floor ), array( 'status' => 400 ) );
	}
	if ( ! empty( $q['sessions'] ) ) {
		return 'sessions'; // the sessions engine, over the same Analytics Engine days (inc/analytics-rows-sessions.php).
	}
	if ( null !== $q['path'] && ! preg_match( '#^/[A-Za-z0-9/._~%@!$&()*+,;=:-]*$#', $q['path'] ) ) {
		return new WP_Error( 'ability_invalid_input', 'that path cannot be filtered in Analytics Engine; ask the stored rows for it (path or day).', array( 'status' => 400 ) );
	}
	return 'events';
}

/**
 * The window's UTC instants: [first site day 00:00, the day after the last). PURE given the zone.
 *
 * @param string $from Y-m-d, site time.
 * @param string $to   Y-m-d, site time.
 * @return array{0:string,1:string}
 */
function snt_arows_utc_bounds( $from, $to ) {
	$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$utc = new DateTimeZone( 'UTC' );
	return array(
		( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
	);
}

/**
 * One Analytics Engine query for the rows. PURE given the human rule. The
 * two shapes are the daily rollup's live-proven ones: everything weighted
 * with sumIf, and visitor-days with a page view from a pv-only query. The
 * referrer is the dims rollup's (inc/analytics-referrer-kind.php): blob3 is ''
 * for no referrer and for an internal click alike, and double10 tells them
 * apart, so (direct) and (internal) are predicates, never blob3 literals.
 * Each half orders by its own figure before the cap, so a capped read keeps
 * the top rows by views and pageview_visits is a floor.
 *
 * @param array  $q       From snt_arows_validate().
 * @param string $lo      UTC instant, inclusive.
 * @param string $hi      UTC instant, exclusive.
 * @param string $tz      Site time zone for day and hour ('' = UTC).
 * @param bool   $pv_only The visitor-days query.
 * @param string $source  One half of a stitched read; '' picks the dataset.
 * @param string $range   That half's side of the split.
 * @return string
 */
function snt_arows_events_sql( array $q, $lo, $hi, $tz, $pv_only, $source = '', $range = '' ) {
	// The planner refuses these first; the builder never interpolates one either ('' reads as a failed half).
	if ( ( null !== $q['path'] && ! preg_match( '#^/[A-Za-z0-9/._~%@!$&()*+,;=:-]*$#', $q['path'] ) ) || ( null !== $q['referrer'] && ! preg_match( '/^[a-z0-9.-]+$|^\((direct|internal|unknown)\)$/', $q['referrer'] ) ) ) {
		return '';
	}
	$tz   = preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ? ", '{$tz}'" : '';
	$cols = array();
	foreach ( $q['dims'] as $d ) {
		$cols[] = ( 'referrer' === $d ? sn_analytics_referrer_value_sql( 'blob3' ) : ( SNT_AROWS_EVENTS_COLS[ $d ] ?? ( 'day' === $d ? "formatDateTime(timestamp, '%Y-%m-%d'{$tz})" : "formatDateTime(timestamp, '%H'{$tz})" ) ) ) . " AS {$d}";
	}
	$metrics = $pv_only
		? array( 'count(DISTINCT index1) AS pageview_visits' )
		: array( "sumIf(_sample_interval, blob1 = 'pv') AS views", 'count(DISTINCT index1) AS visits', "sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum", "sumIf(_sample_interval, blob1 = 'tm') AS time_events", "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events" );
	$ref   = $q['referrer'];
	$ref   = null === $ref ? '' : ( '(direct)' === $ref ? " AND blob3 = ''" . sn_analytics_not_internal_click_sql() : ( SN_ANALYTICS_INTERNAL_REFERRER === $ref ? ' AND ' . sn_analytics_internal_click_sql() : " AND (blob3 = '{$ref}' OR blob3 = 'www.{$ref}')" ) );
	$where = "timestamp >= toDateTime('{$lo}') AND timestamp < toDateTime('{$hi}')" . ( sn_analytics_split_range_ok( $range ) ? $range : '' )
		. ' AND ' . sn_analytics_class_where( $q['class'] ) . sn_analytics_excluded_path_sql()
		. ( $pv_only ? " AND blob1 = 'pv'" : '' )
		. ( in_array( 'link_code', $q['dims'], true ) ? " AND blob2 LIKE '/r/%'" : '' )
		. ( null !== $q['path'] ? " AND (blob2 = '{$q['path']}' OR blob2 = '{$q['path']}/')" : '' )
		. $ref;
	return 'SELECT ' . implode( ', ', array_merge( $cols, $metrics ) )
		. ' FROM ' . ( '' !== (string) $source ? (string) $source : sn_analytics_source( substr( $lo, 0, 10 ) ) )
		. ' WHERE ' . $where . ' GROUP BY ' . implode( ', ', $q['dims'] )
		. ' ORDER BY ' . ( $pv_only ? 'pageview_visits' : 'views' ) . ' DESC LIMIT ' . SNT_AROWS_EVENTS_CAP; // aliases only: AE resolves nothing else in ORDER BY.
}

/**
 * The rows for a validated query over [$from,$to] from Analytics Engine, in
 * the stored read's shape (snt_arows_shape() takes either).
 *
 * @param array  $q    From snt_arows_validate().
 * @param string $from Y-m-d, site time.
 * @param string $to   Y-m-d, site time.
 * @return array{rows:array,capped:bool}|WP_Error
 */
function snt_arows_events_fetch( array $q, $from, $to ) {
	list( $lo, $hi ) = snt_arows_utc_bounds( $from, $to );
	$tz   = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$read = static fn( $pv ) => sn_analytics_stitched_rows( static fn( $s, $r ) => snt_arows_events_sql( $q, $lo, $hi, $tz, $pv, $s, $r ), substr( $lo, 0, 10 ), $hi );
	$main = $read( false );
	$pvs  = null === $main ? null : $read( true );
	if ( null === $main || null === $pvs ) {
		return new WP_Error( 'sn_analytics_read_failed', 'The Analytics Engine read failed; this is not an empty answer.', array( 'status' => 500 ) );
	}
	$key    = static fn( $r ) => implode( "\x1f", array_map( static fn( $d ) => (string) ( $r[ $d ] ?? '' ), $q['dims'] ) );
	$by     = array();
	$capped = false;
	foreach ( $main as $set ) {
		$capped = $capped || count( $set ) >= SNT_AROWS_EVENTS_CAP;
		foreach ( $set as $r ) {
			$k        = $key( $r );
			$m        = $by[ $k ] ?? array_intersect_key( $r, array_flip( $q['dims'] ) ) + array( 'views' => 0, 'visits' => 0, 'pageview_visits' => 0, 'time_sum' => 0, 'time_events' => 0, 'scroll_events' => 0 );
			foreach ( array( 'views', 'visits', 'time_sum', 'time_events', 'scroll_events' ) as $f ) {
				$m[ $f ] += (float) ( $r[ $f ] ?? 0 );
			}
			$by[ $k ] = $m;
		}
	}
	foreach ( $pvs as $set ) {
		$capped = $capped || count( $set ) >= SNT_AROWS_EVENTS_CAP;
		foreach ( $set as $r ) {
			if ( isset( $by[ $key( $r ) ] ) ) {
				$by[ $key( $r ) ]['pageview_visits'] += (int) ( $r['pageview_visits'] ?? 0 );
			}
		}
	}
	// A view's time and scroll beacons share its row only by page and day, as the stored
	// rollup keeps them: the next hour, or another city when geolocation moves between a
	// reader's requests, takes them (475% scroll per view was read live), and a beacon
	// carries no referrer, so a referrer filter puts them all under (direct). Elsewhere
	// they are null, and a row of beacons only (no view) is dropped.
	$own  = ! array_diff( $q['dims'], array( 'path', 'link_code', 'day' ) ) && null === $q['referrer'];
	$rows = array();
	foreach ( $by as $m ) {
		if ( $m['views'] > 0 ) {
			$rows[] = $own ? $m : array( 'time_sum' => null, 'time_events' => null, 'scroll_events' => null ) + $m;
		}
	}
	return array( 'rows' => $rows, 'capped' => $capped );
}
