<?php
/**
 * Read-door ability for the reader-anomalies pipeline.
 *
 * @package Signal_And_Noise_Tools
 * @since   13.76.0
 */

defined( 'ABSPATH' ) || exit;

/** Execute callback. */
function snt_ability_reader_anomalies( $input ) {
	if ( ! function_exists( 'snt_ml_reader_anomalies' ) ) {
		return new WP_Error( 'snt_reader_anomalies_unavailable', 'The reader-anomalies pipeline is unavailable.', array( 'status' => 500 ) );
	}
	return snt_ml_reader_anomalies();
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/reader-anomalies', array(
		'label'               => 'Machine-reader volume and shape deviations',
		'description'         => 'Runs the analytics signal engine over the crawler ledger instead of over human traffic. Adds no statistics: the same median/MAD anomaly detector, Theil-Sen trajectory and Holt forecaster the analytics maturity ladder already ships, fed a DENSER series (69,833 machine requests in 30 days against roughly 130 human visits, measured 2026-09-02). Per eligible family: anomalies over the last 7 days against the 30-day baseline (two-sided — a reader going QUIET is the more interesting reading here, and the site argues that declarations and behaviour diverge), a trajectory, and a forecast only where it EARNS one. The forecast is gated on skill = 1 - mae/mae_naive against a persistence baseline over the same backtest folds; skill <= 0 returns a forecast_withheld signal naming the score rather than a line nobody should act on. Eligibility is PRESENCE, not volume: a family needs hits on at least 20 of the 30 days. That floor is derived from the live distribution, which is bimodal with a nine-day gap (2, 9, 10, 11, 14 ... 23, 24, 31 x5), and admits 7 families carrying 97.4% of traffic. A volume floor would have been wrong: amazon-ai shows a median of 160 across 9 present days and has no series at all, only bursts. Days are ZERO-FILLED across the window, because a day with no rows is a real zero and without the fill a crawler that stops simply yields a shorter series, making "went quiet" invisible. state "unavailable" with a reason means the sensor did not answer — never an empty findings list. Read-only; the frozen family enum is untouched and unclassified-machine is measured, never investigated (it is already named on the vendor/purpose axis).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_reader_anomalies',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'state'    => array( 'type' => 'string', 'enum' => array( 'ok', 'unavailable' ) ),
				'reason'   => array( 'type' => 'string' ),
				'window'   => array( 'type' => 'object' ),
				'floor'    => array( 'type' => 'object' ),
				'eligible' => array( 'type' => 'array' ),
				'excluded' => array( 'type' => 'object' ),
				'families' => array( 'type' => 'array' ),
				'counts'   => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
