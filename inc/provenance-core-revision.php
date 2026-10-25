<?php
/**
 * Signal & Noise Tools: the core revision beside each provenance commit
 * (2026-10-24).
 *
 * By the time sn_prov_record() runs (wp_after_insert_post, priority 20), core
 * has already saved its own revision of the post (wp_save_post_revision on
 * post_updated, and on wp_after_insert_post at priority 9). The commit stores
 * that revision's id as `core_revision_id`, BESIDE the signed payload the way
 * change_class sits beside it: never in the hashed bytes, so no hash,
 * signature or ledger record moves, and the Worker never sees it (its body is
 * key-picked in sn_prov_dispatch()).
 *
 * It answers "which WordPress revision is this version?" so the owner can open
 * core's own compare screen for it. It is a pointer, not evidence: core prunes
 * revisions past wp_revisions_to_keep() and a deleted revision is gone, so a
 * stored id may name a revision that no longer exists. That is fine and every
 * surface that shows it says so. It is the newest revision row at mint time:
 * a save that changed only fields core does not revision (the tags, say) mints
 * no new core revision, and core counts autosaves as revisions, so that row can
 * be an earlier save or an autosave rather than this exact content.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core's newest revision of a post, or 0 when it has none or revisions are off.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function sn_prov_core_revision_id( $post_id ) {
	if ( ! function_exists( 'wp_get_latest_revision_id_and_total_count' ) ) {
		return 0;
	}
	$latest = wp_get_latest_revision_id_and_total_count( (int) $post_id );
	return is_array( $latest ) ? (int) ( $latest['latest_id'] ?? 0 ) : 0;
}

/**
 * A chain entry's core revision as a field for a read surface: present only
 * when one was stored. The id may dangle once core prunes that revision.
 *
 * @param mixed $entry A chain entry.
 * @return array<string,int>
 */
function sn_prov_core_revision_field( $entry ) {
	$id = is_array( $entry ) ? (int) ( $entry['core_revision_id'] ?? 0 ) : 0;
	return $id > 0 ? array( 'core_revision_id' => $id ) : array();
}
