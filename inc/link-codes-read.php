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
const SN_LINK_CODES_VID_CHUNK = 250; // hashes per IN list: 250 x 19 characters stays well under the SQL cap.

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
 * @param int    $days   Trailing window.
 * @param string $tz     Site time zone ('' = UTC days).
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split.
 * @return string AE SQL.
 */
function sn_link_codes_visits_sql( $days, $tz, $source = '', $range = '' ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( (int) $days, (string) $tz );
	$source = '' !== (string) $source ? (string) $source : sn_analytics_source( sn_analytics_trailing_from( (int) $days ) );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return 'SELECT index1 AS vid, ' . $day_col . ' AS day, blob2 AS path, blob11 AS city, blob4 AS country, blob5 AS device, '
		. sn_analytics_rollup_class_parts( true )[0] . ' AS class, sum(_sample_interval) AS views'
		. ' FROM ' . $source
		. " WHERE blob1 = 'pv' AND blob2 LIKE '/r/%' AND timestamp >= {$lower}{$range}"
		. ' GROUP BY vid, day, path, city, country, device, class ORDER BY day DESC LIMIT ' . SN_LINK_CODES_LIMIT;
}

/**
 * Everything those visitor-days did, by validated hash only: views per path
 * and, over every event row, the bot-signal bits (no_input only rides on a
 * scroll or time row, never on the page view). '' when no hash is valid. PURE.
 *
 * @param array  $vids   Visitor-day hashes (at most SN_LINK_CODES_VID_CHUNK keep it under the SQL cap).
 * @param int    $days   Trailing window.
 * @param string $tz     Site time zone, the visits query's own lower bound.
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split.
 * @return string AE SQL, or ''.
 */
function sn_link_codes_pages_sql( array $vids, $days, $tz = '', $source = '', $range = '' ) {
	$vids = sn_analytics_valid_vday_hashes( $vids );
	if ( array() === $vids ) {
		return '';
	}
	list( , $lower ) = sn_analytics_rollup_window_exprs( (int) $days, (string) $tz );
	$source = '' !== (string) $source ? (string) $source : sn_analytics_source( sn_analytics_trailing_from( (int) $days ) );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$cols   = array( 'index1 AS vid', 'blob2 AS path', "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views" );
	foreach ( array( 1 => 'webdriver', 2 => 'no_input', 4 => 'ua_mismatch', 8 => 'headless', 16 => 'tz_mismatch', 32 => 'present' ) as $bit => $name ) {
		$cols[] = "max(bitAnd(toUInt32(double8), {$bit})) AS {$name}";
	}
	return 'SELECT ' . implode( ', ', $cols )
		. ' FROM ' . $source
		. " WHERE timestamp >= {$lower}{$range}" . sn_analytics_excluded_path_sql()
		. " AND index1 IN ('" . implode( "','", array_unique( $vids ) ) . "')"
		. ' GROUP BY vid, path LIMIT 5000';
}

/**
 * Rows to one row per visitor-day and code, newest day first. PURE. The bot
 * signals are the visitor-day's own, over all its rows ($pages), as the
 * site-wide reading takes them.
 *
 * @param array $rows  sn_link_codes_visits_sql() rows.
 * @param array $pages sn_link_codes_pages_sql() rows.
 * @return array<int,array{code:string,day:string,city:string,country:string,device:string,class:string,views:int,also:string[]}>
 */
function sn_link_codes_visits( array $rows, array $pages ) {
	$also = array();
	$bits = array();
	foreach ( $pages as $p ) {
		$vid = (string) ( $p['vid'] ?? '' );
		foreach ( array_keys( SNT_BOT_SIGNAL_WEIGHTS ) + array( 'p' => 'present' ) as $bit ) {
			$bits[ $vid ][ $bit ] = max( (int) ( $bits[ $vid ][ $bit ] ?? 0 ), (int) ( $p[ $bit ] ?? 0 ) );
		}
		$path = sn_analytics_canonical_path( (string) ( $p['path'] ?? '' ) );
		if ( '' !== $path && '' === sn_link_code_from_path( $path ) && (int) ( $p['views'] ?? 0 ) > 0 ) {
			$also[ $vid ][ $path ] = ( $also[ $vid ][ $path ] ?? 0 ) + (int) $p['views'];
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
		$m   = $by[ $key ] ?? array( 'code' => $code, 'day' => (string) ( $r['day'] ?? '' ), 'city' => (string) ( $r['city'] ?? '' ), 'country' => (string) ( $r['country'] ?? '' ), 'device' => (string) ( $r['device'] ?? '' ), 'class' => (string) ( $r['class'] ?? 'human' ), 'views' => 0, 'vid' => $vid );
		$m['views'] += (int) ( $r['views'] ?? 0 );
		$by[ $key ]  = $m;
	}
	$out = array();
	foreach ( $by as $m ) {
		$b = $bits[ $m['vid'] ] ?? array();
		if ( 'human' === $m['class'] && (int) ( $b['present'] ?? 0 ) > 0 && sn_bot_signals_score( $b ) >= SNT_BOT_SIGNAL_THRESHOLD ) {
			$m['class'] = 'likely automated';
		}
		$pages_seen = $also[ $m['vid'] ] ?? array();
		uksort( $pages_seen, static fn( $a, $b ) => $pages_seen[ $b ] <=> $pages_seen[ $a ] ?: strcmp( $a, $b ) );
		$m['also'] = array_keys( $pages_seen );
		unset( $m['vid'] );
		$out[] = $m;
	}
	usort( $out, static fn( $a, $b ) => strcmp( $b['day'], $a['day'] ) ?: strcmp( $a['code'], $b['code'] ) );
	return $out;
}

/**
 * The last 90 days per visitor-day, cached five minutes. Null on a failed
 * read. Both reads are stitched across the dataset generations, as every
 * 90-day reader is, so opens keep showing after the legacy dataset stops.
 *
 * @return array|null
 */
function sn_link_codes_visits_read() {
	$hit = get_transient( SN_LINK_CODES_CACHE );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$tz   = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$from = sn_analytics_trailing_from( SN_LINK_CODES_DAYS );
	$to   = gmdate( 'Y-m-d H:i:s', sn_analytics_clock() );
	$sets = sn_analytics_stitched_rows( static fn( $src, $rng ) => sn_link_codes_visits_sql( SN_LINK_CODES_DAYS, $tz, $src, $rng ), $from, $to );
	if ( null === $sets ) {
		return null;
	}
	$rows  = array_merge( array(), ...$sets );
	$pages = array();
	// ponytail: chunks keep each IN list under the 10,000-character SQL cap;
	// SN_LINK_CODES_LIMIT coded visitor-days in 90 days is the ceiling, page it if outreach grows.
	foreach ( array_chunk( array_values( array_unique( sn_analytics_valid_vday_hashes( array_column( $rows, 'vid' ) ) ) ), SN_LINK_CODES_VID_CHUNK ) as $chunk ) {
		$psets = sn_analytics_stitched_rows( static fn( $src, $rng ) => sn_link_codes_pages_sql( $chunk, SN_LINK_CODES_DAYS, $tz, $src, $rng ), $from, $to );
		if ( null === $psets ) {
			return null;
		}
		$pages = array_merge( $pages, ...$psets );
	}
	$out = sn_link_codes_visits( $rows, $pages );
	set_transient( SN_LINK_CODES_CACHE, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}
