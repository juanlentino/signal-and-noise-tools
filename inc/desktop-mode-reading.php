<?php
/**
 * Signal & Noise Tools: SN Reading, what readers do once here. Scroll depth,
 * time on page, visits, goal events and the field Core Web Vitals. Every
 * figure is read from a rollup another view already fills.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scroll rows from the four-band distribution. PURE.
 *
 * @param array $dist [{label, views}] in band order: 0-25, 25-50, 50-75, 75-100.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_reading_scroll_rows( array $dist ) {
	$v     = array_map( static fn( $r ) => (int) ( $r['views'] ?? 0 ), array_values( $dist ) );
	$total = array_sum( $v );
	if ( 4 !== count( $v ) || $total < 1 ) {
		return array();
	}
	return array(
		array( 'label' => 'Reached half the page', 'value' => snt_desktop_pct( $v[2] + $v[3], $total ) ),
		array( 'label' => 'Reached three quarters', 'value' => snt_desktop_pct( $v[3], $total ) ),
	);
}

/**
 * Visit rows: a visits-weighted fold of the daily session rollup. PURE.
 *
 * @param array|null $days [{visits, bounce_pct, ppv, median_dur}]; null when the read failed.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_reading_visit_rows( $days ) {
	$n = 0; $bounce = 0.0; $ppv = 0.0; $dur = 0.0;
	foreach ( (array) $days as $d ) {
		$w       = (int) ( $d['visits'] ?? 0 );
		$n      += $w;
		$bounce += $w * (float) ( $d['bounce_pct'] ?? 0 );
		$ppv    += $w * (float) ( $d['ppv'] ?? 0 );
		$dur    += $w * (float) ( $d['median_dur'] ?? 0 );
	}
	if ( $n < 1 ) {
		return array();
	}
	return array(
		array( 'label' => 'Visits', 'value' => number_format_i18n( $n ) ),
		array( 'label' => 'One page only', 'value' => round( $bounce / $n ) . '%' ),
		array( 'label' => 'Pages per visit', 'value' => number_format_i18n( $ppv / $n, 2 ) ),
		array( 'label' => 'Typical visit', 'value' => snt_desktop_reading_seconds( $dur / $n ) ),
	);
}

/**
 * Seconds as "42s" or "3m 05s". PURE.
 *
 * @param int|float $s Seconds.
 * @return string
 */
function snt_desktop_reading_seconds( $s ) {
	$s = (int) round( $s );
	return $s < 60 ? $s . 's' : intdiv( $s, 60 ) . 'm ' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT ) . 's';
}

/**
 * One Core Web Vital: the share of page loads in the good band. PURE.
 *
 * @param string $name LCP | INP | CLS.
 * @param array  $dist [{label, views}] in band order: good, needs work, poor.
 * @return array{label:string,value:string}|null Null when nothing was measured.
 */
function snt_desktop_reading_vital_row( $name, array $dist ) {
	$v     = array_map( static fn( $r ) => (int) ( $r['views'] ?? 0 ), array_values( $dist ) );
	$total = array_sum( $v );
	if ( 3 !== count( $v ) || $total < 1 ) {
		return null;
	}
	return array( 'label' => $name, 'value' => snt_desktop_pct( $v[0], $total ) . ' good · ' . snt_desktop_pct( $v[2], $total ) . ' poor · ' . number_format_i18n( $total ) . ' loads' );
}

/**
 * The groups, read live.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array<int,array<string,mixed>>
 */
function snt_desktop_reading_groups( array $win ) {
	$dist   = static fn( $m ) => function_exists( 'sn_analytics_distribution' ) ? (array) sn_analytics_distribution( $m, $win['from'], $win['to'], 'human' ) : array();
	$scroll = snt_desktop_reading_scroll_rows( $dist( 'scroll' ) );
	$pct    = function_exists( 'sn_analytics_percentiles' ) ? sn_analytics_percentiles( 'time', $win['from'], $win['to'], 'human' ) : null;
	foreach ( (array) $pct as $p ) {
		if ( 'p50' === ( $p['label'] ?? '' ) && (float) $p['value'] > 0 ) {
			$scroll[] = array( 'label' => 'Median time on page', 'value' => snt_desktop_reading_seconds( (float) $p['value'] / 1000 ) ); // the beacon reports milliseconds.
		}
	}
	$events = array();
	foreach ( function_exists( 'sn_analytics_top_events' ) ? (array) sn_analytics_top_events( $win['from'], $win['to'], 4 ) : array() as $e ) {
		$events[] = array( 'label' => (string) $e['name'], 'value' => number_format_i18n( (int) $e['events'] ) . ' · ' . number_format_i18n( (int) $e['visitors'] ) . ' visitors' );
	}
	$vitals = array_values( array_filter( array( snt_desktop_reading_vital_row( 'LCP', $dist( 'lcp' ) ), snt_desktop_reading_vital_row( 'INP', $dist( 'inp' ) ), snt_desktop_reading_vital_row( 'CLS', $dist( 'cls' ) ) ) ) );
	return array(
		snt_desktop_group( 'On the page', $scroll, 'No scroll or time events in this window.' ),
		snt_desktop_group( 'Visits', snt_desktop_reading_visit_rows( function_exists( 'sn_session_rollup_read' ) ? sn_session_rollup_read( $win['from'], $win['to'], 'human' ) : null ), 'No visits rolled up in this window.' ),
		snt_desktop_group( 'Goal events', $events, 'No goal events in this window.' ),
		snt_desktop_group( 'Core Web Vitals', $vitals, 'No field measurements in this window.' ),
	);
}
