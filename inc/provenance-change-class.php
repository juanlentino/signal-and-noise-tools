<?php
/**
 * Signal & Noise Tools: the change class, the author's label for what kind of
 * edit minted a provenance version (2026-10-17).
 *
 * The label is stored on the chain entry BESIDE the signed payload, never in
 * it: the payload, its hash and the algo are unchanged, and the Worker never
 * sees the label. It is the author's description of the edit, not something
 * the signature proves, and every surface that shows it says so.
 *
 * Absent key = unclassified, so every version minted before this module is
 * already correct and an unclassified version shows nothing.
 *
 * How a class reaches the commit: sn-apply holds it as pending for the post
 * around its write (sn_prov_change_class_set_pending), and sn_prov_record()
 * consumes it for that post. A write that mints nothing (coalesced) leaves
 * nothing behind, because sn-apply clears it after the write either way.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The fixed vocabulary. unclassified is the default and is never stored. */
const SN_PROV_CHANGE_CLASSES = array( 'reading_aid', 'correction', 'copy', 'claim', 'unclassified' );

/**
 * The short label a reader sees, or '' for unclassified or anything unknown.
 *
 * @param mixed $change_class
 * @return string
 */
function sn_prov_change_class_label( $change_class ) {
	$labels = array(
		'reading_aid' => 'reading aid',
		'correction'  => 'correction',
		'copy'        => 'copy edit',
		'claim'       => 'claim changed',
	);
	return $labels[ (string) $change_class ] ?? '';
}

/**
 * Is this a class worth storing (known and not unclassified)?
 *
 * @param mixed $change_class
 * @return bool
 */
function sn_prov_change_class_storable( $change_class ) {
	return '' !== sn_prov_change_class_label( $change_class );
}

/**
 * The request-scoped pending map, keyed by post ID.
 *
 * @return array<int,string>
 */
function &sn_prov_change_class_pending_map() {
	static $pending = array();
	return $pending;
}

/**
 * Hold a class for the next commit on this post. Unclassified and unknown
 * values hold nothing.
 *
 * @param int    $post_id
 * @param string $change_class
 */
function sn_prov_change_class_set_pending( $post_id, $change_class ) {
	$map = &sn_prov_change_class_pending_map();
	if ( sn_prov_change_class_storable( $change_class ) ) {
		$map[ (int) $post_id ] = (string) $change_class;
	} else {
		unset( $map[ (int) $post_id ] );
	}
}

/**
 * @param int $post_id
 * @return string The pending class, or ''.
 */
function sn_prov_change_class_pending( $post_id ) {
	$map = &sn_prov_change_class_pending_map();
	return $map[ (int) $post_id ] ?? '';
}

/**
 * @param int $post_id
 */
function sn_prov_change_class_clear_pending( $post_id ) {
	$map = &sn_prov_change_class_pending_map();
	unset( $map[ (int) $post_id ] );
}

/**
 * Label an existing version, or remove its label with 'unclassified'. The
 * backfill primitive: touches the change_class key only, never the hash or
 * the payload.
 *
 * @param int    $post_id
 * @param int    $version
 * @param string $change_class
 * @return bool False for an unknown class or a version the chain lacks.
 */
function sn_prov_set_change_class( $post_id, $version, $change_class ) {
	if ( ! in_array( (string) $change_class, SN_PROV_CHANGE_CLASSES, true ) ) {
		return false;
	}
	$chain = sn_prov_get_chain( $post_id );
	foreach ( $chain as $i => $entry ) {
		if ( (int) ( $entry['version'] ?? -1 ) !== (int) $version ) {
			continue;
		}
		unset( $chain[ $i ]['change_class'] );
		if ( sn_prov_change_class_storable( $change_class ) ) {
			$chain[ $i ]['change_class'] = (string) $change_class;
		}
		update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, $chain );
		return true;
	}
	return false;
}

/**
 * The correction notices in a body: each top-level paragraph block that
 * carries sn-correction or whose text opens "Correction," (the detector's
 * own shape, so an unclassed or italic notice still counts).
 *
 * @param string $content
 * @return string[] Each notice's inner markup, whitespace-collapsed.
 */
function sn_prov_change_class_correction_notices( $content ) {
	preg_match_all( '#<!--\s*wp:paragraph(\s+\{.*?\})?\s*-->(.*?)<!--\s*/wp:paragraph\s*-->#s', (string) $content, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $block ) {
		$inner = trim( (string) preg_replace( '/\s+/', ' ', $block[2] ) );
		$text  = trim( html_entity_decode( strip_tags( $inner ), ENT_QUOTES ) );
		if ( false !== strpos( (string) $block[1], 'sn-correction' ) || 1 === preg_match( '/^Correction,/i', $text ) ) {
			$out[] = $inner;
		}
	}
	return $out;
}

/**
 * Why this class cannot describe this edit, or '' when it can. A write that
 * adds or replaces a correction notice is a correction (or a claim), never a
 * reading aid or a copy edit.
 *
 * @param string $change_class
 * @param string $old_content
 * @param string $new_content
 * @return string
 */
function sn_prov_change_class_refusal( $change_class, $old_content, $new_content ) {
	if ( ! in_array( (string) $change_class, array( 'reading_aid', 'copy' ), true ) ) {
		return '';
	}
	$added = array_diff(
		sn_prov_change_class_correction_notices( $new_content ),
		sn_prov_change_class_correction_notices( $old_content )
	);
	return $added
		? sprintf( 'change.change_class "%s" cannot describe a write that adds or replaces a correction notice; use "correction" (or "claim").', $change_class )
		: '';
}
