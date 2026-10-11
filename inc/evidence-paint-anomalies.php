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
	} elseif ( function_exists( 'snt_an_trend_svg' ) && count( $daily['columns'] ) > 1 ) {
		// Classic and S&N Analytics: the Overview's chart, verified as the line
		// and every third-party read as the dashed overlay on one scale.
		$days = snt_mr_day_range( $daily['from'], $daily['to'] );
		$fmt  = static fn( $d ) => '' === (string) $d ? '' : gmdate( 'M j', (int) strtotime( $d . ' 00:00:00 UTC' ) );
		ob_start();
		snt_an_trend_svg(
			array_map( static fn( $c ) => (int) $c[0], $daily['columns'] ),
			array(
				'overlay_series' => array_map( static fn( $c ) => (int) $c[0] + (int) $c[1], $daily['columns'] ),
				'axis'           => array( $fmt( $days[0] ?? '' ), $fmt( end( $days ) ) ),
				'id_suffix'      => 'MrEv',
				'aria_label'     => __( 'Third-party reads per day: verified, and every read', 'signal-and-noise-tools' ),
			)
		);
		$chart = '<p class="sn-mr-key"><span class="sn-mr-key__line"></span>' . esc_html__( 'Verified by Cloudflare', 'signal-and-noise-tools' ) . ' <span class="sn-mr-key__dash"></span>' . esc_html__( 'Every third-party read', 'signal-and-noise-tools' ) . '</p>' . (string) ob_get_clean()
			. snt_ev_sr_days_table( __( 'Third-party reads per day', 'signal-and-noise-tools' ), $days, array( __( 'Verified', 'signal-and-noise-tools' ) => array_map( static fn( $c ) => (int) $c[0], $daily['columns'] ), __( 'Unverified', 'signal-and-noise-tools' ) => array_map( static fn( $c ) => (int) $c[1], $daily['columns'] ) ) );
	}
	$verdicts = '';
	foreach ( (array) ( is_array( $report ) ? ( $report['co_movement'] ?? array() ) : array() ) as $c ) {
		$chips = $classic ? '<strong>' . esc_html( implode( ', ', (array) $c['families'] ) ) . '</strong>' : implode( ' ', array_map( static fn( $f ) => snt_kit_chip( $f ), (array) $c['families'] ) );
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
