<?php
/**
 * The north star's calibration: the same reader count split by device and
 * recounted at other scroll and dwell floors, so the owner can see which bar
 * separates a read from a bounce on a phone and on a desktop. Read-only; the
 * definition stays the setting.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_NSM_SWEEP_SCROLL = array( 25, 50, 75 );
const SNT_NSM_SWEEP_DWELL  = array( 15, 30, 60 );

/**
 * Visitor-days per device (mobile, desktop, unknown) that viewed a core page,
 * and how many would count as readers under each floor: scroll alone, dwell
 * alone, and the live rule (scroll OR dwell). PURE.
 *
 * @param array $visits Visits.
 * @param array $cfg    snt_nsm_config() shape.
 * @return array<string,array{viewed:int,live:int,scroll:array<int,int>,dwell:array<int,int>}>
 */
function snt_nsm_sweep( array $visits, array $cfg ) {
	$blank = array( 'viewed' => 0, 'live' => 0, 'scroll' => array_fill_keys( SNT_NSM_SWEEP_SCROLL, 0 ), 'dwell' => array_fill_keys( SNT_NSM_SWEEP_DWELL, 0 ) );
	$out   = array( 'mobile' => $blank, 'desktop' => $blank, 'unknown' => $blank );
	foreach ( snt_nsm_page_metrics( $visits ) as $row ) {
		$row['pages'] = array_filter( $row['pages'], static fn( $p ) => snt_nsm_is_core( $p, $cfg['prefixes'] ), ARRAY_FILTER_USE_KEY );
		if ( array() === $row['pages'] ) {
			continue;
		}
		$d  = in_array( $row['device'], array( 'mobile', 'desktop' ), true ) ? $row['device'] : 'unknown';
		$sc = max( array_column( $row['pages'], 0 ) );
		$dw = array_column( $row['pages'], 1 );
		++$out[ $d ]['viewed'];
		$live = false;
		foreach ( $row['pages'] as $m ) {
			$live = $live || snt_nsm_is_read( $m, $cfg );
		}
		$out[ $d ]['live'] += $live ? 1 : 0;
		foreach ( SNT_NSM_SWEEP_SCROLL as $s ) {
			$out[ $d ]['scroll'][ $s ] += $sc >= $s ? 1 : 0;
		}
		foreach ( SNT_NSM_SWEEP_DWELL as $s ) {
			$out[ $d ]['dwell'][ $s ] += max( $dw ) >= 1000 * $s ? 1 : 0;
		}
	}
	return $out;
}
