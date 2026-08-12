<?php
/**
 * Signal & Noise Tools -- Health tab: the motion report renderer (IA H3).
 *
 * motion_scan (check #20) shipped report-first with NO detail view — the
 * degrading fallback said so plainly, and the CHANGELOG named the gap as
 * deliberate, awaiting this redesign. This is the view: coverage sentence and
 * headline numbers OPEN, the uncovered table behind an explicit closed
 * <details>, mirroring the H1 shape the contrast card settled on.
 *
 * UNCOVERED ROWS ARE A REPORT, NOT FINDINGS. An uncovered declaration is a
 * measured fact about the stylesheets, not a defect card demanding action —
 * promoting these rows into Findings (or dressing them in a warn pill) would
 * lie about report-only. Fixes land as a later, separate step.
 *
 * Honesty edges, in code below rather than in hope:
 *   - scanned === 0 prints "no front stylesheets were readable", never
 *     "0 uncovered" — unknown is not zero.
 *   - zero uncovered states the measured proportion and stays inside the
 *     declared tier ("every DECLARED motion…"), because script-driven motion
 *     is invisible to a stylesheet reader and the coverage sentence says so.
 *   - the table cap truncates the LIST, never the headline: the uncovered
 *     count in the headline and the summary is always the true total, and the
 *     remainder line names what was cut.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row cap for the uncovered table — the same ceiling the findings tables use,
 * because the failure mode (an unbounded wall) is the same even though the
 * semantics (report, not findings) are not.
 */
if ( ! defined( 'SN_HEALTH_MOTION_MAX_ROWS' ) ) {
	define( 'SN_HEALTH_MOTION_MAX_ROWS', 50 );
}

/**
 * The motion report: headline numbers open, uncovered table folded.
 *
 * Payload (inc/health-motion-scan.php):
 *   {scanned, motion_total, gated, neutralized,
 *    uncovered[] {sheet, selector, kind}, coverage}
 * The coverage sentence is printed by the Reports section chrome before this
 * renderer runs, so it is not repeated here.
 *
 * @param array $report The check's `report` payload.
 */
function sn_health_render_motion_report( $report ) {
	$uncovered   = isset( $report['uncovered'] ) && is_array( $report['uncovered'] ) ? $report['uncovered'] : array();
	$scanned     = (int) ( $report['scanned'] ?? 0 );
	$total       = (int) ( $report['motion_total'] ?? 0 );
	$gated       = (int) ( $report['gated'] ?? 0 );
	$neutralized = (int) ( $report['neutralized'] ?? 0 );

	if ( 0 === $scanned ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'No front stylesheets were readable, so no motion was scanned. This tier reads the plugin\'s front-end CSS and the active theme\'s — the same sheet population as the contrast usage tier.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	// The headline: every number the producer measured, none presented as a
	// pass. The uncovered count here is the TRUE total — the cap below trims
	// the table, never this sentence.
	echo '<p class="sn-health-report__headline">';
	printf(
		/* translators: 1: uncovered count, 2: total motion declarations, 3: gated count, 4: neutralized count, 5: stylesheet count */
		esc_html__( '%1$d of %2$d declared motions have no reduced-motion counterpart — %3$d gated behind no-preference, %4$d neutralized under reduce, across %5$d stylesheets.', 'signal-and-noise-tools' ),
		count( $uncovered ),
		(int) $total,
		(int) $gated,
		(int) $neutralized,
		(int) $scanned
	);
	echo '</p>';

	if ( empty( $uncovered ) ) {
		// Covered, in the declared tier's own words — "the site shows no
		// motion" would claim what a stylesheet reader cannot see.
		echo '<p class="sn-field-helper">' . esc_html__( 'Every declared motion has a reduced-motion counterpart — gated behind no-preference or set to none under reduce. Script-driven motion stays outside this tier, as the coverage line says.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}

	$visible = array_slice( $uncovered, 0, SN_HEALTH_MOTION_MAX_ROWS );
	$hidden  = count( $uncovered ) - count( $visible );

	// The fold: closed by default, the summary restating the count so the
	// fold can hide the evidence but never THAT there is something inside.
	echo '<details class="sn-health-motion-uncovered sn-disclosure"><summary>';
	printf(
		/* translators: %d: uncovered declaration count. */
		esc_html( _n( 'Show the %d uncovered declaration', 'Show the %d uncovered declarations', count( $uncovered ), 'signal-and-noise-tools' ) ),
		count( $uncovered )
	);
	echo '</summary>';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped snt-mt-half"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Stylesheet', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-55">' . esc_html__( 'Selector', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col" class="snt-col-90px">' . esc_html__( 'Kind', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $visible as $row ) {
		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $row['sheet'] ?? '' ) ) . '</td>';
		echo '<td><code>' . esc_html( (string) ( $row['selector'] ?? '' ) ) . '</code></td>';
		// The kinds are SEPARATE claims — a transition reset silences no
		// keyframe animation — so the kind is data, not decoration.
		echo '<td><code>' . esc_html( (string) ( $row['kind'] ?? '' ) ) . '</code></td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';

	if ( $hidden > 0 ) {
		echo '<p class="sn-field-helper">';
		printf(
			/* translators: %d: hidden row count */
			esc_html__( '+%d more uncovered declarations — the list is capped, not complete.', 'signal-and-noise-tools' ),
			(int) $hidden
		);
		echo '</p>';
	}
	echo '</details>';
}
