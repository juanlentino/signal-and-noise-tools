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
 * Per visitor-day: device and each viewed core page's max scroll and summed
 * dwell (tm is a per-flush delta). PURE.
 *
 * @param array    $visits   Visits from sn_sessionize().
 * @param string[] $prefixes Core prefixes.
 * @return array<string,array{device:string,pages:array<string,array{0:float,1:float}>}>
 */
function snt_nsm_core_pages( array $visits, array $prefixes ) {
	$out  = array();
	$seen = array();
	foreach ( $visits as $visit ) {
		foreach ( $visit as $e ) {
			$vid = (string) ( $e['vid'] ?? '' );
			$p   = (string) ( $e['path'] ?? '' );
			if ( '' === $vid || '' === $p || ! snt_nsm_is_core( $p, $prefixes ) ) {
				continue;
			}
			$d = (string) ( $e['device'] ?? '' );
			if ( ! isset( $out[ $vid ] ) || ( '' === $out[ $vid ]['device'] && '' !== $d ) ) {
				$out[ $vid ]['device'] = $d;
			}
			$cur = $out[ $vid ]['pages'][ $p ] ?? array( 0.0, 0.0 );
			$ev  = (string) ( $e['ev'] ?? '' );
			if ( 'sc' === $ev ) {
				$cur[0] = max( $cur[0], (float) ( $e['scroll'] ?? 0 ) );
			} elseif ( 'tm' === $ev ) {
				$cur[1] += (float) ( $e['dwell'] ?? 0 );
			} elseif ( 'pv' === $ev ) {
				$seen[ $vid ][ $p ] = true;
			}
			$out[ $vid ]['pages'][ $p ] = $cur;
		}
	}
	foreach ( $out as $vid => $row ) {
		$out[ $vid ]['pages'] = array_intersect_key( $row['pages'] ?? array(), $seen[ $vid ] ?? array() );
	}
	return $out;
}

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
	foreach ( snt_nsm_core_pages( $visits, $cfg['prefixes'] ) as $row ) {
		if ( array() === $row['pages'] ) {
			continue;
		}
		$d  = in_array( $row['device'], array( 'mobile', 'desktop' ), true ) ? $row['device'] : 'unknown';
		$sc = max( array_column( $row['pages'], 0 ) );
		$dw = array_column( $row['pages'], 1 );
		++$out[ $d ]['viewed'];
		$live = false;
		foreach ( $row['pages'] as $m ) {
			$live = $live || $m[0] >= $cfg['scroll'] || $m[1] >= $cfg['dwell_ms'];
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
