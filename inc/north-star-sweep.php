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

/**
 * Each visitor-day's bot-signal verdict from sn_bot_signals_by_day_sql() rows:
 * vid => true when the bits it fired (merged across the rows it appears in)
 * score at the threshold, false when they score under it. A vid absent from
 * the rows carried no signals and is not in the map: it was not scored. PURE.
 *
 * @param array $rows Signal rows (vid plus the five bit columns).
 * @return array<string,bool>
 */
function snt_nsm_vid_verdicts( array $rows ) {
	$bits = array();
	foreach ( $rows as $r ) {
		$r   = (array) $r;
		$vid = (string) ( $r['vid'] ?? '' );
		if ( '' === $vid ) {
			continue;
		}
		foreach ( SNT_BOT_SIGNAL_BITS as $name ) {
			$bits[ $vid ][ $name ] = max( (float) ( $bits[ $vid ][ $name ] ?? 0 ), (float) ( $r[ $name ] ?? 0 ) );
		}
	}
	return array_map( static fn( $b ) => sn_bot_signals_score( $b ) >= SNT_BOT_SIGNAL_THRESHOLD, $bits );
}

/**
 * The calibration split by the beacon's bot-signal score, three cohorts side
 * by side: presumed human (scored under the threshold), likely automated
 * (scored at it) and not scored (no signals carried). Each is snt_nsm_sweep()
 * over its own visitor-days, so the three add up to the whole calibration and
 * nothing is subtracted from anything. PURE.
 *
 * @param array              $visits   Visits.
 * @param array              $cfg      snt_nsm_config() shape.
 * @param array<string,bool> $verdicts snt_nsm_vid_verdicts().
 * @return array{presumed_human:array,likely_automated:array,not_scored:array}
 */
function snt_nsm_sweep_by_score( array $visits, array $cfg, array $verdicts ) {
	$split = array( 'presumed_human' => array(), 'likely_automated' => array(), 'not_scored' => array() );
	foreach ( $visits as $v ) {
		$vid = (string) ( $v[0]['vid'] ?? '' );
		$key = ! isset( $verdicts[ $vid ] ) ? 'not_scored' : ( $verdicts[ $vid ] ? 'likely_automated' : 'presumed_human' );
		$split[ $key ][] = $v;
	}
	return array_map( static fn( $part ) => snt_nsm_sweep( $part, $cfg ), $split );
}

/**
 * The calibration window's split, read live: the bot-signal rows for the
 * window (one extra Analytics Engine read, inside the reading's hourly cache)
 * joined to the visits by visitor-day. Null cohorts with a reason when the
 * signals cannot be read or the read hit its row cap: a partial map would
 * file scored visitor-days under not scored. Observe-only, basis inferred.
 *
 * @param array $visits The calibration window's visits.
 * @param array $cfg    snt_nsm_config() shape.
 * @param int   $days   Window length in days.
 * @return array
 */
function snt_nsm_calibration_by_score( array $visits, array $cfg, $days = 28 ) {
	$out = array( 'basis' => 'inferred', 'subtracted' => false );
	if ( ! function_exists( 'sn_analytics_stitched_rows' ) || ! function_exists( 'sn_bot_signals_by_day_sql' ) ) {
		return $out + array( 'cohorts' => null, 'reason' => 'The bot-signal reader is not loaded.' );
	}
	$out['threshold'] = SNT_BOT_SIGNAL_THRESHOLD;
	$sets             = sn_analytics_stitched_rows(
		static fn( $source, $range ) => sn_bot_signals_by_day_sql( $days + 1, $source, $range ),
		sn_analytics_trailing_from( $days + 1 ),
		gmdate( 'Y-m-d H:i:s' )
	);
	if ( null === $sets ) {
		return $out + array( 'cohorts' => null, 'reason' => 'The bot-signal rows could not be read.' );
	}
	if ( array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= SNT_BOT_SIGNAL_LIMIT ) ) {
		return $out + array( 'cohorts' => null, 'reason' => 'The bot-signal read reached its row cap, so some scored visitor-days would read as not scored.' );
	}
	$rows = array_merge( ...$sets );
	$days_seen = array_unique( array_map( static fn( $r ) => substr( (string) ( ( (array) $r )['day'] ?? '' ), 0, 10 ), $rows ) );
	sort( $days_seen );
	return $out + array(
		'signal_days' => count( $days_seen ),
		'first_day'   => $days_seen[0] ?? null,
		'cohorts'     => snt_nsm_sweep_by_score( $visits, $cfg, snt_nsm_vid_verdicts( $rows ) ),
	);
}
