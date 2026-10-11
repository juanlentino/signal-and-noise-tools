<?php
/**
 * Signal & Noise Tools: reader behavior, verified apart.
 * Section painters, host-agnostic.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reader anomalies: the verified and unverified reads per day (a time
 * histogram on the kit; a day table on the classic surface), then each
 * co-movement as a warning verdict naming the network, families as chips.
 *
 * @param array      $daily   snt_mr_verification_daily().
 * @param array|null $report  snt_ml_reader_anomalies().
 * @param string     $surface kit|classic.
 * @return string
 */
function snt_ev_paint_anomalies( array $daily, $report, $surface = 'kit' ) {
	$classic = 'classic' === $surface;
	$chart   = '';
	if ( ! $classic ) {
		$chart = snt_kit_histogram(
			array(
				array( 'key' => 'verified', 'label' => __( 'Verified', 'signal-and-noise-tools' ), 'tone' => 'info' ),
				array( 'key' => 'unverified', 'label' => __( 'Unverified', 'signal-and-noise-tools' ), 'tone' => 'neutral' ),
			),
			$daily['columns'],
			array(
				'heading' => __( 'Third-party reads per day, verified and unverified', 'signal-and-noise-tools' ),
				'start'   => strtotime( $daily['from'] . ' 00:00:00 UTC' ),
				'end'     => strtotime( $daily['to'] . ' 23:59:59 UTC' ),
				'legend'  => true,
				'height'  => 140,
			)
		);
	} else {
		$days = snt_mr_day_range( $daily['from'], $daily['to'] );
		$rows = array();
		foreach ( $daily['columns'] as $i => $c ) {
			$rows[] = array( 'day' => (string) ( $days[ $i ] ?? '' ), 'verified' => number_format_i18n( (int) $c[0] ), 'unverified' => number_format_i18n( (int) $c[1] ) );
		}
		$chart = snt_ev_table( $surface, array( 'day' => __( 'Day', 'signal-and-noise-tools' ), 'verified' => __( 'Verified', 'signal-and-noise-tools' ), 'unverified' => __( 'Unverified', 'signal-and-noise-tools' ) ), $rows, 0, __( 'Third-party reads per day, verified and unverified', 'signal-and-noise-tools' ) );
	}
	$verdicts = '';
	foreach ( (array) ( is_array( $report ) ? ( $report['co_movement'] ?? array() ) : array() ) as $c ) {
		$chips = implode( ' ', array_map( static fn( $f ) => $classic ? '<span class="sn-mr-chip">' . esc_html( $f ) . '</span>' : snt_kit_chip( $f ), (array) $c['families'] ) );
		$verdicts .= snt_ev_verdict( $surface, esc_html( sprintf(
			/* translators: 1: families, 2: rose or fell, 3: network owner, 4: day. */
			__( '%1$d families %2$s together from %3$s on %4$s, unverified:', 'signal-and-noise-tools' ),
			count( (array) $c['families'] ),
			'down' === ( $c['direction'] ?? '' ) ? __( 'fell', 'signal-and-noise-tools' ) : __( 'rose', 'signal-and-noise-tools' ),
			(string) $c['network'],
			(string) $c['day']
		) ) . ' ' . $chips );
	}
	if ( '' === $verdicts ) {
		$verdicts = snt_ev_lead( $surface, is_array( $report ) && 'ok' === ( $report['state'] ?? '' )
			? __( 'No co-movement: no three families moved together on unverified reads from one network.', 'signal-and-noise-tools' )
			: __( 'Reader behavior is not measured: the sensor did not answer.', 'signal-and-noise-tools' ) );
	}
	return snt_ev_box( $surface, __( 'Reader anomalies, verified apart', 'signal-and-noise-tools' ), $chart . $verdicts );
}
