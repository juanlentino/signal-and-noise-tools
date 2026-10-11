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
				'The machine-reader sensor did not answer (%s), so reader behavior is unread for this window. That is unknown, not quiet.',
				$reason
			),
		);
	}
	$anoms    = (int) ( $report['counts']['anomalies'] ?? 0 );
	$silences = (int) ( $report['counts']['silences'] ?? 0 );
	$co       = (int) ( $report['counts']['co_movements'] ?? 0 );
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
	if ( 0 === $anoms && 0 === $silences && 0 === $co ) {
		return array(
			'status'  => 'good',
			'summary' => sprintf(
				'%d of %d crawler families carry enough presence to measure. None deviated from its 30-day norm in the last 7 days, and none went silent.',
				$eligible,
				$seen
			),
		);
	}
	// Silence is reported SEPARATELY because it is a different kind of claim: a
	// binary presence rule, not a z-score. Robust z cannot reach zero on counts
	// bounded below by it, so folding the two would let a rule masquerade as a
	// statistic.
	$parts = array();
	if ( $anoms > 0 ) {
		$parts[] = sprintf( '%d deviation(s) from the 30-day norm', $anoms );
	}
	if ( $silences > 0 ) {
		$parts[] = sprintf( '%d family-day(s) of total silence from a reader present on most days', $silences );
	}
	if ( $co > 0 ) {
		// Several families moving the same day on unverified reads from one
		// network: one finding per network, not one per family.
		$parts[] = sprintf( '%d co-movement(s) of three or more families on unverified reads from one network', $co );
	}
	return array(
		'status'  => 'recommended',
		'summary' => sprintf(
			'Last 7 days across %d measured famil(y/ies): %s. Read the rows.',
			$eligible,
			implode( ' and ', $parts )
		),
	);
}

/**
 * One plain line per co-movement: day, direction, network, families.
 *
 * @param array $report Output of snt_ml_reader_anomalies().
 * @return string[]
 */
function snt_ml_reader_co_movement_lines( $report ) {
	$out = array();
	foreach ( (array) ( is_array( $report ) ? ( $report['co_movement'] ?? array() ) : array() ) as $c ) {
		$fams  = (array) ( $c['families'] ?? array() );
		$out[] = sprintf(
			/* translators: 1: day, 2: number of families, 3: up, down or mixed, 4: network owner, 5: the families. */
			__( 'On %1$s, %2$d reader families moved together (%3$s) on unverified reads from %4$s: %5$s. One finding, not %2$d.', 'signal-and-noise-tools' ),
			(string) ( $c['day'] ?? '' ),
			count( $fams ),
			(string) ( $c['direction'] ?? '' ),
			(string) ( $c['network'] ?? '' ),
			implode( ', ', $fams )
		);
	}
	return $out;
}

/**
 * Per family, the verified and the unverified series in words: reads, and the
 * findings, or why the signals are withheld.
 *
 * @param array $report Output of snt_ml_reader_anomalies().
 * @return array<int,array{family:string,verified:string,unverified:string}>
 */
function snt_ml_reader_split_rows( $report ) {
	$cell = static function ( $s ) {
		$reads = sprintf( /* translators: %s: reads. */ __( '%s reads', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $s['total'] ?? 0 ) ) );
		if ( isset( $s['withheld'] ) ) {
			/* translators: 1: reads, 2: why the signals are withheld. */
			return sprintf( __( '%1$s, signals withheld (%2$s)', 'signal-and-noise-tools' ), $reads, (string) $s['withheld'] );
		}
		$n = count( array_filter( (array) ( $s['signals'] ?? array() ), static fn( $g ) => in_array( $g['kind'] ?? '', array( 'anomaly', 'reader_silent' ), true ) ) );
		/* translators: 1: reads, 2: deviations and silences. */
		return sprintf( __( '%1$s, %2$d deviation(s) or silence(s)', 'signal-and-noise-tools' ), $reads, $n );
	};
	$out = array();
	foreach ( (array) ( is_array( $report ) ? ( $report['families'] ?? array() ) : array() ) as $f ) {
		if ( is_array( $f['by_verification'] ?? null ) ) {
			$out[] = array( 'family' => (string) ( $f['family'] ?? '' ), 'verified' => $cell( $f['by_verification']['verified'] ?? array() ), 'unverified' => $cell( $f['by_verification']['unverified'] ?? array() ) );
		}
	}
	return $out;
}

/** Core Site Health registration (direct test). */
function snt_ml_reader_anomalies_register_site_health_test( $tests ) {
	$tests['direct']['snt_ml_reader_anomalies'] = array(
		'label' => __( 'Signal & Noise machine-reader behavior', 'signal-and-noise-tools' ),
		'test'  => 'snt_ml_reader_anomalies_site_health_result',
	);
	return $tests;
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'site_status_tests', 'snt_ml_reader_anomalies_register_site_health_test' );
}

/** The rendered result. */
function snt_ml_reader_anomalies_site_health_result() {
	$report = function_exists( 'snt_ml_reader_anomalies' ) ? snt_ml_reader_anomalies() : null;
	$v      = snt_ml_reader_anomalies_health( $report );
	return array(
		'label'       => __( 'Signal & Noise machine-reader behavior', 'signal-and-noise-tools' ),
		'status'      => in_array( $v['status'], array( 'good', 'recommended', 'critical' ), true ) ? $v['status'] : 'recommended',
		'badge'       => array( 'label' => __( 'Performance', 'signal-and-noise-tools' ), 'color' => 'blue' ),
		'description' => '<p>' . esc_html( $v['summary'] ) . '</p>' . implode( '', array_map( static fn( $l ) => '<p>' . esc_html( $l ) . '</p>', snt_ml_reader_co_movement_lines( $report ) ) ),
		'test'        => 'snt_ml_reader_anomalies',
	);
}
