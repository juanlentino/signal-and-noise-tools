<?php
/**
 * S&N Dashboard, Integrity > Reports: the motion report, mirroring the
 * classic `sn_health_render_motion_report()` (inc/health-render-motion.php).
 *
 * Split out of tools-reports-parts.php (line cap) on 2026-10-11, when the
 * uncovered table came out of its fold (owner rule: no collapsible sections).
 * The card reads top-down: the headline numbers as the finding, then the
 * uncovered declarations as a capped ledger whose "+N more" line counts what
 * the cap cut. Uncovered rows are a report, not findings, so nothing here
 * carries a warning tone.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The motion report's card body.
 *
 * @param array $report The check's `report` payload.
 * @return string
 */
function tools_reports_render_motion( array $report ) {
	$uncovered   = isset( $report['uncovered'] ) && is_array( $report['uncovered'] ) ? $report['uncovered'] : array();
	$scanned     = (int) ( $report['scanned'] ?? 0 );
	$total       = (int) ( $report['motion_total'] ?? 0 );
	$gated       = (int) ( $report['gated'] ?? 0 );
	$neutralized = (int) ( $report['neutralized'] ?? 0 );

	// Unknown is not zero: no readable sheet is "not scanned", never "0 uncovered".
	if ( 0 === $scanned ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( "No front stylesheets were readable, so no motion was scanned. This tier reads the plugin's front-end CSS and the active theme's, the same sheet population as the contrast usage tier.", 'signal-and-noise-tools' ) ) . '</p>';
	}

	// The uncovered count is the TRUE total: the cap trims the ledger, never this.
	$finding = sprintf(
		/* translators: 1: uncovered count, 2: total motion declarations, 3: gated count, 4: neutralized count, 5: stylesheet count */
		__( '%1$d of %2$d declared motions have no reduced-motion counterpart: %3$d gated behind no-preference, %4$d neutralized under reduce, across %5$d stylesheets.', 'signal-and-noise-tools' ),
		count( $uncovered ),
		$total,
		$gated,
		$neutralized,
		$scanned
	);

	if ( empty( $uncovered ) ) {
		return '<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>'
			. '<p class="snt-hint">' . \snt_kit_esc( __( 'Every declared motion has a reduced-motion counterpart: gated behind no-preference or set to none under reduce. Script-driven motion stays outside this tier, as the coverage line says.', 'signal-and-noise-tools' ) ) . '</p>';
	}

	$rows = array();
	foreach ( $uncovered as $row ) {
		$rows[] = array(
			'sheet'    => (string) ( $row['sheet'] ?? '' ),
			'selector' => (string) ( $row['selector'] ?? '' ),
			'kind'     => (string) ( $row['kind'] ?? '' ),
		);
	}
	return \snt_kit_ledger(
		$finding,
		array(
			array( 'key' => 'sheet', 'label' => __( 'Stylesheet', 'signal-and-noise-tools' ) ),
			array( 'key' => 'selector', 'label' => __( 'Selector', 'signal-and-noise-tools' ) ),
			array( 'key' => 'kind', 'label' => __( 'Kind', 'signal-and-noise-tools' ) ),
		),
		$rows,
		TOOLS_REPORTS_ROWS,
		/* translators: %s: uncovered declarations not listed. */
		tools_reports_ledger_opts( __( '+%s more uncovered declarations; the list is capped, not complete.', 'signal-and-noise-tools' ) )
	);
}
