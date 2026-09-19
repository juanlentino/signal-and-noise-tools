<?php
/**
 * Signal & Noise Tools — Content Health check 31: tags Jev reads as not
 * fitting (16.8.0).
 *
 * Over the stored tag-fit pass (inc/jev-tags.php). A finding per note: an
 * attached tag whose subject the note does not touch (score under 0.5 of 2,
 * read with confidence 0.7 or better; the lines moved off two live passes,
 * 16.9.1 and 16.9.2, and the add side is gone since 16.9.2). Tags are not prose, so a published note can
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
			if ( sn_jev_tag_is_misfit( $t ) ) {
				$parts[] = sprintf( 'Jev reads the tag "%s" as attached for reach (%.2f of 2, confidence %.2f).', (string) $t['name'], (float) $t['score'], (float) $t['confidence'] );
			}
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
	$label = 'Notes carrying a tag Jev reads as attached for reach (the subject is absent from the note)';
	$hint  = 'An advisory, not a fault: it re-opens as notes and tags arrive, and the names itemize on the jev-tags ability. Tags are not prose: a tag change moves nothing the signature covers, so a published note can take the fix. Remove a tag whose subject the note does not touch. Jev read each tag\'s DESCRIPTION, so a wrong reading of a right tag is the description to fix (Content › Tags), not the tag. The weekly pass re-reads; jev-tags-now runs it now.';
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
