<?php
/**
 * Signal & Noise — AI bootstrap: the monthly spend rollup.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_spend_month_key(), snt_ai_add_month_spend(),
 * snt_ai_spend_this_month()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v9.26.0: the current spend-rollup bucket key, site-local YYYY-MM.
 *
 * Uses wp_date() (site timezone) when available so the "month" matches the
 * owner's calendar; falls back to gmdate() outside WordPress (CLI tests).
 *
 * @return string
 * @since 9.26.0
 */
function snt_ai_spend_month_key() {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m' ) : gmdate( 'Y-m' );
}

/**
 * v9.26.0: fold one call's USD cost into the current month's rollup bucket.
 *
 * A single autoload=no option keyed YYYY-MM, pruned to the last
 * SN_AI_SPEND_MONTHS buckets (keys sort chronologically). No-op on a
 * zero/negative cost (e.g. an unpriced model, per snt_ai_estimate_cost).
 *
 * @param float $cost USD cost of the call.
 * @return void
 * @since 9.26.0
 */
function snt_ai_add_month_spend( $cost ) {
	$cost = (float) $cost;
	if ( $cost <= 0 ) {
		return;
	}
	$roll = get_option( SN_AI_SPEND_ROLLUP_OPT, array() );
	if ( ! is_array( $roll ) ) {
		$roll = array();
	}
	$key          = snt_ai_spend_month_key();
	$roll[ $key ] = round( ( isset( $roll[ $key ] ) ? (float) $roll[ $key ] : 0.0 ) + $cost, 6 );
	if ( count( $roll ) > SN_AI_SPEND_MONTHS ) {
		ksort( $roll );
		$roll = array_slice( $roll, -SN_AI_SPEND_MONTHS, null, true );
	}
	update_option( SN_AI_SPEND_ROLLUP_OPT, $roll, false );
}

/**
 * v9.26.0: this calendar month's accumulated AI spend in USD (0.0 if none).
 *
 * Reads the durable rollup, so it reflects the whole month regardless of the
 * FIFO usage log's 200-entry cap. Feeds the budget cap and the spend readout.
 *
 * @return float
 * @since 9.26.0
 */
function snt_ai_spend_this_month() {
	$roll = get_option( SN_AI_SPEND_ROLLUP_OPT, array() );
	if ( ! is_array( $roll ) ) {
		return 0.0;
	}
	$key = snt_ai_spend_month_key();
	return isset( $roll[ $key ] ) ? (float) $roll[ $key ] : 0.0;
}
