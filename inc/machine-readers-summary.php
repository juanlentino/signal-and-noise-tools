<?php
/**
 * Signal & Noise Tools — Machine Readers: the ONE summary builder.
 *
 * v10.2.0. Both the desktop tile route (inc/desktop-mode-integration.php) and
 * the signal-noise/get-machine-readers-summary ability read this, so neither
 * can drift when the other gains a field — the fork the first draft of the
 * ability shipped, and its own review caught.
 *
 * Depends on snt_mr_fetch() but does NOT declare it, so a fixture can stub the
 * fetch and still drive the real aggregation.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Top families a summary carries. A glance, not the full table. */
const SN_MR_TOP_FAMILIES = 3;

/**
 * Build the Machine Readers glance for an arbitrary window.
 *
 * The rebuild half of the delegation above: same helpers, same aggregation,
 * same failure contract. Pure with respect to its inputs: the fetched rows
 * are read, never written back.
 *
 * @param int $days Window, already clamped to 1-90.
 * @return array Payload; ok:false + error + days when the sensor did not answer.
 */
function snt_mr_summary_payload( $days ) {
	$days = (int) $days;
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		// The module is optional at the file level; say so rather than 500.
		return array( 'ok' => false, 'error' => 'unavailable', 'days' => $days );
	}

	$result = snt_mr_fetch( $days );
	if ( empty( $result['ok'] ) ) {
		return array(
			'ok'    => false,
			'error' => (string) ( $result['error'] ?? 'unknown' ),
			'days'  => $days,
		);
	}

	$rows    = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
	$totals  = function_exists( 'snt_mr_sum_hits_by' ) ? snt_mr_sum_hits_by( $rows, 'family' ) : array();
	$ai_set  = function_exists( 'snt_mr_ai_training_families' ) ? snt_mr_ai_training_families() : array();
	$total   = 0;
	$ai_hits = 0;
	$ai_rght = 0;
	foreach ( $rows as $row ) {
		$hits   = (int) ( $row['hits'] ?? 0 );
		$total += $hits;
		if ( in_array( (string) ( $row['family'] ?? '' ), $ai_set, true ) ) {
			$ai_hits += $hits;
			// The compliance read: a declared AI-training crawler that fetched
			// the rights surface at least saw the terms it is bound by.
			if ( 'rights' === (string) ( $row['surface'] ?? '' ) ) {
				$ai_rght += $hits;
			}
		}
	}

	$families = array();
	foreach ( $totals as $family => $hits ) {
		$families[] = array( 'family' => (string) $family, 'hits' => (int) $hits );
		if ( count( $families ) >= SN_MR_TOP_FAMILIES ) {
			break;
		}
	}

	// Provenance: the two public sensor documents. Either can fail on its own,
	// and a failure is null (unknown), never an invented verdict.
	$info    = function_exists( 'snt_mr_sensor_info' ) ? snt_mr_sensor_info() : null;
	$status  = function_exists( 'snt_mr_crawler_list_status' ) ? snt_mr_crawler_list_status() : null;
	$verdict = null;
	if ( is_array( $status ) && isset( $status['last_check_ok'] ) ) {
		$c_ok    = '' !== (string) $status['last_check_ok'];
		$c_drift = '' !== (string) ( $status['last_check_drift'] ?? '' );
		$verdict = $c_ok ? ( $c_drift ? 'drift' : 'in sync' ) : 'check failed';
	}

	return array(
		'ok'             => true,
		'days'           => $days,
		'total'          => $total,
		'families'       => $families,
		'ai_training'    => $ai_hits,
		'ai_rights'      => $ai_rght,
		'sensor_version' => ( is_array( $info ) && isset( $info['version'] ) ) ? (string) $info['version'] : null,
		'crawler_list'   => $verdict,
	);
}
