<?php
/**
 * Beacon bot signals per UTC day: the "likely automated" count for any
 * analytics range (Unreleased).
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

/**
 * One row per UTC day and visitor-day that carried signals: the bits, page
 * views and the human flag. The day is a SELECT alias grouped by name, the
 * shape Analytics Engine accepts (never a function in GROUP BY). PURE.
 *
 * @param int    $days   Trailing window.
 * @param string $source One half of a stitched read; '' picks the dataset.
 * @param string $range  That half's side of the split.
 * @return string
 */
function sn_bot_signals_by_day_sql( $days = SNT_BOT_SIGNAL_DAYS, $source = '', $range = '' ) {
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
	return 'SELECT ' . implode( ', ', $cols )
		. ' FROM ' . $source
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY){$range} AND bitAnd(toUInt32(double8), " . SNT_BOT_SIGNAL_PRESENT . ') > 0'
		. ' GROUP BY day, vid LIMIT ' . SNT_BOT_SIGNAL_LIMIT;
}

/**
 * Per day: human visitor-days under the page-view cap that carried signals,
 * and how many scored at the threshold. The readout's human rule. PURE.
 *
 * @param array $rows sn_bot_signals_by_day_sql() rows.
 * @return array<string,array{visitor_days:int,likely_automated:int}> Day ascending.
 */
function sn_bot_signals_by_day( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		$r   = (array) $r;
		$day = substr( (string) ( $r['day'] ?? '' ), 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || (int) ( $r['human'] ?? 0 ) <= 0 || (float) ( $r['views'] ?? 0 ) > SNT_ANALYTICS_VDAY_PV_CAP ) {
			continue;
		}
		$out[ $day ] = $out[ $day ] ?? array( 'visitor_days' => 0, 'likely_automated' => 0 );
		++$out[ $day ]['visitor_days'];
		$out[ $day ]['likely_automated'] += (int) ( sn_bot_signals_score( $r ) >= SNT_BOT_SIGNAL_THRESHOLD );
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

/**
 * Likely-automated human visitor-days over [$from, $to]. A day counts as
 * measured when the beacon's signals were stored for it; the rest are
 * unmeasured (before the beacon carried them, or not yet stored). PURE.
 *
 * @param array  $by_day Stored day map.
 * @param string $from   YYYY-MM-DD.
 * @param string $to     YYYY-MM-DD.
 * @return array{visitor_days:int|null,scored_visitor_days:int|null,measured_days:int,unmeasured_days:int,basis:string,subtracted:bool}
 */
function sn_bot_signals_likely_automated( array $by_day, $from, $to ) {
	$span     = max( 0, (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / 86400 ) + 1 ); // a UTC day, in seconds
	$likely   = 0;
	$scored   = 0;
	$measured = 0;
	foreach ( $by_day as $day => $d ) {
		if ( (string) $day < (string) $from || (string) $day > (string) $to ) {
			continue;
		}
		++$measured;
		$scored += (int) ( $d['visitor_days'] ?? 0 );
		$likely += (int) ( $d['likely_automated'] ?? 0 );
	}
	return array(
		'visitor_days'        => $measured > 0 ? $likely : null,
		'scored_visitor_days' => $measured > 0 ? $scored : null,
		'measured_days'       => $measured,
		'unmeasured_days'     => max( 0, $span - $measured ),
		'basis'               => 'inferred',
		'subtracted'          => false,
	);
}

/**
 * The likely_automated field's schema, shared by get-analytics-summary and its
 * remote twin so the pair stays byte-identical by construction. It hashes into
 * SN_REMOTE_CONTRACT_VERSION: change it and the contract moves.
 *
 * @return array
 */
function snt_analytics_likely_automated_schema() {
	$count = array( 'type' => array( 'integer', 'null' ) );
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Class human only (null otherwise). Presumed-human visitor-days the beacon bot-signal score marks likely automated, beside the totals, never subtracted. Basis inferred: a heuristic.',
		'properties'  => array(
			'visitor_days'        => $count,
			'scored_visitor_days' => $count,
			'measured_days'       => array( 'type' => 'integer' ),
			'unmeasured_days'     => array( 'type' => 'integer' ),
			'basis'               => array( 'type' => 'string', 'enum' => array( 'inferred' ) ),
			'subtracted'          => array( 'type' => 'boolean' ),
		),
	);
}

/**
 * Execute callback for signal-noise/get-analytics-events.
 * Resolves window from input, delegates to sn_analytics_top_events().
 *
 * @param array|null $input  Optional. { range?: int|string }.
 * @return array             Array of { name: string, events: int, visitors: int }.
 */
function sn_ability_get_analytics_events( $input ) {
	$input = is_array( $input ) ? $input : array();
	if ( isset( $input['range'] ) && function_exists( 'snt_analytics_range_is_valid' ) && ! snt_analytics_range_is_valid( $input['range'] ) ) {
		return new WP_Error( 'ability_invalid_input', 'range must be one of 7, 14, 30, 90, 365 or "all".', array( 'status' => 400 ) ); // #1239
	}
	$range = snt_analytics_resolve_range( $input['range'] ?? 30 );
	list( $from, $to ) = snt_analytics_range_dates( $range );
	return function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 100 ) : array();
}

/**
 * Ability execute callback: signal-noise/get-analytics-top-content.
 *
 * Clamps on this side as well as inside sn_analytics_top_paths(), so a hostile
 * or careless `days` cannot widen the query beyond the sensor's own range.
 *
 * @since 13.33.0
 * @param array<string,mixed>|null $input Ability input.
 * @return array<string,mixed>
 */
function snt_ability_get_analytics_top_content( $input ) {
	$input = is_array( $input ) ? $input : array();
	$days  = max( 1, min( 90, (int) ( $input['days'] ?? 7 ) ) );
	$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 5 ) ) );
	$class = snt_analytics_resolve_class( $input['class'] ?? 'human' );
	$range = null;
	if ( isset( $input['range'] ) ) {
		if ( ! snt_analytics_range_is_valid( $input['range'] ) ) {
			return new WP_Error( 'snt_top_content_range', 'range must be one of 7, 14, 30, 90, 365 or all.', array( 'status' => 422 ) );
		}
		list( $range, $from, $to ) = snt_analytics_resolve_window( (string) $input['range'] );
	} else {
		// The site's own day, like the dashboard (was UTC until Unreleased).
		list( $from, $to ) = snt_analytics_range_dates( $days );
	}

	$gsc   = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	$pages = array();
	foreach ( (array) sn_analytics_top_paths( $from, $to, $class, $limit ) as $row ) {
		$path    = (string) ( $row['path'] ?? '' );
		$search  = null !== $gsc ? snt_gsc_metrics_for_path( $path ) : null;
		$pages[] = array(
			'path'        => $path,
			'views'       => (int) ( $row['views'] ?? 0 ),
			'visits'      => (int) ( $row['visits'] ?? 0 ),
			'scroll_avg'  => round( (float) ( $row['scroll_avg'] ?? 0 ), 2 ),
			'time_avg'    => round( (float) ( $row['time_avg'] ?? 0 ), 2 ),
			'impressions' => null === $search ? null : (int) $search['impressions'],
			'position'    => null === $search ? null : round( (float) $search['position'], 1 ),
		);
	}

	return array(
		'days'          => $days,
		'range'         => $range,
		'from'          => $from,
		'to'            => $to,
		'class'         => $class,
		'search_window' => null !== $gsc && isset( $gsc['window'] ) ? $gsc['window'] : null,
		'pages'         => $pages,
	);
}
