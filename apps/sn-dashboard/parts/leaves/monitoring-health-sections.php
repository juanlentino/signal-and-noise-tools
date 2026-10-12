<?php
/**
 * S&N Dashboard, Monitoring > Health: the check sections, open.
 *
 * Moved out of monitoring-health-parts.php when the folds came out
 * (2026-10-11, owner rule: no collapsible sections). Each section reads top
 * down: the finding sentence, then the cards, the facts or the chips. The
 * passing checks and the checks that could not run used to sit behind a
 * disclosure; every figure they carried is painted here, open. The check card
 * (whose advisory rows were folded too) lives with its rows in
 * monitoring-health-rows.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The findings: faults grouped by family, advisories after them, the groups
 * `sn_health_render_findings_section()` paints. One `<os-section>` per family
 * and one for the advisories, its hint as the section's description: the
 * dashboard sheet boxes every section body, so a family section inside a
 * Findings section would be a box in a box (#1600).
 *
 * @param array<string,array> $faults       Non-advisory checks with findings.
 * @param array<string,array> $advisories   Advisory-tier checks with findings.
 * @param bool                $ai_available Whether an AI provider is configured.
 * @return string
 */
function health_findings_html( array $faults, array $advisories, $ai_available ) {
	if ( empty( $faults ) && empty( $advisories ) ) {
		return '';
	}
	$out     = '';
	$grouped = function_exists( 'sn_health_group_checks_by_family' )
		? sn_health_group_checks_by_family( $faults )
		: array( 'other' => array( 'label' => __( 'Other checks', 'signal-and-noise-tools' ), 'checks' => $faults ) );
	foreach ( $grouped as $family ) {
		if ( empty( $family['checks'] ) ) {
			continue;
		}
		$cards = '';
		foreach ( $family['checks'] as $key => $check ) {
			$cards .= health_finding_card_html( $key, $check, false, $ai_available );
		}
		$out .= \snt_kit_section( (string) $family['label'], $cards );
	}
	if ( ! empty( $advisories ) ) {
		$cards = '';
		foreach ( $advisories as $key => $check ) {
			$cards .= health_finding_card_html( $key, $check, true, $ai_available );
		}
		$out .= \snt_kit_section(
			__( 'Advisories', 'signal-and-noise-tools' ),
			$cards,
			__( 'Surfaced, never alarming: these do not count toward the findings total, and a clean site can carry them indefinitely.', 'signal-and-noise-tools' )
		);
	}
	return $out;
}

/**
 * The passing checks, open: the classic summary line ("17 of 18 checks
 * passing") as the finding, then one fact per family whose value is a
 * cluster of chips, the spec-sheet band.
 *
 * @param array<string,array> $passing      From sn_health_passing_checks().
 * @param int                 $check_total  From sn_health_check_total().
 * @param int                 $report_count Count of report-only checks.
 * @return string
 */
function health_passing_html( array $passing, $check_total, $report_count ) {
	if ( empty( $passing ) ) {
		return '';
	}
	$summary = function_exists( 'sn_health_passing_summary_text' )
		? sn_health_passing_summary_text( count( $passing ), $check_total, $report_count )
		/* translators: 1: passing count, 2: check total */
		: sprintf( __( '%1$d of %2$d checks passing', 'signal-and-noise-tools' ), count( $passing ), $check_total );
	$grouped = function_exists( 'sn_health_group_checks_by_family' )
		? sn_health_group_checks_by_family( $passing )
		: array( 'other' => array( 'label' => __( 'Other checks', 'signal-and-noise-tools' ), 'checks' => $passing ) );

	$rows = array();
	foreach ( $grouped as $family ) {
		$chips = '';
		foreach ( $family['checks'] as $check ) {
			$chips .= \snt_kit_chip( (string) ( $check['label'] ?? '' ) );
		}
		$rows[] = array( 'label' => (string) $family['label'], 'value' => '<os-cluster gap="6">' . $chips . '</os-cluster>', 'html' => true );
	}
	/* translators: 1: the passing summary ("17 of 18 checks passing"), 2: family count */
	$finding = sprintf( _n( '%1$s, across %2$d family.', '%1$s, across %2$d families.', count( $rows ), 'signal-and-noise-tools' ), $summary, count( $rows ) );
	return \snt_kit_section( __( 'Passing checks', 'signal-and-noise-tools' ), '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>' . \snt_kit_kv( $rows ) );
}

/**
 * The checks that could not run, open: each one reads "not measured" with its
 * reason and its fix hint. Unknown is never zero and never a pass.
 *
 * @param array<string,array> $skipped From sn_health_skipped_checks().
 * @return string
 */
function health_skipped_html( array $skipped ) {
	$skipped = array_filter( $skipped, 'is_array' );
	if ( empty( $skipped ) ) {
		return '';
	}
	$count   = count( $skipped );
	$finding = sprintf(
		/* translators: %s: number of checks that could not run */
		_n( '%s check produced no evidence either way this scan, so it reads not measured and is not counted as passed.', '%s checks produced no evidence either way this scan, so they read not measured and are not counted as passed.', $count, 'signal-and-noise-tools' ),
		number_format_i18n( $count )
	);
	$rows = array();
	foreach ( $skipped as $check ) {
		$value = \snt_kit_badge( 'neutral', __( 'not measured', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( (string) ( $check['skipped'] ?? '' ) );
		$hint  = trim( (string) ( $check['fix_hint'] ?? '' ) );
		if ( '' !== $hint ) {
			$value .= '<br><span class="snt-hint">' . \snt_kit_esc( $hint ) . '</span>';
		}
		$rows[] = array( 'label' => (string) ( $check['label'] ?? '' ), 'value' => $value, 'html' => true );
	}
	return \snt_kit_section( __( 'Could not run', 'signal-and-noise-tools' ), '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>' . \snt_kit_kv( $rows ) );
}
