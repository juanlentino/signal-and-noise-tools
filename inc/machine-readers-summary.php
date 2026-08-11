<?php
/**
 * Signal & Noise Tools — Machine Readers: the ONE summary builder.
 *
 * v10.2.0. Both the desktop tile route (inc/desktop-mode-payloads.php) and
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

	// v10.27.0: the per-surface split for declared AI-training families. The
	// family-level ai_training/ai_rights pair above cannot answer "did AI
	// crawlers fetch robots.txt here" — a widget '348' figure turned out to be
	// the search FAMILY total, not the robots SURFACE. This groups the SAME
	// ai_training rows (never a different family set) by their already-
	// normalized surface class (inc/machine-readers-api.php's fixed enum), so
	// it can only ever report surfaces the module already distinguishes.
	$ai_surfaces = array();
	if ( function_exists( 'snt_mr_sum_hits_by' ) ) {
		$ai_rows = array();
		foreach ( $rows as $row ) {
			if ( in_array( (string) ( $row['family'] ?? '' ), $ai_set, true ) ) {
				$ai_rows[] = $row;
			}
		}
		foreach ( snt_mr_sum_hits_by( $ai_rows, 'surface' ) as $surface => $hits ) {
			$ai_surfaces[] = array( 'surface' => (string) $surface, 'hits' => (int) $hits );
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

	// v10.79.0: the purpose axis rides the same payload. ADDITIVE — every field
	// above keeps its exact meaning, because agents and the Desktop tile read
	// them and `ai_training` in particular is the family-based figure a
	// published note already used.
	//
	// ai_training_by_purpose is the HONEST count and is deliberately reported
	// beside ai_training rather than replacing it: the frozen families match
	// GoogleOther, MistralAI-Index and MistralAI-User, which their vendors
	// document as generic, index-building and user-directed. A consumer that
	// wants the defensible number reads the purpose field; the gap between them
	// is the over-count, and it is visible rather than reconciled away.
	$purposes    = array();
	$train_hits  = 0;
	$first_party = 0;
	$tax_version = '';
	$has_tax     = function_exists( 'snt_mr_taxonomy_absent' ) && ! snt_mr_taxonomy_absent( $rows );
	if ( $has_tax ) {
		foreach ( $rows as $row ) {
			// Reported from the DATA, never a literal: rows carry the version
			// they were written under, so a window spanning a definition change
			// is visibly mixed instead of stamped with today's number.
			$row_version = (string) ( $row['taxonomy_version'] ?? '' );
			if ( '' !== $row_version && $row_version !== $tax_version ) {
				$tax_version = '' === $tax_version ? $row_version : 'mixed';
			}
			$hits = (int) ( $row['hits'] ?? 0 );
			if ( ! empty( $row['first_party'] ) ) {
				$first_party += $hits;
				continue;
			}
			$p              = (string) ( $row['purpose'] ?? 'unknown' );
			$purposes[ $p ] = (int) ( $purposes[ $p ] ?? 0 ) + $hits;
			if ( 'train' === $p ) {
				$train_hits += $hits;
			}
		}
		arsort( $purposes );
	}
	$purpose_rows = array();
	foreach ( $purposes as $p => $hits ) {
		$purpose_rows[] = array( 'purpose' => (string) $p, 'hits' => (int) $hits );
	}

	return array(
		'ok'             => true,
		'days'           => $days,
		'total'          => $total,
		'families'       => $families,
		'ai_training'    => $ai_hits,
		'ai_rights'      => $ai_rght,
		'ai_surfaces'    => $ai_surfaces,
		// null, not 0, when the deployed sensor predates the taxonomy: a
		// consumer must be able to tell never-measured from measured-zero.
		'purposes'       => $has_tax ? $purpose_rows : null,
		'ai_training_by_purpose' => $has_tax ? $train_hits : null,
		'first_party'    => $has_tax ? $first_party : null,
		'taxonomy'       => ( $has_tax && '' !== $tax_version ) ? $tax_version : null,
		'sensor_version' => ( is_array( $info ) && isset( $info['version'] ) ) ? (string) $info['version'] : null,
		'crawler_list'   => $verdict,
	);
}
