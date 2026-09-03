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
	$silent   = 0;
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
		$silent += count(
			array_filter(
				$signals,
				static function ( $s ) {
					return 'reader_silent' === ( $s['kind'] ?? '' );
				}
			)
		);
		$values = array_map( static function ( $r ) { return (int) $r['views']; }, $series );
		// BASELINE on every row, including quiet ones. Without it the median
		// exists only INSIDE an anomaly signal's interval and label, so a family
		// that deviated from nothing reports no norm at all — and "is 400/day
		// normal for uptime?" is the first question anyone asks of this payload.
		//
		// null and 0 are DIFFERENT and both are preserved. sn_analytics_stat_mad()
		// returns null when it cannot compute a spread; a genuinely rigid series
		// returns 0, which is a real measurement. Collapsing them would report
		// "no variation observed" for a series nothing could read — the same
		// failure as an empty findings list standing in for a dead sensor.
		$median = function_exists( 'sn_analytics_stat_median' ) ? sn_analytics_stat_median( $values ) : null;
		$mad    = ( function_exists( 'sn_analytics_stat_mad' ) && null !== $median )
			? sn_analytics_stat_mad( $values, $median )
			: null;
		$families[] = array(
			'family'       => $family,
			'days_present' => (int) ( $days_by_family[ $family ] ?? 0 ),
			'total'        => array_sum( $values ),
			'baseline'     => array( 'median' => $median, 'mad' => $mad ),
			'signals'      => $signals,
		);
	}

	$out = array(
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
			'silences'          => $silent,
		),
	);

	// Record this payload's STRUCTURE, so "has the shape held still?" is a
	// measurement rather than someone's recollection. Recorded on every real run,
	// and snt_ml_reader_anomalies_record_shape() below guarantees one such run an
	// hour. Irregular cadence on top of that is safe — sn_shape_stability() gates
	// on span AND count, so extra readings can only shorten the wait.
	//
	// `excluded` is declared OPEN: its keys are family names, so a family crossing
	// the eligibility floor removes one. That is data moving, not shape moving.
	if ( function_exists( 'sn_shape_fingerprint' ) && function_exists( 'sn_shape_ledger_record' ) ) {
		sn_shape_ledger_record(
			'reader-anomalies',
			sn_shape_fingerprint( $out, array( 'excluded' ) ),
			$now
		);
	}

	return $out;
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
	// SILENCE gets its own rule, because robust z structurally cannot express it
	// on this data. Measured live 2026-09-02: for EVERY eligible family a day of
	// zero hits scores |z| between 0.74 and 2.30 against a 3.5 threshold. The
	// reason is the data's shape, not the threshold — these are counts bounded
	// below by ZERO, so the furthest a value can fall from the median is the
	// median itself, and the most negative z obtainable is 0.6745 * median / MAD.
	// With MADs running 0.29x to 0.92x of their medians here, that ceiling is
	// ~2.3 at best. Lowering the threshold cannot fix it: openai's ceiling is
	// 0.80, and a threshold under that would fire constantly on the UP side.
	//
	// So this pipeline shipped describing itself as two-sided while the quiet
	// half could never fire. Eligibility already means the family appears on at
	// least 20 of 30 days, so a zero day for one of them is unusual BY
	// CONSTRUCTION. Binary, stated as a rule, not dressed as a statistic.
	foreach ( $series as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( '' === $day || $day < $recent_from || 0 !== (int) ( $row['views'] ?? 0 ) ) {
			continue;
		}
		$out[] = array(
			'id'            => 'reader-silent:' . $family . ':' . $day,
			'tier'          => 'predictive',
			'kind'          => 'reader_silent',
			'subject'       => (string) $family,
			'subject_label' => $label,
			'stat'          => 'presence_rule',
			'value'         => 0,
			'direction'     => 'down',
			'confidence'    => 'high',
			'window'        => array( 'from' => (string) $from, 'to' => (string) $to, 'baseline_days' => count( $series ) ),
			'plain_label'   => sprintf(
				'%s went silent on %s — no reads at all, from a reader present on most days',
				$label, $day
			),
			'severity'      => 3,
		);
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

/**
 * Drive one pipeline run per hour, so the shape ledger fills at a usable rate.
 *
 * The ledger shipped in v13.84.0 recording on every real run, on the reasoning
 * that the payload "is already being produced". True, but never measured: the
 * only GUARANTEED caller is WordPress's weekly site-health check. The MCP door
 * and the admin surface are on-demand and may go untouched for weeks. At one
 * reading a week the SN_SHAPE_STABLE_READINGS gate of 24 needs ~24 weeks — a
 * gate that is correct and unreachable.
 *
 * Riding the machine-reader snapshot's existing hourly cron costs ZERO extra
 * outbound calls. Both callers land on the SAME transient: the snapshot asks
 * snt_mr_fetch( SN_MR_SNAPSHOT_DAYS ) and this pipeline asks
 * snt_mr_fetch( SN_MR_SERIES_WINDOW, 'aggregate' ) — 30 and 30, and 'aggregate'
 * is that parameter's default — so both build cache key `sn_mr_rows_30_aggregate`
 * against a 15-minute TTL. Running second, we read what the snapshot just warmed.
 *
 * A failed fetch is NOT cached, so a broken sensor costs one extra request an
 * hour while already broken, and records no shape at all: the unavailable path
 * returns before the ledger write, so a degenerate payload can never be
 * mistaken for a settled one.
 *
 * @return void
 */
function snt_ml_reader_anomalies_record_shape() {
	snt_ml_reader_anomalies();
}

// PRIORITY 20 IS LOAD-BEARING, not decoration. snt_mr_snapshot_refresh() is
// registered on this same hook at the default 10 and is what warms the
// transient. At 10 the ordering between two same-priority callbacks is
// registration order across two files — and losing that race means this makes
// its own outbound request every hour, forever, silently. Pinned in
// tests/ml-reader-anomalies-cadence.php against the snapshot's REGISTERED
// priority rather than a hardcoded 10, so moving either side reds the test.
//
// Guarded because the CLI test harnesses load this file bare, with no WordPress.
if ( defined( 'SN_MR_SNAPSHOT_HOOK' ) && function_exists( 'add_action' ) ) {
	add_action( SN_MR_SNAPSHOT_HOOK, 'snt_ml_reader_anomalies_record_shape', 20 );
}

/** Pipeline entry for snt_ml_pipelines(). */
function snt_ml_pipeline_reader_anomalies( $args = array() ) {
	return snt_ml_reader_anomalies( isset( $args['now'] ) ? (int) $args['now'] : null );
}
