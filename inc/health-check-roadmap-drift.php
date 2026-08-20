<?php
/**
 * Signal & Noise Tools — Health check: roadmap board merge conflicts.
 *
 * The board has two writers (code and sn_apply). Where both have moved the same
 * (family, column) cell, the override renders and code's edit does NOT land —
 * deliberately, because an install must not silently revert authored copy. That
 * is a decision a person has to make, so it is reported here rather than
 * settled quietly.
 *
 * Silent when there are no conflicts: this is a defects-only surface.
 *
 * @package SignalNoiseTools
 * @since 12.6.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Findings for a merge report. Pure: takes the report, returns strings.
 *
 * @param array $report A merge report (reads `conflicts` and `invalid`).
 * @return string[]
 */
function snt_roadmap_drift_findings( array $report ) {
	$out = array();
	// The validation fallback serves the static board and reports zero
	// conflicts. Silent reversion is the defect this feature exists to remove,
	// so it gets its own finding.
	if ( ! empty( $report['invalid'] ) ) {
		$out[] = 'Roadmap board: the merged board failed validation, so the code board is being served and the saved override is NOT rendering. Nothing else on this page reflects that.';
	}
	foreach ( (array) ( $report['conflicts'] ?? array() ) as $c ) {
		$out[] = sprintf(
			'Roadmap board: "%s" / %s was changed in BOTH the code board and the saved override. The override is what renders; the code edit has not landed.',
			(string) ( $c['family'] ?? '?' ),
			(string) ( $c['column'] ?? '?' )
		);
	}
	return $out;
}

/**
 * The Health check.
 *
 * sn_health_pack_check() takes one fix_hint for the whole check, but the two
 * findings above call for different fixes: a cell conflict is reconciled by
 * re-writing through sn_apply or editing the static board, while `invalid`
 * has no cell to point at — the merged board itself failed validation, so
 * the fix is to the override or the static board's SHAPE, not a sentence.
 * Branch on which fired rather than write one hint vague enough to cover
 * both — `invalid` firing implies the merge is broken, so its hint takes
 * priority when both are somehow present at once.
 *
 * @return array
 */
function sn_health_check_roadmap_drift() {
	$report   = sn_maturity_roadmap_effective_report();
	$findings = snt_roadmap_drift_findings( $report );
	$hint     = ! empty( $report['invalid'] )
		? 'The merged board failed validation and is not being served. Inspect the saved override (sn_apply\'s roadmap_board change type, dry-run first) against sn_maturity_roadmap_board_problems() and either correct it or reset:true to drop it back to the static board.'
		: 'Reconcile the cell: re-write the board through sn_apply to accept code\'s wording, or edit the static board to match the override. sn_apply reset:true drops the override entirely.';
	return sn_health_pack_check( 'Roadmap board drift', $findings, $hint );
}
