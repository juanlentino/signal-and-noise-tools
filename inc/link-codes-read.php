<?php
/**
 * Signal & Noise Tools: the Links view's reads (coded résumé links, 2026-10-31).
 *
 * Both read what the analytics already keep; nothing new is stored.
 * - Per code (durable): first and last day, views and visitor-days by class,
 *   from the daily rollup, which is kept forever. The code is read back out of
 *   each stored path (sn_link_code_from_path()).
 * - Per visitor-day (Analytics Engine keeps about 90 days): city, country,
 *   device, the read-time class, and the other pages the same visitor-day read,
 *   joined on the visitor-day hash the beacon rows already carry. A human-class
 *   visitor-day whose observe-only bot signals reach the threshold reads
 *   "likely automated" here, so an email scanner that opens a link before its
 *   person does is never counted as that person. Cached five minutes.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LINK_CODES_DAYS  = 90;
const SN_LINK_CODES_LIMIT = 500;
const SN_LINK_CODES_CACHE = 'sn_link_codes_visits';

/**
 * The durable read, for $wpdb->prepare( sql, like, from, to ). PURE.
 *
 * @param string $table The daily rollup table.
 * @return string
 */
function sn_link_codes_summary_sql( $table ) {
	return "SELECT path, class, MIN(day) AS first_day, MAX(day) AS last_day, SUM(views) AS views, SUM(pageview_visits) AS visitor_days FROM {$table} WHERE path LIKE %s AND day BETWEEN %s AND %s GROUP BY path, class";
}

/**
 * Durable rows to one row per code, last seen first. PURE.
 *
 * @param array $rows { path, class, first_day, last_day, views, visitor_days }.
 * @return array<int,array{code:string,first_seen:string,last_seen:string,views:int,human:int,suspect:int,bot:int}>
 */
function sn_link_codes_summary( array $rows ) {
	$by = array();
	foreach ( $rows as $r ) {
		$code  = sn_link_code_from_path( $r['path'] ?? '' );
		$class = (string) ( $r['class'] ?? '' );
		if ( '' === $code || ! in_array( $class, array( 'human', 'suspect', 'bot' ), true ) ) {
			continue;
		}
		$m = $by[ $code ] ?? array( 'code' => $code, 'first_seen' => '', 'last_seen' => '', 'views' => 0, 'human' => 0, 'suspect' => 0, 'bot' => 0 );
		$first           = (string) ( $r['first_day'] ?? '' );
		$m['first_seen'] = '' === $m['first_seen'] || ( '' !== $first && $first < $m['first_seen'] ) ? $first : $m['first_seen'];
		$m['last_seen']  = max( $m['last_seen'], (string) ( $r['last_day'] ?? '' ) );
		$m['views']     += (int) ( $r['views'] ?? 0 );
		$m[ $class ]    += (int) ( $r['visitor_days'] ?? 0 );
		$by[ $code ]     = $m;
	}
	$out = array_values( $by );
	usort( $out, static fn( $a, $b ) => strcmp( $b['last_seen'], $a['last_seen'] ) ?: strcmp( $a['code'], $b['code'] ) );
	return $out;
}

/**
 * Per code over [$from,$to] from the daily rollup. Null on a failed read.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array|null
 */
function sn_link_codes_summary_read( $from, $to ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( sn_link_codes_summary_sql( $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE ), $wpdb->esc_like( '/r/' ) . '%', (string) $from, (string) $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- the table name is the plugin's constant; values are prepared.
	return is_array( $rows ) && '' === (string) $wpdb->last_error ? sn_link_codes_summary( $rows ) : null;
}

/**
 * Page views on coded paths, one row per visitor-day, path and place. PURE.
 *
 * @param int    $days Trailing window.
 * @param string $tz   Site time zone ('' = UTC days).
 * @return string AE SQL.
 */
function sn_link_codes_visits_sql( $days, $tz ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( (int) $days, (string) $tz );
	$cols = array( 'index1 AS vid', "{$day_col} AS day", 'blob2 AS path', 'blob11 AS city', 'blob4 AS country', 'blob5 AS device', sn_analytics_rollup_class_parts( true )[0] . ' AS class' );
	foreach ( array( 1 => 'webdriver', 2 => 'no_input', 4 => 'ua_mismatch', 8 => 'headless', 16 => 'tz_mismatch', 32 => 'present' ) as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	$cols[] = 'sum(_sample_interval) AS views';
	return 'SELECT ' . implode( ', ', $cols )
		. ' FROM ' . sn_analytics_source( sn_analytics_trailing_from( (int) $days ) )
		. " WHERE blob1 = 'pv' AND blob2 LIKE '/r/%' AND timestamp >= {$lower}"
		. ' GROUP BY vid, day, path, city, country, device, class ORDER BY day DESC LIMIT ' . SN_LINK_CODES_LIMIT;
}

/**
 * Every page those visitor-days viewed, by validated hash only. '' when none is valid. PURE.
 *
 * @param array $vids Visitor-day hashes.
 * @param int   $days Trailing window.
 * @return string AE SQL, or ''.
 */
function sn_link_codes_pages_sql( array $vids, $days ) {
	$vids = sn_analytics_valid_vday_hashes( $vids );
	if ( array() === $vids ) {
		return '';
	}
	list( , $lower ) = sn_analytics_rollup_window_exprs( (int) $days, '' );
	return 'SELECT index1 AS vid, blob2 AS path, sum(_sample_interval) AS views'
		. ' FROM ' . sn_analytics_source( sn_analytics_trailing_from( (int) $days ) )
		. " WHERE blob1 = 'pv' AND timestamp >= {$lower}" . sn_analytics_excluded_path_sql()
		. " AND index1 IN ('" . implode( "','", array_unique( $vids ) ) . "')"
		. ' GROUP BY vid, path LIMIT 2000';
}

/**
 * Rows to one row per visitor-day and code, newest day first. PURE.
 *
 * @param array $rows  sn_link_codes_visits_sql() rows.
 * @param array $pages sn_link_codes_pages_sql() rows.
 * @return array<int,array{code:string,day:string,city:string,country:string,device:string,class:string,views:int,also:string[]}>
 */
function sn_link_codes_visits( array $rows, array $pages ) {
	$also = array();
	foreach ( $pages as $p ) {
		$path = sn_analytics_canonical_path( (string) ( $p['path'] ?? '' ) );
		if ( '' !== $path && '' === sn_link_code_from_path( $path ) ) {
			$also[ (string) ( $p['vid'] ?? '' ) ][ $path ] = ( $also[ (string) ( $p['vid'] ?? '' ) ][ $path ] ?? 0 ) + (int) ( $p['views'] ?? 0 );
		}
	}
	$by = array();
	foreach ( $rows as $r ) {
		$code = sn_link_code_from_path( $r['path'] ?? '' );
		$vid  = (string) ( $r['vid'] ?? '' );
		if ( '' === $code || '' === $vid ) {
			continue;
		}
		$key = $vid . '|' . $code;
		$m   = $by[ $key ] ?? array( 'code' => $code, 'day' => (string) ( $r['day'] ?? '' ), 'city' => (string) ( $r['city'] ?? '' ), 'country' => (string) ( $r['country'] ?? '' ), 'device' => (string) ( $r['device'] ?? '' ), 'class' => (string) ( $r['class'] ?? 'human' ), 'views' => 0, 'bits' => array(), 'vid' => $vid );
		foreach ( array_keys( SNT_BOT_SIGNAL_WEIGHTS ) + array( 'p' => 'present' ) as $bit ) {
			$m['bits'][ $bit ] = max( (int) ( $m['bits'][ $bit ] ?? 0 ), (int) ( $r[ $bit ] ?? 0 ) );
		}
		$m['views'] += (int) ( $r['views'] ?? 0 );
		$by[ $key ]  = $m;
	}
	$out = array();
	foreach ( $by as $m ) {
		if ( 'human' === $m['class'] && $m['bits']['present'] > 0 && sn_bot_signals_score( $m['bits'] ) >= SNT_BOT_SIGNAL_THRESHOLD ) {
			$m['class'] = 'likely automated';
		}
		$pages_seen = $also[ $m['vid'] ] ?? array();
		uksort( $pages_seen, static fn( $a, $b ) => $pages_seen[ $b ] <=> $pages_seen[ $a ] ?: strcmp( $a, $b ) );
		$m['also'] = array_keys( $pages_seen );
		unset( $m['bits'], $m['vid'] );
		$out[] = $m;
	}
	usort( $out, static fn( $a, $b ) => strcmp( $b['day'], $a['day'] ) ?: strcmp( $a['code'], $b['code'] ) );
	return $out;
}

/**
 * The last 90 days per visitor-day, cached five minutes. Null on a failed read.
 *
 * @return array|null
 */
function sn_link_codes_visits_read() {
	$hit = get_transient( SN_LINK_CODES_CACHE );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$rows = sn_analytics_query( sn_link_codes_visits_sql( SN_LINK_CODES_DAYS, function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '' ) );
	if ( ! is_array( $rows ) ) {
		return null;
	}
	$sql   = sn_link_codes_pages_sql( array_column( $rows, 'vid' ), SN_LINK_CODES_DAYS );
	$pages = '' === $sql ? array() : sn_analytics_query( $sql );
	if ( ! is_array( $pages ) ) {
		return null;
	}
	$out = sn_link_codes_visits( $rows, $pages );
	set_transient( SN_LINK_CODES_CACHE, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}
