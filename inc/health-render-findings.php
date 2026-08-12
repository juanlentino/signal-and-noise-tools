<?php
/**
 * Signal & Noise Tools -- Health tab: the Findings section (IA increment H5).
 *
 * Extracted from inc/health-checks-admin.php when the loop grew a second
 * shape. Two changes came with the move, and both are about a reader's
 * question rather than a producer's convenience:
 *
 * 1. FAULT CARDS GROUP BY FAMILY. They used to print in scan-registry order,
 *    which is chronological-by-ship-date — meaningful to whoever added the
 *    checks, meaningless to someone asking "is the rights surface dirty?".
 *    They now sit under family headings in the canonical family order the
 *    passing disclosure already uses, so the two halves of the tab answer to
 *    the same spine.
 *
 * 2. ADVISORIES STOP IMPERSONATING FAULTS. external_links and
 *    link_opportunities are advisory tier (owner re-tier 2026-07-02):
 *    surfaced, never alarming, and already excluded from the hero's alarm
 *    calculus. On this tab they nevertheless rendered as an identical warn-
 *    pilled card with an identical open 50-row table, so the page contradicted
 *    the tiering it already had. They now sit under their own subhead, after
 *    every fault family, with a NEUTRAL chip, the word "advisory" instead of
 *    "finding", and their table behind a closed disclosure.
 *
 * WHAT DID NOT CHANGE: no row is dropped, no check is relocated to another
 * leaf, the 50-row cap and its remainder line stay, the Suggest-all wiring
 * stays on the same seven keys, and sn_health_finding_total() is untouched —
 * so no other surface has to be re-derived.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render every check that reported findings: faults grouped by family, then
 * advisories folded under their own subhead.
 *
 * @param array<string,array> $with_findings key => check envelope, scan order.
 * @param bool                $ai_available  Whether the AI suggest column shows.
 * @param string[]            $suggest_keys  Check keys that support Suggest.
 */
function sn_health_render_findings_section( $with_findings, $ai_available, $suggest_keys ) {
	$with_findings = (array) $with_findings;
	if ( empty( $with_findings ) ) {
		return;
	}

	$advisory_keys = function_exists( 'sn_health_advisory_checks' ) ? sn_health_advisory_checks() : array();
	$faults        = array();
	$advisories    = array();
	foreach ( $with_findings as $key => $check ) {
		if ( in_array( (string) $key, $advisory_keys, true ) ) {
			$advisories[ $key ] = $check;
		} else {
			$faults[ $key ] = $check;
		}
	}

	echo '<h2 class="sn-section-h">Findings</h2>';
	// v6.47.0: scope a full-width uncap to the findings cards only (NOT the
	// short scan form above), so the wide 4-column finding tables use the page
	// width instead of staying 820px-capped with dead space beside them.
	echo '<div class="sn-health-findings">';

	// Faults, by family, in canonical family order. Empty families are omitted
	// by the shared helper, so only groups that exist print a heading.
	$grouped = function_exists( 'sn_health_group_checks_by_family' )
		? sn_health_group_checks_by_family( $faults )
		: array( 'other' => array( 'label' => 'Other checks', 'checks' => $faults ) );

	foreach ( $grouped as $family ) {
		if ( empty( $family['checks'] ) ) {
			continue;
		}
		echo '<h3 class="sn-health-findings__family">' . esc_html( (string) $family['label'] ) . '</h3>';
		foreach ( $family['checks'] as $key => $check ) {
			sn_health_render_finding_card( $key, $check, $ai_available, $suggest_keys, false );
		}
	}

	// Advisories last: they ask for attention, not action.
	if ( ! empty( $advisories ) ) {
		echo '<h3 class="sn-health-findings__family">' . esc_html__( 'Advisories', 'signal-and-noise-tools' ) . '</h3>';
		echo '<p class="sn-field-helper">' . esc_html__( 'Surfaced, never alarming: these do not count toward the findings total above, and a clean site can carry them indefinitely.', 'signal-and-noise-tools' ) . '</p>';
		foreach ( $advisories as $key => $check ) {
			sn_health_render_finding_card( $key, $check, $ai_available, $suggest_keys, true );
		}
	}

	echo '</div>'; // .sn-health-findings
}

/**
 * One check's card. Identical anatomy either tier; what changes is the chip
 * (neutral vs warn), the noun ("advisory" vs "finding"), and whether the table
 * sits behind a disclosure.
 *
 * @param string   $key           Check key.
 * @param array    $check         Check envelope.
 * @param bool     $ai_available  Whether AI suggestions are available.
 * @param string[] $suggest_keys  Keys supporting Suggest.
 * @param bool     $is_advisory   Advisory tier.
 */
function sn_health_render_finding_card( $key, $check, $ai_available, $suggest_keys, $is_advisory ) {
	$count = (int) ( $check['count'] ?? 0 );
	$show_ai_col = $ai_available && in_array( $key, (array) $suggest_keys, true );

	echo '<div class="sn-fieldset">';

	echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
	echo esc_html( (string) ( $check['label'] ?? $key ) );
	if ( $is_advisory ) {
		// Base .sn-pill is the neutral chip. An advisory wearing --warn is the
		// exact thing that made the two tiers indistinguishable on this tab.
		echo '<span class="sn-pill">' . esc_html( sprintf( /* translators: %d: advisory count. */ _n( '%d advisory', '%d advisories', $count, 'signal-and-noise-tools' ), $count ) ) . '</span>';
	} else {
		echo '<span class="sn-pill sn-pill--warn">' . esc_html( $count ) . ' finding' . ( 1 === $count ? '' : 's' ) . '</span>';
	}
	if ( $show_ai_col ) {
		echo snt_health_suggest_all_button_html( $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped markup.
	}
	echo '</h2>';

	if ( ! empty( $check['fix_hint'] ) ) {
		echo '<p class="sn-fieldset-intro">' . esc_html( (string) $check['fix_hint'] ) . '</p>';
	}

	if ( $is_advisory ) {
		echo '<details class="sn-health-advisory sn-disclosure"><summary>';
		echo esc_html( sprintf( /* translators: %d: advisory count. */ _n( 'Show the %d advisory', 'Show the %d advisories', $count, 'signal-and-noise-tools' ), $count ) );
		echo '</summary>';
	}

	// Cap visible rows at 50.
	$findings = isset( $check['findings'] ) && is_array( $check['findings'] ) ? $check['findings'] : array();
	$visible  = array_slice( $findings, 0, 50 );
	$hidden   = count( $findings ) - count( $visible );

	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col" class="' . ( $show_ai_col ? 'snt-col-40' : 'snt-col-55' ) . '">Subject</th>';
	echo '<th scope="col">Note</th>';
	echo '<th scope="col" class="snt-col-90px">Action</th>';
	if ( $show_ai_col ) {
		echo '<th scope="col" class="snt-col-280px">' . esc_html__( 'AI fix', 'signal-and-noise-tools' ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $visible as $f ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $f['subject_label'] ) . '</code>';
		if ( ! empty( $f['subject_url'] ) ) {
			echo '<br><small><a href="' . esc_url( $f['subject_url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $f['subject_url'] ) . '</a></small>';
		}
		echo '</td>';
		echo '<td>' . esc_html( (string) ( $f['note'] ?? '' ) ) . '</td>';
		echo '<td>';
		if ( ! empty( $f['edit_url'] ) ) {
			echo '<a href="' . esc_url( $f['edit_url'] ) . '" class="button button-small">Edit</a>';
		}
		echo '</td>';
		if ( $show_ai_col ) {
			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sn_health_render_suggest_cell() returns markup with every attribute esc_attr-escaped and the label esc_html-escaped.
			echo sn_health_render_suggest_cell( $key, $f );
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';

	if ( $hidden > 0 ) {
		echo '<p class="sn-field-helper">+' . (int) $hidden . ' more ' . ( $is_advisory ? 'advisories' : 'findings' ) . ': re-run scan after fixing the top batch.</p>';
	}

	if ( $is_advisory ) {
		echo '</details>';
	}

	echo '</div>'; // .sn-fieldset
}
