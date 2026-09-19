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
 * Apply the tag-fit rows the owner checked. Reads assign[post_id][] and
 * remove[post_id][] = term_id.
 *
 * SECURITY (same shape as the retired sn_handle_tag_ai_apply, v6.39.2): the
 * POSTed maps are attacker-controllable, so the stored pass is the
 * allow-list. A pair is applied ONLY when Jev listed that exact term for that
 * exact post (a missing tag for assign, a misfit for remove), the post is a
 * Note, and the current user can edit_post it. Forged ids riding beside a
 * legitimate one are dropped. Applied pairs leave the stored pass.
 *
 * @param array $post Raw $_POST.
 * @return string flash code.
 */
function sn_handle_tag_fit_apply( $post ) {
	$assign = isset( $post['assign'] ) && is_array( $post['assign'] ) ? wp_unslash( $post['assign'] ) : array();
	$remove = isset( $post['remove'] ) && is_array( $post['remove'] ) ? wp_unslash( $post['remove'] ) : array();
	$rows   = function_exists( 'sn_jev_tags_rows' ) && function_exists( 'sn_jev_tags_data' ) ? sn_jev_tags_rows( sn_jev_tags_data() ) : array();
	$done   = array( 'assign' => array(), 'remove' => array() );
	foreach ( array( 'assign' => array( $assign, 'add' ), 'remove' => array( $remove, 'remove' ) ) as $what => $pair ) {
		list( $map, $key ) = $pair;
		foreach ( $map as $pid => $term_ids ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || empty( $rows[ $pid ][ $key ] ) ) {
				continue; // Jev never listed this post for this action.
			}
			if ( 'post' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			$allowed = array_map( 'intval', array_column( $rows[ $pid ][ $key ], 'id' ) );
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
			if ( 'assign' === $what ) {
				wp_set_object_terms( $pid, array_values( $ids ), 'post_tag', true );
			} else {
				wp_remove_object_terms( $pid, array_values( $ids ), 'post_tag' );
			}
			$done[ $what ][ $pid ] = array_values( $ids );
		}
	}
	if ( function_exists( 'sn_jev_tags_forget' ) && ( $done['assign'] || $done['remove'] ) ) {
		sn_jev_tags_forget( $done['assign'], $done['remove'] );
	}
	return ( $done['assign'] || $done['remove'] ) ? 'tag_fit_applied' : 'tag_fit_nothing';
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
