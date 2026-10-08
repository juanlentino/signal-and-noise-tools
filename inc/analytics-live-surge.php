<?php
/**
 * Signal & Noise — live surge, an analytics signal: is right now unusual?
 *
 * Reads a slot log the realtime refresh keeps: distinct human readers per
 * completed 5-minute slot, eight days of them, taken from the hour it already
 * reads (inc/analytics-live-hour.php), so nothing new is collected. The last
 * completed slot is compared with the same time of day on earlier days (one
 * slot either side), robust z over the median and MAD: the analytics signal
 * engine's own anomaly math (sn_analytics_anomaly_of()), with the same
 * mean-deviation fallback when MAD is 0.
 *
 * An ANALYTICS signal, deliberately not an ML pipeline: the ML family's
 * public promise is that it models the writing and reader data never enters
 * it (the ML Maturity page). Reader counts live with the analytics engine,
 * which already runs this math on human traffic (owner, 2026-10-08).
 *
 * Counts are small whole numbers, so two guards keep the verdict honest: the
 * spread never counts as less than one reader (a rigid zero baseline would
 * otherwise call any visitor a surge), and a surge needs at least three
 * readers. Under four days of history the answer is "learning", never a
 * verdict. Only a SURGE is reported: a quiet slot on a small site is the
 * normal state, not a finding.
 *
 * @package SignalNoiseTools
 * @since   23.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SURGE_LOG         = 'sn_live_slot_log';
const SN_ANALYTICS_SURGE_KEEP        = 8 * 86400;
const SN_ANALYTICS_SURGE_SLOT        = 300;
const SN_ANALYTICS_SURGE_MIN_DAYS    = 4;
const SN_ANALYTICS_SURGE_Z           = 3.0;
const SN_ANALYTICS_SURGE_MIN_READERS = 3;

/**
 * Merge an hour's COMPLETED slots into the log and prune it. PURE.
 *
 * @param array<int,int> $log  slot start => readers.
 * @param array          $hour Twelve { t, readers }, the last one current.
 * @param int            $now  Unix time.
 * @return array<int,int>
 */
function sn_analytics_live_log_merge( array $log, array $hour, $now ) {
	$cur = intdiv( (int) $now, SN_ANALYTICS_SURGE_SLOT ) * SN_ANALYTICS_SURGE_SLOT;
	foreach ( $hour as $s ) {
		if ( is_array( $s ) && isset( $s['t'], $s['readers'] ) && (int) $s['t'] < $cur ) {
			$log[ (int) $s['t'] ] = max( 0, (int) $s['readers'] );
		}
	}
	foreach ( array_keys( $log ) as $t ) {
		if ( (int) $t < (int) $now - SN_ANALYTICS_SURGE_KEEP ) {
			unset( $log[ $t ] );
		}
	}
	ksort( $log );
	return $log;
}

/**
 * The verdict for one slot. PURE.
 *
 * @param array<int,int> $log  slot start => readers.
 * @param int            $slot The slot judged (start).
 * @param int            $x    Its readers.
 * @return array{state:string,readers:int,usual:float|null,ratio:float|null,z:float|null,days:int}
 */
function sn_analytics_live_surge( array $log, $slot, $x ) {
	$vals = array();
	$days = array();
	foreach ( $log as $t => $v ) {
		$back = (int) $slot - (int) $t;
		if ( $back < 3600 ) {
			continue; // today's last hour is not its own baseline
		}
		$tod = ( ( $back % 86400 ) + 86400 ) % 86400;
		$tod = $tod > 43200 ? $tod - 86400 : $tod;
		if ( abs( $tod ) <= SN_ANALYTICS_SURGE_SLOT ) {
			$vals[]                                  = (float) $v;
			$days[ (int) round( $back / 86400 ) ] = true;
		}
	}
	$out = array( 'state' => 'learning', 'readers' => (int) $x, 'usual' => null, 'ratio' => null, 'z' => null, 'days' => count( $days ) );
	if ( count( $days ) < SN_ANALYTICS_SURGE_MIN_DAYS ) {
		return $out;
	}
	$median = (float) sn_analytics_stat_median( $vals );
	$dev    = array_map( static fn( $v ) => abs( $v - $median ), $vals );
	$mad    = (float) sn_analytics_stat_median( $dev );
	$sigma  = $mad > 0.0 ? 1.4826 * $mad : sqrt( M_PI / 2.0 ) * ( array_sum( $dev ) / count( $dev ) );
	$sigma  = max( 1.0, $sigma ); // a spread under one reader is not a measurement
	$z      = ( (float) $x - $median ) / $sigma;
	$out['usual'] = round( $median, 1 );
	$out['z']     = round( $z, 1 );
	$out['ratio'] = $median > 0.0 ? round( (float) $x / $median, 1 ) : null;
	$out['state'] = ( $z >= SN_ANALYTICS_SURGE_Z && (int) $x >= SN_ANALYTICS_SURGE_MIN_READERS ) ? 'surge' : 'usual';
	return $out;
}

/**
 * The refresh's step: log the hour, judge the last completed slot.
 *
 * @param array|null $hour Twelve slots, or null when the hour was not read.
 * @return array|null The verdict, or null when there is nothing to judge.
 */
function sn_analytics_live_surge_step( $hour ) {
	if ( ! is_array( $hour ) || count( $hour ) < 2 ) {
		return null;
	}
	$old = get_option( SN_ANALYTICS_SURGE_LOG, array() );
	$old = is_array( $old ) ? $old : array();
	$log = sn_analytics_live_log_merge( $old, $hour, time() );
	if ( $log !== $old ) {
		update_option( SN_ANALYTICS_SURGE_LOG, $log, false );
	}
	$last = $hour[ count( $hour ) - 2 ]; // the last COMPLETED slot
	return sn_analytics_live_surge( $log, (int) $last['t'], (int) $last['readers'] );
}
