<?php
/**
 * Signal & Noise — admin POST handlers: tag vocabulary: merge, AI suggest/apply, prune.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: tag_merge, tag_fit_run, tag_fit_apply (16.9.0; the Claude suggest pair retired), tag_prune_unused
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commit a tag merge (POSTed from the Content > Tags confirm panel). The central
 * dispatcher already verified the nonce + manage_options. Returns a ?sn_flash code.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_tag_merge( $post ) {
	$from = array_filter( array_map( 'intval', explode( ',', isset( $post['sn_tag_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_tag_from'] ) ) : '' ) ) );
	$into = isset( $post['sn_tag_into'] ) ? (int) $post['sn_tag_into'] : 0;
	if ( ! $from || ! $into || ! function_exists( 'sn_tag_merge' ) ) {
		return 'tag_merge_error';
	}
	$res = sn_tag_merge( $from, $into );
	return is_wp_error( $res ) ? 'tag_merge_error' : 'tag_merge_ok';
}

/**
 * 16.9.0: run the Jev tag-fit pass now (Content › Tags › "Read tags now").
 * Replaces the Claude suggest (v6.39.2 → 16.8.2), which only saw untagged
 * notes and only proposed; Jev reads every note against every tag.
 *
 * @param array $post Raw $_POST (unused).
 * @return string flash code.
 */
function sn_handle_tag_fit_run( $post ) {
	unset( $post );
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() || ! function_exists( 'sn_jev_tags_sync' ) ) {
		return 'tag_fit_unavailable';
	}
	$r = sn_jev_tags_sync();
	return ! empty( $r['ok'] ) ? 'tag_fit_read' : 'tag_fit_failed';
}

/**
 * Apply the tag-fit rows the owner checked. Reads remove[post_id][] = term_id.
 * 16.9.2: remove only; the pass no longer proposes tags to add.
 *
 * SECURITY (same shape as the retired sn_handle_tag_ai_apply, v6.39.2): the
 * POSTed map is attacker-controllable, so the stored pass is the allow-list.
 * A pair is removed ONLY when Jev listed that exact term as a misfit for that
 * exact post, the post is a Note, and the current user can edit_post it.
 * Forged ids riding beside a legitimate one are dropped. Applied pairs leave
 * the stored pass.
 *
 * @param array $post Raw $_POST.
 * @return string flash code.
 */
function sn_handle_tag_fit_apply( $post ) {
	$remove = isset( $post['remove'] ) && is_array( $post['remove'] ) ? wp_unslash( $post['remove'] ) : array();
	$rows   = function_exists( 'sn_jev_tags_rows' ) && function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_rows( sn_jev_tags_data() ) : array();
	$done   = array();
	foreach ( $remove as $pid => $term_ids ) {
		$pid = (int) $pid;
		if ( $pid <= 0 || empty( $rows[ $pid ]['remove'] ) ) {
			continue; // Jev never listed this post.
		}
		if ( 'post' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			continue;
		}
		$allowed = array_map( 'intval', array_column( $rows[ $pid ]['remove'], 'id' ) );
		$ids     = array();
		foreach ( (array) $term_ids as $tid ) {
			$tid = (int) $tid;
			if ( $tid > 0 && in_array( $tid, $allowed, true ) ) {
				$ids[ $tid ] = $tid;
			}
		}
		if ( array() === $ids ) {
			continue;
		}
		wp_remove_object_terms( $pid, array_values( $ids ), 'post_tag' );
		$done[ $pid ] = array_values( $ids );
	}
	if ( function_exists( 'sn_jev_tags_forget' ) && $done ) {
		sn_jev_tags_forget( $done );
	}
	return $done ? 'tag_fit_applied' : 'tag_fit_nothing';
}

/**
 * 17.2.0: file tags under /notes/tags' headings from Content › Tags. Reads
 * group[term_id] = group id ('' for unfiled). The heading list and the meta
 * key are the theme's (sn_notes_tag_group_ids(), SN_TAG_GROUP_META, theme
 * 13.4.0); without them the handler refuses rather than inventing a key.
 * Each pair is written only when the user can edit_term that tag and the
 * value differs from what the tag renders under today.
 *
 * @param array $post Raw $_POST.
 * @return string flash code.
 */
function sn_handle_tag_group_apply( $post ) {
	if ( ! function_exists( 'sn_notes_tag_group_ids' ) || ! function_exists( 'sn_notes_tag_group_effective' ) || ! function_exists( 'sn_notes_tag_group_of' ) || ! defined( 'SN_TAG_GROUP_META' ) ) {
		return 'tag_group_unavailable';
	}
	$map  = isset( $post['group'] ) && is_array( $post['group'] ) ? wp_unslash( $post['group'] ) : array();
	$ids  = sn_notes_tag_group_ids();
	$done = 0;
	foreach ( $map as $tid => $gid ) {
		$tid = (int) $tid;
		$gid = is_string( $gid ) ? sanitize_key( $gid ) : '';
		$gid = in_array( $gid, $ids, true ) ? $gid : '';
		$term = $tid > 0 ? get_term( $tid, 'post_tag' ) : null;
		if ( ! $term || is_wp_error( $term ) || ! current_user_can( 'edit_term', $tid ) ) {
			continue;
		}
		if ( $gid === sn_notes_tag_group_effective( $term ) ) {
			continue; // Already renders there; nothing to write.
		}
		if ( '' === $gid ) {
			// Unfiling only removes the owner's filing; a tag the theme's seed
			// list names keeps rendering there, so with no meta to remove
			// there is nothing this form can change.
			if ( '' === sn_notes_tag_group_of( $term ) ) {
				continue;
			}
			delete_term_meta( $tid, SN_TAG_GROUP_META );
		} else {
			update_term_meta( $tid, SN_TAG_GROUP_META, $gid );
		}
		$done++;
	}
	return $done ? 'tag_group_applied' : 'tag_group_nothing';
}

/**
 * Delete the selected unused (count-0) tags. Reads sn_tag_unused[] = term_id.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_prune_unused( $post ) {
	$ids = isset( $post['sn_tag_unused'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $post['sn_tag_unused'] ) ) ) : array();
	if ( ! $ids || ! function_exists( 'sn_tag_delete_unused' ) ) {
		return 'tag_prune_error';
	}
	$res = sn_tag_delete_unused( $ids );
	return is_wp_error( $res ) ? 'tag_prune_error' : 'tag_pruned';
}
