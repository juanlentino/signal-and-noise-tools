<?php
/**
 * Signal & Noise Tools — sn_apply per-change-type executors. MCP
 * consolidation session 6b.
 *
 * Everything TYPE-SPECIFIC lives here: target-shape resolution, the
 * mode-support matrix (gate 3's structural half), and the actual write
 * dispatch. Gates 1 (fingerprint) and 2 (server-side validation) live in the
 * sibling inc/sn-apply-validation.php (split out purely for the 450-line
 * file budget — same executor layer). Every write DELEGATES to the real
 * absorbed impl's core logic — this file never re-implements a capability
 * check, a sanitizer, or a fingerprint scheme; see each case below for
 * exactly which function it calls and why.
 *
 * ── The mode-support matrix (grounded, not asserted) ──
 *
 * | type             | revision | publish | why                                                                 |
 * |------------------|:--------:|:-------:|----------------------------------------------------------------------|
 * | block_migration   |   yes    |   yes   | content field write; snt_block_fp_apply()'s injectable write_callback (v10.40.0) routes it through snt_sn_apply_stage_revision(). |
 * | pattern_adoption  |   yes    |   yes   | same mechanism as block_migration (shared engine).                    |
 * | emdash_replace    |   yes    |   yes   | prose em-dash fix; DELEGATES to drift_replace's splice (identical mechanics, different candidate source: snt_emdash_scan_content()).
 * | drift_replace     |   yes    |   yes   | content field write (substr_replace on post_content); snt_ai_drift_apply_impl()'s injectable write_callback (v10.40.0). |
 * | link_insert       |   yes    |   yes   | same mechanism as drift_replace (snt_ai_link_apply_impl()).           |
 * | alt_text          |   yes    |   yes   | postmeta write (_wp_attachment_image_alt); revision mode uses snt_sn_apply_stage_meta() — session 6a's staged-meta draft queue, not a postmeta write. |
 * | surfaces          |  partial |   yes   | excerpt is a content field (post_excerpt, revision-stageable); meta_description/og_card_title/seo_title/focus_keyword are postmeta (staged via snt_sn_apply_stage_meta()). Revision mode stages ALL of these but never regenerates the OG card PNG — that side effect is publish-only (see og_card below). |
 * | og_card           |    NO    |   yes   | sn_generate_og_card() writes a PNG FILE to disk (inc/og-card-generator.php) — not a post field at all. There is no WordPress revision of a file. Refuses structurally in revision mode, never fakes a staged version. |
 * | anchor_sweep      |    NO    |   yes   | dispatches a bounded wp_remote_post() to the provenance Worker (inc/provenance-webhook.php's sn_prov_run_sweep()) — an external side effect with no post entity involved at all (target is scope:"provenance_anchors", not a post_id). Nothing to stage. |
 * | create_draft      |   yes    |   NO    | insert-only (session 6c, the arc's finale — see inc/sn-apply-create-draft.php's docblock for the full B5c origin): post_status is hard-coded 'draft', post_type hard-coded 'post'. mode:"publish" refuses structurally — this tool never makes a draft live; the owner schedules by hand. mode:"revision" is the one accepted mode, but its OWN write mechanism (snt_sn_apply_write_create_draft(), not snt_sn_apply_stage_revision()) — there is no parent post yet to stage a core revision against; "revision" here just means the request goes through the same dry_run-by-default, human-review-first posture as everywhere else in this tool. |
 * | link_reshape      |   yes    |   yes   | audit item 5 (v10.58.0): move an <a>'s boundaries within one text node. new_anchor must be a contiguous, unique substring of current_anchor; href carried over, never a parameter; prose byte-identity ASSERTED post-splice; fingerprint = live content_hash (sentence_replace's binding). Provenance-invisible by item 4's answer (normalized prose is unchanged -> bearing-hash coalesce -> no new commit). |
 * | delete_draft      |   yes    |   NO    | create_draft's mirror (audit item 6, v10.58.0): trash-only (wp_trash_post, never a hard delete), draft-only (gate 2 + a last-instant re-check in the write), fingerprint-gated on the draft's content_hash. Revision-only for create_draft's reason; rollback is wp-admin untrash (reported as method "manual_untrash" — a human action, not an MCP method). |
 * | restore_revision  |    NO    |   yes   | session 7 (the acceptance path — see inc/sn-apply-restore-revision.php's docblock): a restore IS the live write, so "staging a restore" would stage a revision of a revision. mode:"revision" refuses structurally, the exact mechanism og_card/anchor_sweep use. Publish-only means only the rw door's bound owner credential can ever execute it (gate 3's identity grant); a routine credential is refused there by construction, never by new identity code. Promotes a staged revision to live AND applies+clears any queued snt_sn_apply_stage_meta() rows for the same post — the queue's first application path. |
 *
 * "partial" on surfaces is intentionally not a clean yes/no: the TYPE
 * supports revision mode (the gate lets the mode through), but the response
 * itself documents which sub-fields staged vs which side effect (card
 * regen) was skipped — see snt_sn_apply_write_surfaces() below.
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Session 6c appended 'create_draft' (the arc's finale); session 7 appends
// 'restore_revision' (the acceptance path). Every gate function below
// switches on this list, never a hardcoded count; the
// tests/abilities-sn-apply-delegation-sweep.php ALL-TYPES sweep loops this
// same constant, so it REDs on its own count pin the moment a type is added
// here without a matching sweep-table entry (watched RED, see FINDINGS.md).
const SNT_SN_APPLY_CHANGE_TYPES = array(
	'block_migration', 'pattern_adoption', 'alt_text', 'link_insert',
	'drift_replace', 'surfaces', 'og_card', 'anchor_sweep', 'create_draft',
	'restore_revision', 'emdash_replace', 'sentence_replace',
	'roadmap_board',
	// v10.58.0 (audit item 6): makes create_draft's advertised
	// rollback:{method:"delete_draft"} REAL — trash-only, draft-only,
	// fingerprint-gated. See inc/sn-apply-delete-draft.php.
	'delete_draft',
	// v10.58.0 (audit item 5, owner-confirmed after item 4): move an <a>'s
	// boundaries within one text node — rendered prose byte-identical,
	// asserted server-side. See inc/sn-apply-link-reshape.php.
	'link_reshape',
);

/**
 * The mode-support matrix, structural (never identity-dependent — see
 * inc/sn-apply-gates.php's snt_sn_apply_gate_capability() for how this
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

/**
 * Gate-passed, non-dry-run write dispatch. Every branch delegates to the
 * real absorbed impl for the actual mutation logic (capability re-check,
 * sanitization, the write itself) — this function only decides WHICH write
 * path (revision vs publish) and shapes the diff/revision_id the ability
 * response needs.
 *
 * @param string $type
 * @param array  $resolved
 * @param array  $change
 * @param string $mode 'revision'|'publish'.
 * @return array{ok:bool,diff:array,revision_id:?int,write_result:array}|WP_Error
 */
function snt_sn_apply_execute_write( $type, array $resolved, array $change, $mode ) {
	$payload = (array) ( $change['payload'] ?? array() );

	switch ( $type ) {
		case 'block_migration':
		case 'pattern_adoption':
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$fp          = (string) ( $change['fingerprint'] ?? '' );
			$markup      = (string) ( $payload['replacement_markup'] ?? '' );
			if ( 'block_migration' === $type ) {
				$result = snt_block_migrations_apply_impl( $resolved['post_id'], $fp, $markup, (string) ( $payload['migration_type'] ?? '' ), $cb );
			} else {
				$result = snt_ai_pattern_adoption_apply_impl( $resolved['post_id'], $fp, $markup, (string) ( $payload['pattern_type'] ?? '' ), $cb );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => $result['old_content'] ?? '', 'after' => $result['new_content'] ?? '', 'blocks_touched' => 1 ),
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'emdash_replace':
		case 'drift_replace':
		case 'link_insert':
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$fp          = (string) ( $change['fingerprint'] ?? '' );
			if ( 'drift_replace' === $type || 'emdash_replace' === $type ) {
				// emdash_replace IS a drift_replace at the write layer: locate the
				// phrase in raw content, gate on the fingerprint, splice. The only
				// difference is upstream — its candidates come from
				// snt_emdash_scan_content()'s prose classifier rather than the
				// time-phrase scanner. Delegating keeps one splice implementation.
				$result = snt_ai_drift_apply_impl( $resolved['post_id'], (string) ( $payload['phrase'] ?? '' ), (int) ( $payload['position'] ?? -1 ), (string) ( $payload['replacement'] ?? '' ), $fp, (string) ( $payload['context_snippet'] ?? '' ), $cb );
			} else {
				$result = snt_ai_link_apply_impl( $resolved['post_id'], (string) ( $payload['anchor'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ), $fp, (string) ( $payload['target_url'] ?? '' ), $cb );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => $result['old_content'] ?? '', 'after' => $result['new_content'] ?? '', 'blocks_touched' => 0 ),
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'sentence_replace':
			// The agent-composed body edit — impl in
			// inc/sn-apply-sentence-replace.php; whole-post content_hash
			// fingerprint, plain-prose splice, same write-callback contract
			// as the drift family above.
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$result      = snt_sn_apply_sentence_replace_impl(
				$resolved['post_id'],
				(string) ( $payload['phrase'] ?? '' ),
				(string) ( $payload['replacement'] ?? '' ),
				(string) ( $change['fingerprint'] ?? '' ),
				(string) ( $payload['context_snippet'] ?? '' ),
				$cb
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => $result['old_content'] ?? '', 'after' => $result['new_content'] ?? '', 'blocks_touched' => 0 ),
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'link_reshape':
			// Audit item 5: tag-boundary movement inside one text node —
			// impl in inc/sn-apply-link-reshape.php, sentence_replace's
			// write-callback contract, its own post-splice identity assert.
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$result      = snt_sn_apply_link_reshape_impl(
				$resolved['post_id'],
				(string) ( $payload['current_anchor'] ?? '' ),
				(string) ( $payload['new_anchor'] ?? '' ),
				(string) ( $change['fingerprint'] ?? '' ),
				(string) ( $payload['context_snippet'] ?? '' ),
				$cb
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => $result['old_content'] ?? '', 'after' => $result['new_content'] ?? '', 'blocks_touched' => 0 ),
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'alt_text':
			$text = (string) ( $payload['text'] ?? $payload['alt_text'] ?? '' );
			if ( 'revision' === $mode ) {
				$staged = snt_sn_apply_stage_meta( $resolved['attachment_id'], '_wp_attachment_image_alt', $text, (string) ( $change['fingerprint'] ?? '' ) );
				if ( is_wp_error( $staged ) ) {
					return $staged;
				}
				return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $text, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $staged );
			}
			$result = snt_ai_alt_apply_impl( $resolved['attachment_id'], $text );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $text, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'surfaces':
			return snt_sn_apply_write_surfaces( $resolved['post_id'], $payload, $mode, (string) ( $change['fingerprint'] ?? '' ) );

		case 'og_card':
			// mode:publish only — see snt_sn_apply_mode_support(). Gate 3
			// already refused 'revision' before this function is ever
			// reached for og_card.
			$result = snt_ability_regenerate_og_card( array( 'post_id' => $resolved['post_id'] ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result['image_url'] ?? null, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'roadmap_board':
			// mode:publish only — see snt_sn_apply_mode_support(). The write
			// impl (inc/sn-apply-roadmap-board.php) shapes its own diff
			// (before = the pre-write effective board).
			return snt_sn_apply_write_roadmap_board( $payload );

		case 'anchor_sweep':
			// mode:publish only — see snt_sn_apply_mode_support().
			$result = snt_ability_anchor_sweep( array() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'create_draft':
			// mode:revision only — see snt_sn_apply_mode_support(). This is
			// create_draft's OWN write mechanism (inc/sn-apply-create-draft.php),
			// never snt_sn_apply_stage_revision() — there is no parent post to
			// stage against, the insert itself IS the (reversible-via-trash)
			// artifact.
			$result = snt_sn_apply_write_create_draft( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ),
				'revision_id'  => null,
				'write_result' => $result,
			);

		case 'delete_draft':
			// mode:revision only — see snt_sn_apply_mode_support(). Trash-only
			// write with its own last-instant draft-status re-check
			// (inc/sn-apply-delete-draft.php).
			$result = snt_sn_apply_write_delete_draft( (int) ( $resolved['post_id'] ?? 0 ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array(
				'ok'           => true,
				'diff'         => array( 'before' => array( 'post_id' => $result['post_id'], 'status' => 'draft' ), 'after' => array( 'post_id' => $result['post_id'], 'status' => 'trash' ), 'blocks_touched' => 0 ),
				'revision_id'  => null,
				'write_result' => $result,
			);

		case 'restore_revision':
			// mode:publish only — see snt_sn_apply_mode_support(). This is
			// session 7's acceptance path (inc/sn-apply-restore-revision.php):
			// ensure a rollback snapshot of the CURRENT live state, restore the
			// requested revision, then apply+clear any staged-meta rows for
			// this post. apply_staged_meta defaults TRUE — see that file's
			// docblock for why (the whole point of this change type is
			// atomically finishing what mode:"revision" started elsewhere).
			return snt_sn_apply_write_restore_revision(
				$resolved['post_id'],
				(int) ( $payload['revision_id'] ?? 0 ),
				array_key_exists( 'apply_staged_meta', $payload ) ? (bool) $payload['apply_staged_meta'] : true
			);

		default:
			return new WP_Error( 'snt_sn_apply_unknown_type', __( 'Unknown change.type.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
}

/**
 * surfaces' write step is split across content (post_excerpt — a real
 * revision-stageable field) and postmeta (meta_description/og_card_title/
 * seo_title/focus_keyword — staged via snt_sn_apply_stage_meta(), NOT
 * postmeta, per session 6a's staged-meta draft queue). Revision mode never
 * regenerates the OG card PNG (og_card_title's side effect in publish mode)
 * — a file write has no revision, same reasoning as the og_card change type
 * itself; `card_regenerated` is always false in revision mode and the
 * response says so explicitly rather than silently omitting it.
 *
 * @param int    $post_id
 * @param array  $payload
 * @param string $mode
 * @param string $fingerprint
 * @return array|WP_Error
 */
function snt_sn_apply_write_surfaces( $post_id, array $payload, $mode, $fingerprint ) {
	if ( 'publish' === $mode ) {
		$input           = $payload;
		$input['post_id'] = $post_id;
		$result           = snt_ability_update_post_surfaces( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $payload, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );
	}

	// mode: revision.
	$revision_id = null;
	$staged_meta = array();
	if ( array_key_exists( 'excerpt', $payload ) ) {
		$cb      = snt_sn_apply_revision_write_callback( $revision_id );
		$post    = get_post( $post_id );
		$before  = $post ? (string) $post->post_excerpt : '';
		$staged  = snt_sn_apply_stage_revision( $post_id, array( 'post_excerpt' => (string) $payload['excerpt'] ) );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$revision_id = (int) $staged['revision_id'];
		unset( $cb, $before );
	}
	foreach ( array( 'meta_description', 'og_card_title', 'seo_title', 'focus_keyword' ) as $meta_field ) {
		if ( array_key_exists( $meta_field, $payload ) ) {
			$meta_key = 'meta_description' === $meta_field ? '_sn_meta_description' : ( 'og_card_title' === $meta_field ? '_sn_og_card_title' : ( 'seo_title' === $meta_field ? '_sn_seo_title' : '_sn_focus_keyword' ) );
			$staged   = snt_sn_apply_stage_meta( $post_id, $meta_key, (string) $payload[ $meta_field ], $fingerprint );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}
			$staged_meta[ $meta_field ] = $staged;
		}
	}

	return array(
		'ok'           => true,
		'diff'         => array( 'before' => null, 'after' => $payload, 'blocks_touched' => 0 ),
		'revision_id'  => $revision_id,
		'write_result' => array( 'ok' => true, 'card_regenerated' => false, 'staged_meta' => $staged_meta ),
	);
}

/**
 * Read-only diff preview for dry_run:true — never writes anything. Content
 * types reuse gate 1's already-computed `new_content` (no second
 * find/replace pass); meta/postmeta types read the CURRENT stored value
 * directly. og_card/anchor_sweep have no textual before/after to preview
 * (a PNG regen and an HTTP dispatch aren't diffable text), so both report
 * null/null rather than fabricating one.
 *
 * @param string $type
 * @param array  $resolved
 * @param array  $change
 * @param array  $gate1 Output of snt_sn_apply_gate1_fingerprint().
 * @return array{before:mixed,after:mixed,blocks_touched:int}
 */
function snt_sn_apply_dry_run_diff( $type, array $resolved, array $change, array $gate1 ) {
	$payload = (array) ( $change['payload'] ?? array() );

	switch ( $type ) {
		case 'block_migration':
		case 'pattern_adoption':
		case 'drift_replace':
		case 'link_insert':
		case 'sentence_replace':
		case 'link_reshape':
			$post   = get_post( $resolved['post_id'] ?? 0 );
			$before = $post ? (string) $post->post_content : '';
			return array(
				'before'         => $before,
				'after'          => $gate1['new_content'] ?? $before,
				'blocks_touched' => in_array( $type, array( 'block_migration', 'pattern_adoption' ), true ) ? 1 : 0,
			);

		case 'alt_text':
			$attachment_id = $resolved['attachment_id'] ?? 0;
			return array(
				'before'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'after'          => (string) ( $payload['text'] ?? $payload['alt_text'] ?? '' ),
				'blocks_touched' => 0,
			);

		case 'surfaces':
			$post_id = $resolved['post_id'] ?? 0;
			$post    = get_post( $post_id );
			$before  = array();
			$after   = array();
			$meta_map = array( 'meta_description' => '_sn_meta_description', 'og_card_title' => '_sn_og_card_title', 'seo_title' => '_sn_seo_title', 'focus_keyword' => '_sn_focus_keyword' );
			if ( array_key_exists( 'excerpt', $payload ) ) {
				$before['excerpt'] = $post ? (string) $post->post_excerpt : '';
				$after['excerpt']  = (string) $payload['excerpt'];
			}
			foreach ( $meta_map as $field => $meta_key ) {
				if ( array_key_exists( $field, $payload ) ) {
					$before[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
					$after[ $field ]  = (string) $payload[ $field ];
				}
			}
			return array( 'before' => $before, 'after' => $after, 'blocks_touched' => 0 );

		case 'create_draft':
			// {title, block_count, word_count} — not a before/after content
			// diff (there is no "before": nothing exists yet). See
			// inc/sn-apply-create-draft.php's snt_sn_apply_create_draft_preview().
			return array(
				'before'         => null,
				'after'          => snt_sn_apply_create_draft_preview( $payload ),
				'blocks_touched' => 0,
			);

		case 'restore_revision':
			// {before, after, fields_changed} — session 7's revision_diff()
			// shape (inc/sn-apply-revision.php), deliberately NOT the
			// {before,after,blocks_touched} shape every other type uses: a
			// full-field restore isn't measured in "blocks touched", and
			// fields_changed is the more honest, more useful preview here.
			// The output_schema's `diff` property is an open object
			// (type:[object,null], no fixed properties), so this shape
			// difference is not a schema violation — documented in
			// FINDINGS.md session 7 as a deliberate, minor deviation.
			$revision_id = (int) ( $payload['revision_id'] ?? 0 );
			$diff        = $revision_id > 0 ? snt_sn_apply_revision_diff( $revision_id ) : null;
			return is_array( $diff ) ? $diff : array( 'before' => null, 'after' => null, 'fields_changed' => array() );

		case 'roadmap_board':
			// before = the CURRENT effective board — deliberately doubling as
			// the type's read surface (see inc/sn-apply-roadmap-board.php's
			// docblock: the observe step is a dry_run call itself).
			return snt_sn_apply_roadmap_board_diff( $change );

		case 'delete_draft':
			// Identity preview, not a content diff — "is this the draft I
			// think it is" (inc/sn-apply-delete-draft.php).
			return snt_sn_apply_delete_draft_diff( $resolved );

		default:
			// og_card, anchor_sweep — no textual diff to preview.
			return array( 'before' => null, 'after' => null, 'blocks_touched' => 0 );
	}
}
