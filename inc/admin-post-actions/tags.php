<?php
/**
 * Signal & Noise — admin POST handlers: tag vocabulary: merge, AI suggest/apply, prune.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: tag_merge, tag_ai_suggest, tag_ai_apply, tag_prune_unused
 *
 * @package SignalNoiseTools
 * @since 12.22.0
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
 * Run the AI tag-suggestion pass over untagged Notes; store the results in a
 * per-user transient for review. Returns a flash code.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_suggest( $post ) {
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return 'tag_ai_unavailable';
	}
	if ( ! function_exists( 'sn_tag_untagged_notes' ) || ! function_exists( 'snt_ai_tag_suggest_impl' ) ) {
		return 'tag_ai_none';
	}
	$results = array();
	foreach ( sn_tag_untagged_notes( 20 ) as $note ) {
		$out = snt_ai_tag_suggest_impl( (int) $note['id'] );
		if ( ! is_wp_error( $out ) && ! empty( $out['suggested'] ) ) {
			$out['title'] = (string) $note['title'];
			$results[]    = $out;
		}
	}
	if ( ! $results ) {
		return 'tag_ai_none';
	}
	set_transient( 'sn_tag_ai_suggestions_' . get_current_user_id(), $results, HOUR_IN_SECONDS );
	return 'tag_ai_suggested';
}

/**
 * Apply the AI tag suggestions the owner checked. Reads assign[post_id][] = term_id.
 *
 * SECURITY (v6.39.2): the POSTed assign map is fully attacker-controllable, so
 * it is NOT trusted directly. The cached suggestion transient written by
 * sn_handle_tag_ai_suggest() is the authoritative allow-list — a (post,term)
 * pair is applied ONLY when:
 *   1. SN proposed that exact term for that exact post in this user's last scan,
 *   2. the post is an editable Note (post_type 'post' — the only type the
 *      suggester scans; never a page/CPT/attachment), and
 *   3. the current user can edit_post that specific post (per-resource cap, not
 *      a blanket manage_options — the dispatcher already checked the nonce).
 * Submitted term ids are intersected with the suggested set for that post, so a
 * forged term riding alongside a legitimate one is dropped, not applied.
 *
 * @param array $post Raw $_POST.
 * @return string
 */
function sn_handle_tag_ai_apply( $post ) {
	$assign = isset( $post['assign'] ) && is_array( $post['assign'] ) ? wp_unslash( $post['assign'] ) : array();

	// Build the allow-list: post_id => set of suggested term_ids.
	$cache   = get_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	$allowed = array();
	if ( is_array( $cache ) ) {
		foreach ( $cache as $row ) {
			if ( ! is_array( $row ) || empty( $row['suggested'] ) || ! is_array( $row['suggested'] ) ) {
				continue;
			}
			$pid = (int) ( $row['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			foreach ( $row['suggested'] as $s ) {
				$tid = (int) ( is_array( $s ) ? ( $s['term_id'] ?? 0 ) : 0 );
				if ( $tid > 0 ) {
					$allowed[ $pid ][ $tid ] = true;
				}
			}
		}
	}

	foreach ( $assign as $pid => $term_ids ) {
		$pid = (int) $pid;
		if ( $pid <= 0 || empty( $allowed[ $pid ] ) ) {
			continue; // never suggested for this post.
		}
		if ( 'post' !== get_post_type( $pid ) || ! current_user_can( 'edit_post', $pid ) ) {
			continue; // not an editable Note for this user.
		}
		$ids = array();
		foreach ( (array) $term_ids as $tid ) {
			$tid = (int) $tid;
			if ( $tid > 0 && isset( $allowed[ $pid ][ $tid ] ) ) {
				$ids[ $tid ] = $tid; // intersect with the suggested set; dedupe.
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $pid, array_values( $ids ), 'post_tag', true );
		}
	}

	delete_transient( 'sn_tag_ai_suggestions_' . get_current_user_id() );
	return 'tag_ai_applied';
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
