<?php
/**
 * Signal & Noise Tools — Content Health check 31: tags Jev reads as not
 * fitting (16.8.0).
 *
 * Over the stored tag-fit pass (inc/jev-tags.php). A finding per note:
 * an attached tag the note does not argue about (score under 1 of 2) and
 * a tag the note does not carry that a reader browsing it would expect
 * (probability 0.6 or above). Tags are not prose, so a published note can
 * take the fix; and the tag's description is what Jev read, so a wrong
 * reading of a right tag is a description to fix, not a tag to remove.
 *
 * @since 16.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The judge. PURE given the stored notes.
 *
 * @return array{findings:array,judged:int}
 */
function sn_health_jev_tags_judge( $notes ) {
	$findings = array();
	$judged   = 0;
	foreach ( (array) $notes as $id => $n ) {
		if ( ! is_array( $n ) ) {
			continue;
		}
		$judged++;
		$parts = array();
		foreach ( (array) ( $n['attached'] ?? array() ) as $t ) {
			if ( (float) $t['score'] < SN_JEV_TAG_MISFIT_BELOW ) {
				$parts[] = sprintf( 'Jev reads the tag "%s" as attached for reach (%.2f of 2, confidence %.2f).', (string) $t['name'], (float) $t['score'], (float) $t['confidence'] );
			}
		}
		foreach ( (array) ( $n['missing'] ?? array() ) as $t ) {
			$parts[] = sprintf( 'A reader browsing "%s" would expect this note (%.2f).', (string) $t['name'], (float) $t['noul'] );
		}
		if ( array() === $parts ) {
			continue;
		}
		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => (int) $id,
			'subject_url'   => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $id ) : '',
			'subject_label' => (string) ( $n['title'] ?? '' ),
			'edit_url'      => function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) : '',
			'note'          => implode( ' ', $parts ),
		);
	}
	return array( 'findings' => $findings, 'judged' => $judged );
}

/**
 * CHECK 31.
 *
 * @since 16.8.0
 * @return array
 */
function sn_health_check_jev_tags() {
	$label = 'Notes whose tags Jev reads as not fitting (attached for reach, or a tag the note should carry)';
	$hint  = 'Tags are not prose: a tag change moves nothing the signature covers, so a published note can take the fix. Remove a tag the note does not argue about; add one a reader browsing it would expect. Jev read each tag\'s DESCRIPTION, so a wrong reading of a right tag is the description to fix (Content › Tags), not the tag. The weekly pass re-reads; jev-tags-now runs it now.';
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return sn_health_pack_check( $label, array(), $hint, SN_JEV_NOT_READY . ' The tag-fit pass does not run without it.' );
	}
	$data = function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_data() : null;
	if ( null === $data ) {
		return sn_health_pack_check( $label, array(), $hint, 'A key is stored but the first tag-fit pass has not run yet.' );
	}
	$j = sn_health_jev_tags_judge( $data['notes'] ?? array() );
	return sn_health_pack_check( $label, $j['findings'], $hint, null );
}
