<?php
/**
 * Signal & Noise Tools — sn_apply change.type "delete_draft".
 *
 * ── Origin (2026-08-08 audit, item 6) ──
 *
 * create_draft has advertised `rollback: {method:"delete_draft", post_id}`
 * since v10.40.0 — but no delete_draft path existed anywhere in the MCP
 * surface, so abandoned drafts accumulated with no way to clean them up
 * through the tool that created them. This file makes the advertised
 * rollback method real.
 *
 * ── Structural constraints (the same fence philosophy as create_draft) ──
 *
 *   1. TRASH, never a hard delete. wp_trash_post() only — the draft is
 *      recoverable from wp-admin (Posts → Trash → Restore) until WordPress's
 *      own trash purge (EMPTY_TRASH_DAYS, default 30). This tool never
 *      permanently destroys content.
 *   2. DRAFT status only, checked at gate 2 AND re-checked inside the write
 *      primitive immediately before the trash call ("never trust gate 2
 *      alone" — the create_draft tag-resolution lesson). A post that was
 *      scheduled or published since the caller last looked refuses with a
 *      409; publish/future/pending/private posts are structurally
 *      unreachable from this type.
 *   3. Fingerprint REQUIRED (gate 1, the sentence_replace/restore_revision
 *      idiom): change.fingerprint must equal the draft's CURRENT
 *      content_hash (sn_posts' scheme, snt_corpus_content_hash — reused,
 *      never a parallel hash). Trashing a draft someone edited since it was
 *      created is the same stale-branch conflict every other gated write
 *      refuses. create_draft's rollback object now carries this fingerprint
 *      so the create → delete round trip is one-shot; otherwise read
 *      gates.fingerprint.observed from a dry_run:true call (the
 *      roadmap_board observe idiom) or fetch content_hash via sn_posts.
 *   4. Mode: revision-only, create_draft's mirror. There is no WordPress
 *      revision of a trash transition to stage — "revision" here means what
 *      it means for create_draft: the dry_run-by-default posture plus a
 *      write whose reversibility is delivered by WordPress's own
 *      trash/untrash mechanics rather than the staged-revision queue.
 *      The response's rollback.method is "manual_untrash" — deliberately
 *      prefixed: restoring from Trash is a wp-admin action, NOT an MCP
 *      method, and this field must never name an unreachable method again
 *      (the exact defect that created this change type).
 *
 * @package SignalNoiseTools
 * @since 10.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate 2 for change.type "delete_draft": the target must be a draft post of
 * post_type "post". Everything else is an error finding (blocks the write),
 * named precisely so the refusal is self-explanatory.
 *
 * @param array $resolved Output of snt_sn_apply_resolve_target() ({post_id}).
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_delete_draft( array $resolved ) {
	$findings = array();
	$post     = get_post( (int) ( $resolved['post_id'] ?? 0 ) );

	if ( $post && 'draft' !== (string) $post->post_status ) {
		$findings[] = snt_sn_validate_finding(
			'delete_draft', 'draft_status', 'error',
			sprintf(
				/* translators: %s: the target post's actual status. */
				__( 'delete_draft only trashes drafts — this post is "%s". Scheduled and published posts are structurally out of this change type\'s reach.', 'signal-and-noise-tools' ),
				(string) $post->post_status
			),
			(string) $post->post_status, 'draft', array(), 'delete_draft|status|' . $post->ID
		);
	}
	if ( $post && 'post' !== (string) $post->post_type ) {
		$findings[] = snt_sn_validate_finding(
			'delete_draft', 'post_type', 'error',
			__( 'delete_draft only targets post_type "post" — the mirror of what create_draft creates.', 'signal-and-noise-tools' ),
			(string) $post->post_type, 'post', array(), 'delete_draft|type|' . $post->ID
		);
	}

	return array( 'checks' => array( 'draft_status', 'post_type' ), 'findings' => $findings );
}

/**
 * Gate-passed, non-dry-run write: trash the draft. Re-verifies draft status
 * immediately before the trash call — gate 2 ran earlier in the request, and
 * "never trust gate 2 alone" is this executor layer's standing rule.
 *
 * @param int $post_id
 * @return array{post_id:int,status:string,previous_status:string,restore:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_sn_apply_not_a_draft   (409) — status changed since gate 2 ran.
 *   snt_sn_apply_write_failed  (500) — wp_trash_post() itself failed.
 */
function snt_sn_apply_write_delete_draft( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'draft' !== (string) $post->post_status || 'post' !== (string) $post->post_type ) {
		return new WP_Error(
			'snt_sn_apply_not_a_draft',
			__( 'The target is no longer a draft post — it changed between validation and the write. Nothing was trashed.', 'signal-and-noise-tools' ),
			array( 'status' => 409 )
		);
	}

	$trashed = wp_trash_post( (int) $post_id );
	if ( ! $trashed ) {
		return new WP_Error( 'snt_sn_apply_write_failed', __( 'wp_trash_post() failed.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	return array(
		'post_id'         => (int) $post_id,
		'status'          => 'trash',
		'previous_status' => 'draft',
		'restore'         => 'wp-admin: Posts → Trash → Restore. WordPress purges trash after EMPTY_TRASH_DAYS (default 30).',
	);
}

/**
 * dry_run preview: what would be trashed. Mirrors create_draft's preview
 * posture ({title, block_count, word_count} rather than a before/after
 * content diff) — the useful question for a delete is "is this the draft I
 * think it is", answered by identity fields, not a content diff.
 *
 * @param array $resolved
 * @return array{before:array|null,after:array|null,blocks_touched:int}
 */
function snt_sn_apply_delete_draft_diff( array $resolved ) {
	$post = get_post( (int) ( $resolved['post_id'] ?? 0 ) );
	if ( ! $post ) {
		return array( 'before' => null, 'after' => null, 'blocks_touched' => 0 );
	}
	$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $post->post_content ) : strip_tags( (string) $post->post_content );
	return array(
		'before'         => array(
			'post_id'    => (int) $post->ID,
			'status'     => (string) $post->post_status,
			'title'      => (string) $post->post_title,
			'word_count' => function_exists( 'snt_word_count' ) ? snt_word_count( $stripped ) : str_word_count( $stripped ),
		),
		'after'          => array( 'post_id' => (int) $post->ID, 'status' => 'trash' ),
		'blocks_touched' => 0,
	);
}
