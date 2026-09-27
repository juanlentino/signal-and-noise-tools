<?php
/**
 * THE one "counted human" rule every human reader routes through.
 *
 * Human = the network did not class the hit bot/suspect (blob7 = 'human') AND
 * the visitor-day did not exceed SNT_ANALYTICS_VDAY_PV_CAP page views. index1
 * is a daily-rotating visitor hash, so a hash IS a visitor-day: one list of
 * over-cap hashes, read over the widest window any reader uses, excludes the
 * right rows from any narrower window too (a hash outside a window matches
 * nothing there). Over-cap visitor-days read as automated whatever their class.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Measured 2026-09 on sn_pageviews (28 days): ONE human-classed visitor-day
// (Chrome macOS, 2026-09-22) made 258 of the 497 human page views; the next
// highest were 20, 12, 12, 9, 7. No reader does 50 pages in a day.
const SNT_ANALYTICS_VDAY_PV_CAP = 50;
// Widest reader window: AE keeps ~90 days; the north star reads 12 weeks.
const SNT_ANALYTICS_VDAY_WINDOW_DAYS = 92;
const SNT_ANALYTICS_VDAY_LIST_MAX    = 500;
const SNT_ANALYTICS_VDAY_CACHE_KEY   = 'sn_analytics_overcap_vdays';

/**
 * AE SQL: visitor-days over the page-view cap in the trailing window. Uses the
 * GROUP BY index1, toDate(timestamp) HAVING shape verified live on 2026-09-27.
 *
 * @return string
 */
function sn_analytics_overcap_sql() {
	$days = (int) SNT_ANALYTICS_VDAY_WINDOW_DAYS;
	$cap  = (int) SNT_ANALYTICS_VDAY_PV_CAP;
	$max  = (int) SNT_ANALYTICS_VDAY_LIST_MAX;
	return implode( ' ', array(
		'SELECT index1 AS vid, sum(_sample_interval) AS views',
		'FROM ' . ( defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews' ),
		"WHERE blob1 = 'pv' AND timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY index1, toDate(timestamp)',
		"HAVING sum(_sample_interval) > {$cap}",
		"LIMIT {$max}",
	) );
}

/**
 * Keep only well-formed visitor hashes (hex). The list comes from our own
 * dataset but is interpolated into SQL, so it is treated as untrusted. PURE.
 *
 * @param array $hashes Candidate hashes.
 * @return string[]
 */
function sn_analytics_valid_vday_hashes( array $hashes ) {
	$out = array();
	foreach ( $hashes as $h ) {
		$h = strtolower( (string) $h );
		if ( 1 === preg_match( '/^[0-9a-f]{8,64}$/', $h ) ) {
			$out[ $h ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * The over-cap visitor-days, cached for an hour. A failed read returns an empty
 * list with ok=false (cached five minutes) so the UI can say the cap was NOT
 * applied, instead of silently counting everything.
 *
 * @return array{hashes:string[],ok:bool,truncated:bool}
 */
function sn_analytics_overcap_vdays() {
	$fail = array( 'hashes' => array(), 'ok' => false, 'truncated' => false );
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return $fail;
	}
	$cached = get_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
	if ( is_array( $cached ) && isset( $cached['hashes'], $cached['ok'] ) ) {
		return $cached;
	}
	$rows = sn_analytics_query( sn_analytics_overcap_sql() );
	if ( ! is_array( $rows ) ) {
		error_log( '[sn-analytics] over-cap visitor-day read failed; the page-view cap is not applied' );
		set_transient( SNT_ANALYTICS_VDAY_CACHE_KEY, $fail, 5 * 60 );
		return $fail;
	}
	$out = array(
		'hashes'    => sn_analytics_valid_vday_hashes( array_column( $rows, 'vid' ) ),
		'ok'        => true,
		'truncated' => count( $rows ) >= SNT_ANALYTICS_VDAY_LIST_MAX,
	);
	set_transient( SNT_ANALYTICS_VDAY_CACHE_KEY, $out, 3600 );
	return $out;
}

/**
 * ' AND index1 NOT IN (...)' for the given hashes, or '' when none. PURE.
 *
 * @param array $hashes Over-cap hashes.
 * @return string
 */
function sn_analytics_overcap_and( array $hashes ) {
	$hashes = sn_analytics_valid_vday_hashes( $hashes );
	return array() === $hashes ? '' : " AND index1 NOT IN ('" . implode( "','", $hashes ) . "')";
}

/**
 * The SQL condition for one traffic class under the rule. PURE.
 * human/suspect: the class filter minus over-cap visitor-days.
 * bot: the class filter plus over-cap visitor-days.
 *
 * @param string $class  Traffic class (anything unknown reads as human).
 * @param array  $hashes Over-cap hashes.
 * @return string
 */
function sn_analytics_counted_condition( $class, array $hashes ) {
	$class  = in_array( $class, array( 'human', 'suspect', 'bot' ), true ) ? $class : 'human';
	$hashes = sn_analytics_valid_vday_hashes( $hashes );
	if ( 'bot' === $class && array() !== $hashes ) {
		return "(blob7 = 'bot' OR index1 IN ('" . implode( "','", $hashes ) . "'))";
	}
	return "blob7 = '{$class}'" . sn_analytics_overcap_and( $hashes );
}

/**
 * The live condition for a class (reads the cached over-cap list).
 *
 * @param string $class Traffic class.
 * @return string
 */
function sn_analytics_class_where( $class = 'human' ) {
	return sn_analytics_counted_condition( $class, sn_analytics_overcap_vdays()['hashes'] );
}

/**
 * The live exclusion for builders that group by class in PHP.
 *
 * @return string
 */
function sn_analytics_overcap_where() {
	return sn_analytics_overcap_and( sn_analytics_overcap_vdays()['hashes'] );
}

/**
 * What the Overview says about the rule for a window: the over-cap count (and
 * whether the list read at all) plus engaged visitor-days from the durable
 * session rollup (human only; NULL days were not measured).
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @return array{excluded:int,ok:bool,truncated:bool,engaged:?int,measured:int,days:int,class:string}
 */
function sn_analytics_human_rule_reading( $from, $to, $class ) {
	$list = sn_analytics_overcap_vdays();
	$out  = array(
		'excluded'  => count( $list['hashes'] ),
		'ok'        => (bool) $list['ok'],
		'truncated' => (bool) $list['truncated'],
		'engaged'   => null,
		'measured'  => 0,
		'days'      => 0,
		'class'     => (string) $class,
	);
	$rows = ( 'human' === $class && function_exists( 'sn_session_rollup_read' ) ) ? sn_session_rollup_read( $from, $to, 'human' ) : null;
	foreach ( (array) $rows as $r ) {
		++$out['days'];
		if ( null !== ( $r['engaged'] ?? null ) ) {
			++$out['measured'];
			$out['engaged'] = (int) $out['engaged'] + (int) $r['engaged'];
		}
	}
	return $out;
}
