<?php
/**
 * Signal & Noise Tools — health check 30: notes Jev would not search for.
 *
 * Reads the stored daily pass (inc/jev-notes.php); never calls Jev at scan
 * time. A finding needs BOTH a low rubric position AND a confidence at or
 * above the floor; a note Jev is unsure about is counted as unsure, never as
 * a finding (the confidence page: never act below 0.5; act above ~0.9). No
 * key, or no pass yet, reads skipped.
 *
 * @since 16.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rubric position at or below which the search title is a finding (of 1..3). */
const SN_JEV_TITLE_FINDING_MAX = 1.5;
/** Rubric position at or below which the description is a finding (of 1..3). */
const SN_JEV_DESCRIPTION_FINDING_MAX = 1.5;

/**
 * Judge the stored notes. PURE.
 *
 * @since 16.3.0
 * @param array $notes {id: {title, verdict|null, at, error}}.
 * @return array{findings:array,unsure:int,judged:int}
 */
function sn_health_jev_notes_judge( $notes ) {
	$findings = array();
	$unsure   = 0;
	$judged   = 0;
	foreach ( (array) $notes as $id => $n ) {
		if ( ! is_array( $n ) || ! is_array( $n['verdict'] ?? null ) ) {
			continue;
		}
		$judged++;
		$v     = $n['verdict'];
		$notes_out = array();
		foreach ( array( 'title' => SN_JEV_TITLE_FINDING_MAX, 'description' => SN_JEV_DESCRIPTION_FINDING_MAX ) as $field => $max ) {
			$f = $v[ $field ] ?? array();
			if ( (float) ( $f['score'] ?? 3 ) <= $max ) {
				if ( ! empty( $f['sure'] ) ) {
					$notes_out[] = 'title' === $field
						? sprintf( 'Jev reads the search title as an aphorism, not a query (rubric %.1f of 3, confidence %.2f).', (float) $f['score'], (float) $f['confidence'] )
						: sprintf( 'Jev reads the description as a fragment or a repeat of the title (rubric %.1f of 3, confidence %.2f).', (float) $f['score'], (float) $f['confidence'] );
				} else {
					$unsure++;
				}
			}
		}
		if ( array() === $notes_out ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => (int) $id,
			'subject_url'   => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $id ) : '',
			'subject_label' => (string) ( $n['title'] ?? '' ),
			'edit_url'      => function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) : '',
			'note'          => implode( ' ', $notes_out ),
		);
	}
	return array( 'findings' => $findings, 'unsure' => $unsure, 'judged' => $judged );
}

/**
 * CHECK 30.
 *
 * @since 16.3.0
 * @return array
 */
function sn_health_check_jev_notes() {
	$label = 'Notes Jev would not search for (search title or description, confidence at or above ' . SN_JEV_CONFIDENCE_FLOOR . ')';
	$hint  = 'Jev, TypeSafe\'s judge, read each note\'s title, search title, description and opening. Rewrite the search title as the words a searcher types, or the description as the argument in one sentence; the next daily pass re-reads it.';
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return sn_health_pack_check( $label, array(), $hint, 'No TypeSafe key in the keyring; the daily Jev pass does not run.' );
	}
	$data = function_exists( 'sn_jev_data' ) ? sn_jev_data() : null;
	if ( null === $data ) {
		return sn_health_pack_check( $label, array(), $hint, 'A key is stored but the first daily Jev pass has not run yet.' );
	}
	$j = sn_health_jev_notes_judge( $data['notes'] ?? array() );
	if ( $j['unsure'] > 0 ) {
		/* translators: %d: notes below the floor */
		$hint .= sprintf( ' %d reading(s) fell below the confidence floor and are not findings.', (int) $j['unsure'] );
	}
	return sn_health_pack_check( $label, $j['findings'], $hint, null );
}
