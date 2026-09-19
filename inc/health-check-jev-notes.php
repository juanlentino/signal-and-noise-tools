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

/**
 * 16.3.2: a score runs from 0 to the highest level number (docs, primitives/score),
 * so a three-level rubric scores 0..2.
 * 16.3.4: a finding is a position BELOW level one ("names the subject"),
 * whatever the confidence. The 16.3.0 floor (0.9) was the docs' "act
 * automatically" line; a health finding is the opposite of acting, it is
 * routing to the human, which the docs prescribe for every reading under
 * 0.5. On 16.3.3's first pass the positions were informative (a spread from
 * 0.58 to 1.88 that matched a human read) and the confidences were not (one
 * of 69 over 0.9), so a floor hid every reading. The note carries the
 * confidence; under 0.5 it says so.
 */
const SN_JEV_TITLE_FINDING_BELOW = 1.0;
const SN_JEV_DESCRIPTION_FINDING_BELOW = 1.0;
const SN_JEV_LOW_CONFIDENCE = 0.5;

/**
 * Judge the stored notes. PURE.
 *
 * @since 16.3.0
 * @param array $notes {id: {title, verdict|null, at, error}}.
 * @return array{findings:array,unsure:int,judged:int} `unsure` counts findings whose confidence is under 0.5.
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
		$v         = $n['verdict'];
		$prev      = is_array( $n['previous'] ?? null ) ? $n['previous'] : null;
		$notes_out = array();
		$low_conf  = false;
		foreach ( array( 'title' => SN_JEV_TITLE_FINDING_BELOW, 'description' => SN_JEV_DESCRIPTION_FINDING_BELOW ) as $field => $below ) {
			$f = $v[ $field ] ?? array();
			if ( ! isset( $f['score'] ) || (float) $f['score'] >= $below ) {
				continue;
			}
			// 16.8.1: an edge reading is taken twice. A note read at 1.00 one day
			// and 0.98 the next flapped in and out of the red count; a finding now
			// needs the previous pass below the line too. A first reading (no
			// previous pass) still counts: a read is a read until the next one.
			$p = is_array( $prev ) ? ( $prev[ $field ] ?? array() ) : array();
			if ( isset( $p['score'] ) && (float) $p['score'] >= $below ) {
				continue;
			}
			$conf = (float) ( $f['confidence'] ?? 0 );
			$tail = $conf < SN_JEV_LOW_CONFIDENCE ? sprintf( 'rubric %.2f of 2, confidence %.2f: Jev is unsure; read it yourself', (float) $f['score'], $conf ) : sprintf( 'rubric %.2f of 2, confidence %.2f', (float) $f['score'], $conf );
			$notes_out[] = 'title' === $field
				? 'Jev reads the search title below "names the subject" (' . $tail . ').'
				: 'Jev reads the description below "says what it is about" (' . $tail . ').';
			if ( $conf < SN_JEV_LOW_CONFIDENCE ) {
				$low_conf = true;
			}
		}
		if ( array() === $notes_out ) {
			continue;
		}
		if ( $low_conf ) {
			$unsure++;
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
	$label = 'Notes Jev reads below "names the subject" (search title or description)';
	$hint  = 'Jev, TypeSafe\'s judge, read each note\'s title, search title, description and opening. A note is listed when two consecutive daily passes read it below the line (an edge reading is taken twice). Rewrite the search title as the words a searcher types, or the description as the argument in one sentence; the next daily pass re-reads it.';
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return sn_health_pack_check( $label, array(), $hint, SN_JEV_NOT_READY . ' The daily Jev pass does not run without it.' );
	}
	$data = function_exists( 'sn_jev_data' ) ? sn_jev_data() : null;
	if ( null === $data ) {
		return sn_health_pack_check( $label, array(), $hint, 'A key is stored but the first daily Jev pass has not run yet.' );
	}
	$j = sn_health_jev_notes_judge( $data['notes'] ?? array() );
	if ( $j['unsure'] > 0 ) {
		/* translators: %d: findings Jev is unsure about */
		$hint .= sprintf( ' %d of them Jev is unsure about (confidence under %s); the position is the reading, the confidence is the caveat.', (int) $j['unsure'], SN_JEV_LOW_CONFIDENCE );
	}
	return sn_health_pack_check( $label, $j['findings'], $hint, null );
}
