<?php
/**
 * Signal & Noise Tools -- Health tab: the passing-checks disclosure.
 *
 * WAS (v8.0.1): one open strip listing every clean check as a chip. At 10
 * checks that read as reassurance; at 19 it is a flat wall the eye cannot
 * parse, and it sits between the reader and the findings they came for.
 *
 * NOW (v10.83.0): a <details> collapsed by default. The <summary> carries the
 * whole message a healthy site needs ("17 of 19 checks passing"), and the
 * names live one click away, grouped by family so an expanded list answers
 * "is the rights surface clean" without a chip hunt.
 *
 * THE DENOMINATOR IS NOT SHRUNK. sn_health_check_total() still counts every
 * check the scan ran, on every surface. Report-only checks are simply not
 * counted as PASSING (they cannot fail, so "pass" is not a verdict they can
 * earn) and the difference is named in the summary line -- "17 of 19 checks
 * passing · 1 report-only" -- rather than silently absorbed. Naming the gap
 * beats re-deriving a second denominator that four other surfaces would then
 * disagree with.
 *
 * <details> gives keyboard and screen-reader disclosure semantics for free;
 * a div plus JS toggle would owe aria-expanded, focus handling, and a no-JS
 * fallback. Progressive enhancement is the default, not the upgrade.
 *
 * @package SignalNoiseTools
 * @since 10.83.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the summary line for the passing disclosure.
 *
 * @param int $passing_count Checks that passed (report-only excluded).
 * @param int $check_total   Every check the scan ran.
 * @param int $report_count  Report-only checks.
 * @return string Plain text, caller escapes.
 * @since 10.83.0
 */
function sn_health_passing_summary_text( $passing_count, $check_total, $report_count ) {
	$passing_count = (int) $passing_count;
	$check_total   = (int) $check_total;
	$report_count  = (int) $report_count;

	$text = ( $passing_count === $check_total )
		? sprintf( 'All %d check%s passing', $passing_count, 1 === $passing_count ? '' : 's' )
		: sprintf( '%d of %d checks passing', $passing_count, $check_total );

	if ( $report_count > 0 ) {
		$text .= sprintf( ' · %d report-only', $report_count );
	}
	return $text;
}

/**
 * Render the collapsed passing-checks disclosure. Emits nothing when no check
 * passed -- an empty "0 of 19 passing" card is noise on a site already showing
 * a full findings column.
 *
 * @param array<string,array> $passing      key => check envelope (sn_health_passing_checks()).
 * @param int                 $check_total  sn_health_check_total().
 * @param int                 $report_count Count of report-only checks.
 * @since 10.83.0
 */
function sn_health_render_passing_section( $passing, $check_total, $report_count = 0 ) {
	$passing = (array) $passing;
	if ( empty( $passing ) ) {
		return;
	}

	$summary = sn_health_passing_summary_text( count( $passing ), (int) $check_total, (int) $report_count );

	echo '<details class="sn-fieldset sn-health-passing">';
	echo '<summary class="sn-health-passing__summary">';
	echo '<span class="sn-health-passing__title">' . esc_html( $summary ) . '</span>';
	echo '<span class="sn-pill sn-pill--ok">pass</span>';
	echo '</summary>';

	foreach ( sn_health_group_checks_by_family( $passing ) as $family_key => $family ) {
		echo '<div class="sn-health-passing__family">';
		echo '<h3 class="sn-health-passing__family-label">' . esc_html( (string) $family['label'] ) . '</h3>';
		echo '<p class="sn-health-passing__names">';
		foreach ( $family['checks'] as $check ) {
			echo '<span class="sn-badge">' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</span>';
		}
		echo '</p>';
		echo '</div>';
	}

	echo '</details>';
}

/**
 * The checks that could not run, each with the reason it could not.
 *
 * WHY THIS IS ITS OWN SECTION. These used to be counted as passes and printed
 * inside "passing", so the page asserted that a check had cleared when it had
 * never executed. A count in the meta line fixes the arithmetic but still
 * leaves the reader knowing only that something is missing; naming the check
 * and its reason is what makes it actionable — "AI provider not configured"
 * tells you where to go, "1 skipped" does not.
 *
 * Deliberately NOT styled as an error. A skipped check is a gap in evidence,
 * not a defect: the same principle health-check-ledger-ci.php words as "an
 * outage is a gap in evidence, not a red ledger". It renders in the neutral
 * register so the colour on this page keeps meaning "something is wrong".
 *
 * @since 11.33.0
 * @param array<string,array> $skipped From sn_health_skipped_checks().
 * @return void
 */
function sn_health_render_skipped_section( $skipped ) {
	$skipped = (array) $skipped;
	if ( empty( $skipped ) ) {
		return; // Nothing to explain, so nothing is drawn.
	}

	echo '<details class="sn-fieldset sn-health-passing sn-health-skipped">';
	echo '<summary class="sn-health-passing__summary">';
	echo '<span class="sn-health-passing__title">' . esc_html(
		sprintf(
			/* translators: %d number of checks that could not run */
			_n( '%d check could not run', '%d checks could not run', count( $skipped ), 'signal-and-noise-tools' ),
			count( $skipped )
		)
	) . '</span>';
	echo '<span class="sn-pill">not measured</span>';
	echo '</summary>';

	echo '<p class="description">These produced no evidence either way this scan. They are not counted as passed.</p>';

	echo '<ul class="sn-health-skipped__list">';
	foreach ( $skipped as $check ) {
		if ( ! is_array( $check ) ) {
			continue;
		}
		echo '<li>';
		echo '<b>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</b> — ';
		echo esc_html( (string) ( $check['skipped'] ?? '' ) );
		$hint = trim( (string) ( $check['fix_hint'] ?? '' ) );
		if ( '' !== $hint ) {
			echo '<br><span class="description">' . esc_html( $hint ) . '</span>';
		}
		echo '</li>';
	}
	echo '</ul>';

	echo '</details>';
}
