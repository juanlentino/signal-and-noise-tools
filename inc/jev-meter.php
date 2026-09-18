<?php
/**
 * Signal & Noise Tools — the Jev meter (16.6.0).
 *
 * The site's own priced ledger of Jev use, the way the Claude itemization
 * is: every request through sn_jev_ask() lands in a bucket per feature per
 * CREDIT CYCLE (TypeSafe's monthly credit runs from the cycle day, the 17th
 * by default, not the calendar month), priced from the tokens the response
 * reports at the pinned rate. A cache hit on the connector is counted and
 * costs nothing. Nothing here projects: a figure is read or absent.
 *
 * @since 16.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_METER_OPTION       = 'sn_jev_meter';
const SN_JEV_METER_CYCLES       = 12;
const SN_JEV_PRICE_PER_M_INPUT  = 0.042; // USD per million input tokens; output is free (docs.typesafe.ai, read 2026-09-18)
const SN_JEV_CREDIT_DEFAULT     = 5.0;
const SN_JEV_CYCLE_DAY_DEFAULT  = 17;
const SN_JEV_FEATURES           = array( 'notes', 'collision', 'lane_map', 'fit', 'other' );

/** The credit and the cycle day, from settings. */
function sn_jev_credit() {
	return max( 0.0, (float) sn_setting( 'theme.jev_credit', SN_JEV_CREDIT_DEFAULT ) );
}
function sn_jev_cycle_day() {
	return max( 1, min( 28, (int) sn_setting( 'theme.jev_cycle_day', SN_JEV_CYCLE_DAY_DEFAULT ) ) );
}

/**
 * The cycle a moment falls in. PURE. Starts on the cycle day at 00:00 UTC,
 * ends the day before the next cycle day.
 *
 * @return array{key:string,start:string,end:string,days_left:int}
 */
function sn_jev_cycle( $now = null, $day = null ) {
	$now = null === $now ? time() : (int) $now;
	$day = null === $day ? sn_jev_cycle_day() : (int) $day;
	$y   = (int) gmdate( 'Y', $now );
	$m   = (int) gmdate( 'n', $now );
	if ( (int) gmdate( 'j', $now ) < $day ) {
		$m--;
		if ( 0 === $m ) {
			$m = 12;
			$y--;
		}
	}
	$start = gmmktime( 0, 0, 0, $m, $day, $y );
	$next  = gmmktime( 0, 0, 0, $m + 1, $day, $y );
	return array(
		'key'       => gmdate( 'Y-m-d', $start ),
		'start'     => gmdate( 'Y-m-d', $start ),
		'end'       => gmdate( 'Y-m-d', $next - DAY_IN_SECONDS ),
		'days_left' => max( 0, (int) ceil( ( $next - $now ) / DAY_IN_SECONDS ) ),
	);
}

/** Price of a request. PURE. */
function sn_jev_price( $input_tokens ) {
	return round( max( 0, (int) $input_tokens ) * SN_JEV_PRICE_PER_M_INPUT / 1000000, 6 );
}

/**
 * Record one request. Called by sn_jev_ask() for every call that reached
 * the connector: a paid answer, a cache hit (cached, free), or a failure
 * (counted, no tokens).
 */
function sn_jev_meter_record( $feature, $input_tokens, $output_tokens = 0, $cached = false, $failed = false ) {
	$feature = in_array( (string) $feature, SN_JEV_FEATURES, true ) ? (string) $feature : 'other';
	$roll    = get_option( SN_JEV_METER_OPTION, array() );
	$roll    = is_array( $roll ) ? $roll : array();
	$key     = sn_jev_cycle()['key'];
	$row     = isset( $roll[ $key ][ $feature ] ) && is_array( $roll[ $key ][ $feature ] ) ? $roll[ $key ][ $feature ] : array( 'requests' => 0, 'cached' => 0, 'failed' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0 );
	$row['requests']++;
	if ( $cached ) {
		$row['cached']++;
	} elseif ( $failed ) {
		$row['failed']++;
	} else {
		$row['input_tokens']  += max( 0, (int) $input_tokens );
		$row['output_tokens'] += max( 0, (int) $output_tokens );
		$row['cost']           = round( (float) $row['cost'] + sn_jev_price( $input_tokens ), 6 );
	}
	$roll[ $key ][ $feature ] = $row;
	if ( count( $roll ) > SN_JEV_METER_CYCLES ) {
		ksort( $roll );
		$roll = array_slice( $roll, -SN_JEV_METER_CYCLES, null, true );
	}
	update_option( SN_JEV_METER_OPTION, $roll, false );
}

/**
 * This cycle's reading. PURE given the roll.
 *
 * @return array{cycle:array,credit:float,spent:float,remaining:float,requests:int,cached:int,failed:int,input_tokens:int,by_feature:array,seeded:bool}
 */
function sn_jev_meter_reading( $roll = null, $now = null ) {
	$roll  = null === $roll ? get_option( SN_JEV_METER_OPTION, array() ) : $roll;
	$cycle = sn_jev_cycle( $now );
	$month = is_array( $roll ) && isset( $roll[ $cycle['key'] ] ) && is_array( $roll[ $cycle['key'] ] ) ? $roll[ $cycle['key'] ] : array();
	$out   = array( 'cycle' => $cycle, 'credit' => sn_jev_credit(), 'spent' => 0.0, 'remaining' => 0.0, 'requests' => 0, 'cached' => 0, 'failed' => 0, 'input_tokens' => 0, 'by_feature' => array(), 'seeded' => ! empty( $month['_seeded'] ) );
	foreach ( $month as $feature => $row ) {
		if ( '_seeded' === $feature || ! is_array( $row ) ) {
			continue;
		}
		$out['spent']        += (float) $row['cost'];
		$out['requests']     += (int) $row['requests'];
		$out['cached']       += (int) $row['cached'];
		$out['failed']       += (int) ( $row['failed'] ?? 0 );
		$out['input_tokens'] += (int) $row['input_tokens'];
		$out['by_feature'][ $feature ] = $row;
	}
	$out['spent']     = round( $out['spent'], 6 );
	$out['remaining'] = round( max( 0.0, $out['credit'] - $out['spent'] ), 6 );
	return $out;
}

/**
 * One-shot seed at install: the passes before 16.6.0 stored their own
 * input tokens (notes, lane map, fit; collision per post). Seeds this
 * cycle's buckets from those once, marked seeded, so the first paint is
 * not zero for spend that already happened. Requests are unknown for the
 * seeded part and read as one per stored pass.
 */
function sn_jev_meter_seed() {
	$roll = get_option( SN_JEV_METER_OPTION, array() );
	$key  = sn_jev_cycle()['key'];
	if ( is_array( $roll ) && isset( $roll[ $key ]['_seeded'] ) ) {
		return 'already';
	}
	$seed = array();
	$n    = get_option( 'sn_jev_notes', null );
	if ( is_array( $n ) && (int) ( $n['usage']['input_tokens'] ?? 0 ) > 0 ) {
		$seed['notes'] = (int) $n['usage']['input_tokens'];
	}
	$l = get_option( 'sn_jev_lanes', null );
	if ( is_array( $l ) && (int) ( $l['input_tokens'] ?? 0 ) > 0 ) {
		$seed['lane_map'] = (int) $l['input_tokens'];
	}
	$f = get_option( 'sn_jev_query_fit', null );
	if ( is_array( $f ) && (int) ( $f['usage']['input_tokens'] ?? 0 ) > 0 ) {
		$seed['fit'] = (int) $f['usage']['input_tokens'];
	}
	foreach ( $seed as $feature => $tokens ) {
		sn_jev_meter_record( $feature, $tokens );
	}
	$roll                    = get_option( SN_JEV_METER_OPTION, array() );
	$roll                    = is_array( $roll ) ? $roll : array();
	$roll[ $key ]['_seeded'] = time();
	update_option( SN_JEV_METER_OPTION, $roll, false );
	return array() === $seed ? 'empty' : 'seeded';
}
add_action( 'init', function () {
	if ( function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready() ) {
		sn_jev_meter_seed();
	}
}, 30 );
