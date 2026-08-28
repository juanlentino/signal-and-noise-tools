<?php
/**
 * Signal & Noise — AI bootstrap: the monthly spend rollup.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins the layer's declaration count,
 * the SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_spend_month_key(), snt_ai_add_month_spend(),
 * snt_ai_spend_this_month(), snt_ai_add_month_feature_spend(),
 * snt_ai_spend_this_month_by_feature()
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

/**
 * v13.21.0: fold one call's USD cost into the per-feature monthly rollup.
 *
 * A sibling of snt_ai_add_month_spend() with one more dimension: a single
 * autoload=no option keyed YYYY-MM => feature => USD, pruned to the same
 * SN_AI_SPEND_MONTHS window. Deliberately a SEPARATE option: the month-total
 * rollup's flat shape feeds the budget gate, and adding a dimension there
 * would be a schema change to a load-bearing option. Same zero/negative
 * guard, same rounding, fed the same cost at the same call site, so the
 * feature buckets sum to the month total.
 *
 * @param string $feature Feature label as passed to snt_ai_record_usage().
 * @param float  $cost    USD cost of the call.
 * @return void
 * @since 13.21.0
 */
function snt_ai_add_month_feature_spend( $feature, $cost ) {
	$cost = (float) $cost;
	if ( $cost <= 0 ) {
		return;
	}
	$feature = (string) $feature;
	if ( '' === $feature ) {
		$feature = 'generic'; // generate.php's own default label.
	}
	$roll = get_option( SN_AI_SPEND_FEATURE_OPT, array() );
	if ( ! is_array( $roll ) ) {
		$roll = array();
	}
	$key = snt_ai_spend_month_key();
	if ( ! isset( $roll[ $key ] ) || ! is_array( $roll[ $key ] ) ) {
		$roll[ $key ] = array();
	}
	$prev                     = isset( $roll[ $key ][ $feature ] ) ? (float) $roll[ $key ][ $feature ] : 0.0;
	$roll[ $key ][ $feature ] = round( $prev + $cost, 6 );
	if ( count( $roll ) > SN_AI_SPEND_MONTHS ) {
		ksort( $roll );
		$roll = array_slice( $roll, -SN_AI_SPEND_MONTHS, null, true );
	}
	update_option( SN_AI_SPEND_FEATURE_OPT, $roll, false );
}

/**
 * This calendar month's AI spend itemized by feature, highest first.
 *
 * Returns array<string,float> of feature => USD, or an EMPTY array when the
 * month holds nothing — absence, not zeros, so a renderer can skip the whole
 * section (the spend-watch unconfigured-is-absent precedent). Only positive
 * buckets survive: a zero here could only be shape damage, never a recorded
 * spend (the fold above refuses cost <= 0).
 *
 * @return array<string,float>
 * @since 13.21.0
 */
function snt_ai_spend_this_month_by_feature() {
	$roll = get_option( SN_AI_SPEND_FEATURE_OPT, array() );
	if ( ! is_array( $roll ) ) {
		return array();
	}
	$key   = snt_ai_spend_month_key();
	$month = isset( $roll[ $key ] ) && is_array( $roll[ $key ] ) ? $roll[ $key ] : array();
	$out   = array();
	foreach ( $month as $feature => $cost ) {
		$cost = (float) $cost;
		if ( $cost > 0 ) {
			$out[ (string) $feature ] = $cost;
		}
	}
	arsort( $out );
	return $out;
}
