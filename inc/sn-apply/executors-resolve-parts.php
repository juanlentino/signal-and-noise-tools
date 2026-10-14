<?php
/**
 * Signal & Noise Tools: sn_apply executors, the mode-support matrix, the target resolver and the revision write callback.
 *
 * Split from inc/sn-apply/executors.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The mode-support matrix, structural (never identity-dependent — see
 * inc/sn-apply/gates.php's snt_sn_apply_gate_capability() for how this
 * combines with the IDENTITY grant). 'reason' is surfaced verbatim in a
 * gate-3 refusal when 'revision' is requested for a publish-only type.
 *
 * @param string $type
 * @return array{modes:string[],reason:string|null}
 */
function snt_sn_apply_mode_support( $type ) {
	switch ( (string) $type ) {
		case 'block_migration':
		case 'pattern_adoption':
		case 'drift_replace':
		case 'link_insert':
		case 'emdash_replace':
		case 'sentence_replace':
		case 'link_reshape':
		case 'unlink':
		case 'batch':
		case 'block_insert':
		case 'block_replace':
		case 'block_delete':
		case 'block_move':
		case 'alt_text':
		case 'surfaces':
			return array( 'modes' => array( 'revision', 'publish' ), 'reason' => null );
		case 'og_card':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'og_card regenerates a PNG file on disk (sn_generate_og_card()): not a post field. There is no WordPress revision of a file; this change type is publish-only.',
			);
		case 'anchor_sweep':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'anchor_sweep dispatches a live HTTP call to the provenance Worker (sn_prov_run_sweep()): an external side effect with no post entity to stage a revision of. Publish-only.',
			);
		case 'roadmap_board':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'roadmap_board writes the maturity roadmap\'s site-level board override: an option, not a post field. An option has no WordPress revision to stage — publish-only, the og_card/anchor_sweep posture; dry_run:true is the review step.',
			);
		case 'merge_tags':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'merge_tags reassigns posts between terms and deletes the emptied ones: a taxonomy write with no post-field revision to stage. Publish-only, the og_card/anchor_sweep/roadmap_board posture; dry_run:true is the review step.',
			);
		case 'clear_template_overrides':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'clear_template_overrides removes Site Editor template rows: not a post field, and there is no WordPress revision of a deleted override. Publish-only; dry_run:true is the review step.',
			);
		case 'schedule_cron_event':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'schedule_cron_event writes a WP-Cron row: not a post field, and a cron booking has no WordPress revision to stage. Publish-only; dry_run:true is the review step, and it is a genuine one here — the dry run reports what WOULD be booked without booking it.',
			);
		case 'dismiss':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'dismiss writes the per-surface dismissal store, not a post field. A store row has no WordPress revision to stage — publish-only, the og_card/anchor_sweep/roadmap_board posture; dry_run:true is the review step.',
			);
		case 'create_draft':
			return array(
				'modes'  => array( 'revision' ),
				'reason' => 'create_draft can never publish (post_status is hard-coded to "draft" and this tool will never make it live. Drafts are scheduled by hand; that manual step is the human review gate. This change type only supports mode:"revision", which performs the actual (reversible via rollback:delete_draft) draft insert directly) there is no live post yet to stage a WordPress core revision against.',
			);
		case 'delete_draft':
			return array(
				'modes'  => array( 'revision' ),
				'reason' => 'delete_draft is create_draft\'s mirror: trash-only (never a hard delete, recoverable from wp-admin Trash), draft-only, and revision-only for the same reason create_draft is — there is no WordPress revision of a trash transition to stage; "revision" means the dry_run-by-default posture with reversibility delivered by WordPress\'s own trash/untrash mechanics.',
			);
		case 'restore_revision':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'restore_revision IS the live write (it is the acceptance step of the PR pattern, promoting a staged revision to the live post. "Staging a restore" would mean staging a revision of a revision, which has no meaning. This change type only supports mode:"publish"; a routine credential (granted "revision" only) is refused here by the same identity grant every other publish-only call already goes through) never a new capability check.',
			);
		default:
			return array( 'modes' => array(), 'reason' => 'Unknown change.type.' );
	}
}

/**
 * Resolve + validate a raw target object for a given change type. Returns a
 * normalized array (post_id / attachment_id / scope, whichever applies) or a
 * WP_Error (404/422) — never partial state.
 *
 * @param string $type
 * @param mixed  $target
 * @return array|WP_Error
 */
function snt_sn_apply_resolve_target( $type, $target ) {
	$target = is_array( $target ) ? $target : array();

	if ( 'anchor_sweep' === $type ) {
		if ( 'provenance_anchors' !== ( $target['scope'] ?? '' ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'anchor_sweep requires target.scope === "provenance_anchors".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'scope' => 'provenance_anchors' );
	}

	if ( 'merge_tags' === $type ) {
		// Explicit scope, the anchor_sweep/roadmap_board posture: the target is
		// the tag vocabulary, not a post — name it rather than accepting any
		// object at all.
		if ( 'tags' !== ( $target['scope'] ?? '' ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'merge_tags requires target.scope === "tags".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'scope' => 'tags' );
	}

	if ( 'clear_template_overrides' === $type ) {
		if ( 'template_overrides' !== ( $target['scope'] ?? '' ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'clear_template_overrides requires target.scope === "template_overrides".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'scope' => 'template_overrides' );
	}

	if ( 'schedule_cron_event' === $type ) {
		if ( 'cron' !== ( $target['scope'] ?? '' ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'schedule_cron_event requires target.scope === "cron".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'scope' => 'cron' );
	}

	if ( 'roadmap_board' === $type ) {
		// Same explicit-scope posture as anchor_sweep: the target is a site
		// surface, not a post — name it exactly rather than accepting any
		// object at all.
		if ( 'maturity_roadmap' !== ( $target['scope'] ?? '' ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'roadmap_board requires target.scope === "maturity_roadmap".', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'scope' => 'maturity_roadmap' );
	}

	if ( 'alt_text' === $type ) {
		$attachment_id = (int) ( $target['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'alt_text requires target.attachment_id.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Attachment not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
		}
		return array( 'attachment_id' => $attachment_id );
	}

	if ( 'create_draft' === $type ) {
		// The target doesn't exist yet — nothing to look up. Requires the
		// explicit marker (same "name the exact shape" posture as
		// anchor_sweep's target.scope check above) rather than accepting
		// any object at all for this type.
		if ( true !== ( $target['new_post'] ?? null ) ) {
			return new WP_Error( 'snt_sn_apply_bad_target', __( 'create_draft requires target.new_post === true.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
		}
		return array( 'new_post' => true );
	}

	// Every other type: a corpus post_id (same target contract as
	// update-post-surfaces — corpus statuses + allowed post types only).
	$post_id = (int) ( $target['post_id'] ?? 0 );
	if ( $post_id <= 0 ) {
		return new WP_Error( 'snt_sn_apply_bad_target', __( 'This change type requires target.post_id.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$post      = get_post( $post_id );
	$status_ok = $post && defined( 'SNT_CORPUS_STATUSES' ) && in_array( (string) $post->post_status, SNT_CORPUS_STATUSES, true );
	$type_ok   = $post && function_exists( 'snt_corpus_post_type_allowed' ) && snt_corpus_post_type_allowed( (string) $post->post_type );
	if ( ! $status_ok || ! $type_ok ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}
	return array( 'post_id' => $post_id );
}


/**
 * A write_callback (see snt_block_fp_apply()'s docblock, and
 * snt_ai_drift_apply_impl()/snt_ai_link_apply_impl()'s) that routes a
 * content-field write through session 6a's staged-revision primitive
 * instead of the live post. $revision_id_out is populated by reference —
 * the only way to get the created revision's ID back out of a callback
 * whose return value must mimic wp_update_post()'s own contract (post ID or
 * WP_Error, nothing richer).
 *
 * @param int|null $revision_id_out By reference; set to the new revision ID on success.
 * @return callable
 */
function snt_sn_apply_revision_write_callback( &$revision_id_out ) {
	return function( $post_id, $new_content ) use ( &$revision_id_out ) {
		$staged = snt_sn_apply_stage_revision( $post_id, array( 'post_content' => (string) $new_content ) );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$revision_id_out = (int) $staged['revision_id'];
		return $revision_id_out;
	};
}

