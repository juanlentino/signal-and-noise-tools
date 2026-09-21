<?php
/**
 * S&N Dashboard, Measurement > Machine Readers: the two Site Health verdicts
 * about machine readers, painted where the question is asked (#1599).
 *
 * Crawler-family drift (inc/family-drift.php, `sn_family_drift_health()`)
 * and machine-reader behaviour (inc/ml-reader-anomalies-health.php,
 * `snt_ml_reader_anomalies_health()`) register as core Site Health direct
 * tests and reached no leaf, classic or native. Here each is one kit box in
 * the 17.4.0 shape: the verdict summary as the notice on top when the status
 * is not good (danger for critical, warning otherwise), else as the hint
 * line. One derivation, exactly the reads the abilities and the Site Health
 * rows make; nothing is computed here. The two boxes are of a height, so
 * they share one os-grid row under the sensor hero; a side whose module
 * is absent leaves the other at full width (absent is not zero).
 *
 * @package SignalNoiseTools
 * @since 17.4.4
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The "Crawler-family drift" box, or '' when the module is absent.
 *
 * @return string
 */
function machine_readers_drift_html() {
	if ( ! function_exists( 'sn_family_drift_report' ) || ! function_exists( 'sn_family_drift_health' ) ) {
		return '';
	}
	$v = \sn_family_drift_health( \sn_family_drift_report(), time() );
	return \snt_kit_section(
		__( 'Crawler-family drift', 'signal-and-noise-tools' ),
		\snt_kit_verdict( is_array( $v ) ? $v : array() ),
		__( 'Whether the plugin and the deployed worker still agree on the crawler families. Checked weekly; a mirror disagreement is critical.', 'signal-and-noise-tools' )
	);
}

/**
 * The "Reader behaviour" box, or '' when the module is absent.
 *
 * @return string
 */
function machine_readers_anomalies_html() {
	if ( ! function_exists( 'snt_ml_reader_anomalies_health' ) ) {
		return '';
	}
	// ponytail: snt_ml_reader_anomalies() runs the per-family series over the same 30-day sensor read the hero already made (the transient when it succeeded, snt_mr_fetch()'s per-request memo when it did not); the ceiling is the eligible-family count, as on Site Health.
	$report = function_exists( 'snt_ml_reader_anomalies' ) ? \snt_ml_reader_anomalies() : null;
	$v      = \snt_ml_reader_anomalies_health( $report );
	return \snt_kit_section(
		__( 'Reader behaviour', 'signal-and-noise-tools' ),
		\snt_kit_verdict( is_array( $v ) ? $v : array() ),
		__( 'Whether any measured crawler family deviated from its 30-day norm or went silent in the last 7 days. An instrument, not a gate.', 'signal-and-noise-tools' )
	);
}

/**
 * The two verdict boxes on one row, or the one that paints at full width.
 *
 * @return string
 */
function machine_readers_health_row_html() {
	$drift = machine_readers_drift_html();
	$anoms = machine_readers_anomalies_html();
	return \snt_kit_grid( array( $drift, $anoms ) );
}
