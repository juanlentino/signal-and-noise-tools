<?php
/**
 * Site Health surface for reader-anomalies.
 *
 * Split from the pipeline so the pipeline stays pure-ish and testable without WP
 * shims, and so a harness can load one without the other.
 *
 * @package Signal_And_Noise_Tools
 * @since   13.76.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The verdict sentence.
 *
 * Three states, and the middle one is the point: an UNREADABLE sensor is not a
 * calm one. 'unavailable' reports 'recommended' with the reason rather than
 * 'good' with no findings — the same fail-closed position family_drift takes.
 *
 * Anomalies alone never reach 'critical'. This is an INSTRUMENT, not a gate: a
 * crawler behaving oddly is a thing to read, not a site fault, and a badge that
 * cries wolf on openai's normal spikiness is a badge nobody looks at.
 *
 * @param array $report Output of snt_ml_reader_anomalies().
 * @return array{status:string,summary:string}
 */
function snt_ml_reader_anomalies_health( $report ) {
	if ( ! is_array( $report ) || 'ok' !== ( $report['state'] ?? '' ) ) {
		$reason = is_array( $report ) ? (string) ( $report['reason'] ?? 'unknown' ) : 'unknown';
		return array(
			'status'  => 'recommended',
			'summary' => sprintf(
				'The machine-reader sensor did not answer (%s), so reader behaviour is unread for this window. That is unknown, not quiet.',
				$reason
			),
		);
	}
	$anoms    = (int) ( $report['counts']['anomalies'] ?? 0 );
	$eligible = (int) ( $report['counts']['families_eligible'] ?? 0 );
	$seen     = (int) ( $report['counts']['families_seen'] ?? 0 );
	if ( 0 === $eligible ) {
		return array(
			'status'  => 'recommended',
			'summary' => sprintf(
				'No crawler family appeared on at least %d of the last %d days, so nothing carries a statistic yet. %d family(ies) were seen.',
				(int) ( $report['floor']['min_days'] ?? 0 ),
				(int) ( $report['floor']['of'] ?? 0 ),
				$seen
			),
		);
	}
	if ( 0 === $anoms ) {
		return array(
			'status'  => 'good',
			'summary' => sprintf(
				'%d of %d crawler families carry enough presence to measure, and none deviated from its 30-day norm in the last 7 days.',
				$eligible,
				$seen
			),
		);
	}
	return array(
		'status'  => 'recommended',
		'summary' => sprintf(
			'%d reader deviation(s) in the last 7 days across %d measured famil(y/ies) — read the rows; a family running BELOW its norm is as real a finding as one running above.',
			$anoms,
			$eligible
		),
	);
}

/** Core Site Health registration (direct test). */
function snt_ml_reader_anomalies_register_site_health_test( $tests ) {
	$tests['direct']['snt_ml_reader_anomalies'] = array(
		'label' => __( 'Signal & Noise machine-reader behaviour', 'signal-and-noise-tools' ),
		'test'  => 'snt_ml_reader_anomalies_site_health_result',
	);
	return $tests;
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'site_status_tests', 'snt_ml_reader_anomalies_register_site_health_test' );
}

/** The rendered result. */
function snt_ml_reader_anomalies_site_health_result() {
	$v = snt_ml_reader_anomalies_health( function_exists( 'snt_ml_reader_anomalies' ) ? snt_ml_reader_anomalies() : null );
	return array(
		'label'       => __( 'Signal & Noise machine-reader behaviour', 'signal-and-noise-tools' ),
		'status'      => in_array( $v['status'], array( 'good', 'recommended', 'critical' ), true ) ? $v['status'] : 'recommended',
		'badge'       => array( 'label' => __( 'Performance', 'signal-and-noise-tools' ), 'color' => 'blue' ),
		'description' => '<p>' . esc_html( $v['summary'] ) . '</p>',
		'test'        => 'snt_ml_reader_anomalies',
	);
}
