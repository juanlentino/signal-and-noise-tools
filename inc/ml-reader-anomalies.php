<?php
/**
 * reader-anomalies — the machine-reader subsystem's first ML consumer.
 *
 * Adds no statistics. It supplies a DENSER INPUT to the analytics signal engine
 * (I1-I6), which is complete and starved: Theil-Sen and Holt currently run over
 * a few visits a day, while the crawler ledger carries ~500x that volume
 * (69,833 machine requests in 30 days against ~130 human visits, measured
 * 2026-09-02).
 *
 * Three tiers, all from the same zero-filled series:
 *   descriptive  sn_analytics_anomaly_of()      median/MAD z, two-sided
 *   predictive   sn_analytics_trajectory_of()   Theil-Sen slope
 *   predictive   sn_analytics_forecast_of()     Holt, GATED BY SKILL (v13.75.0)
 *
 * The skill gate matters more here than on the human side. It does NOT fire on
 * thin data (measured +0.06 on realistic noisy traffic — a smoothed level
 * legitimately beats persistence on a stationary series). It fires on
 * STRUCTURAL MISFIT: a reversed trend, a cycle Holt cannot represent. Crawler
 * series are where those live, so the gate decides which families earn a
 * forecast, per family, on measured evidence.
 *
 * FAIL-CLOSED: a failed sensor fetch is 'unavailable' with the reason, never an
 * empty findings list. An instrument that cannot read must not report calm.
 *
 * @package Signal_And_Noise_Tools
 * @since   13.76.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the pipeline over the trailing SN_MR_SERIES_WINDOW days.
 *
 * @param int|null $now Unix time; null uses time(). Injectable for fixtures.
 * @return array
 */
function snt_ml_reader_anomalies( $now = null ) {
	$now = ( null === $now ) ? ( function_exists( 'time' ) ? time() : 0 ) : (int) $now;
	$to  = gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ); // yesterday: today is partial.
	$from = gmdate( 'Y-m-d', $now - SN_MR_SERIES_WINDOW * DAY_IN_SECONDS );

	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return snt_ml_reader_unavailable( 'sensor_unavailable', $from, $to );
	}
	$fetched = snt_mr_fetch( SN_MR_SERIES_WINDOW, 'aggregate' );
	if ( empty( $fetched['ok'] ) ) {
		return snt_ml_reader_unavailable( (string) ( $fetched['error'] ?? 'fetch_failed' ), $from, $to );
	}
	$rows = (array) ( $fetched['rows'] ?? array() );

	$days_by_family = snt_mr_family_days( $rows, $from, $to );
	$eligible       = snt_mr_eligible_families( $rows, $from, $to );
	$excluded       = array();
	foreach ( $days_by_family as $fam => $n ) {
		if ( ! in_array( $fam, $eligible, true ) ) {
			$excluded[ $fam ] = $n;
		}
	}

	$families = array();
	$flagged  = 0;
	foreach ( $eligible as $family ) {
		$series  = snt_mr_daily_series( $rows, $family, $from, $to );
		$signals = snt_ml_reader_signals_for( $family, $series, $from, $to );
		$flagged += count(
			array_filter(
				$signals,
				static function ( $s ) {
					return 'anomaly' === ( $s['kind'] ?? '' );
				}
			)
		);
		$values     = array_map( static function ( $r ) { return (int) $r['views']; }, $series );
		$families[] = array(
			'family'       => $family,
			'days_present' => (int) ( $days_by_family[ $family ] ?? 0 ),
			'total'        => array_sum( $values ),
			'signals'      => $signals,
		);
	}

	return array(
		'ok'       => true,
		'state'    => 'ok',
		'window'   => array( 'from' => $from, 'to' => $to, 'days' => SN_MR_SERIES_WINDOW ),
		'floor'    => array( 'min_days' => SN_MR_SERIES_MIN_DAYS, 'of' => SN_MR_SERIES_WINDOW ),
		'eligible' => $eligible,
		'excluded' => $excluded,
		'families' => $families,
		'counts'   => array(
			'families_seen'     => count( $days_by_family ),
			'families_eligible' => count( $eligible ),
			'anomalies'         => $flagged,
		),
	);
}

/**
 * The three tiers for one family's series.
 *
 * Every composer is function_exists-guarded: the CLI harnesses load single real
 * modules, and an unguarded cross-module call turns a fixture run into a fatal.
 *
 * @return array Signal[]
 */
function snt_ml_reader_signals_for( $family, array $series, $from, $to ) {
	$label = 'Reader: ' . $family;
	$out   = array();

	// Anomalies are reported only for the LAST 7 days: the whole window is the
	// baseline, and re-reporting a spike from three weeks ago every day is noise,
	// not a finding.
	$recent_from = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - 6 * DAY_IN_SECONDS );
	if ( function_exists( 'sn_analytics_anomaly_of' ) ) {
		foreach ( sn_analytics_anomaly_of( $family, $label, $series, $recent_from, $to ) as $sig ) {
			$out[] = $sig;
		}
	}
	if ( function_exists( 'sn_analytics_trajectory_of' ) ) {
		$traj = sn_analytics_trajectory_of( $family, $label, $series, $from, $to, 10 );
		if ( is_array( $traj ) ) {
			$out[] = $traj;
		}
	}
	if ( function_exists( 'sn_analytics_forecast_of' ) ) {
		$fc = sn_analytics_forecast_of( $family, $label, $series, $from, $to, array() );
		if ( is_array( $fc ) ) {
			$out[] = $fc;
		}
	}
	return $out;
}

/** The fail-closed record. A sensor that did not answer is not a quiet sensor. */
function snt_ml_reader_unavailable( $reason, $from, $to ) {
	return array(
		'ok'     => false,
		'state'  => 'unavailable',
		'reason' => (string) $reason,
		'window' => array( 'from' => (string) $from, 'to' => (string) $to, 'days' => SN_MR_SERIES_WINDOW ),
	);
}

/** Pipeline entry for snt_ml_pipelines(). */
function snt_ml_pipeline_reader_anomalies( $args = array() ) {
	return snt_ml_reader_anomalies( isset( $args['now'] ) ? (int) $args['now'] : null );
}
