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
 * | drift_replace     |   yes    |   yes   | content field write (substr_replace on post_content); snt_ai_drift_apply_impl()'s injectable write_callback (v10.40.0). |
 * | link_insert       |   yes    |   yes   | same mechanism as drift_replace (snt_ai_link_apply_impl()).           |
 * | alt_text          |   yes    |   yes   | postmeta write (_wp_attachment_image_alt); revision mode uses snt_sn_apply_stage_meta() — session 6a's staged-meta draft queue, not a postmeta write. |
 * | surfaces          |  partial |   yes   | excerpt is a content field (post_excerpt, revision-stageable); meta_description/og_card_title/seo_title/focus_keyword are postmeta (staged via snt_sn_apply_stage_meta()). Revision mode stages ALL of these but never regenerates the OG card PNG — that side effect is publish-only (see og_card below). |
 * | og_card           |    NO    |   yes   | sn_generate_og_card() writes a PNG FILE to disk (inc/og-card-generator.php) — not a post field at all. There is no WordPress revision of a file. Refuses structurally in revision mode, never fakes a staged version. |
 * | anchor_sweep      |    NO    |   yes   | dispatches a bounded wp_remote_post() to the provenance Worker (inc/provenance-webhook.php's sn_prov_run_sweep()) — an external side effect with no post entity involved at all (target is scope:"provenance_anchors", not a post_id). Nothing to stage. |
 * | create_draft      |   yes    |   NO    | insert-only (session 6c, the arc's finale — see inc/sn-apply-create-draft.php's docblock for the full B5c origin): post_status is hard-coded 'draft', post_type hard-coded 'post'. mode:"publish" refuses structurally — this tool never makes a draft live; the owner schedules by hand. mode:"revision" is the one accepted mode, but its OWN write mechanism (snt_sn_apply_write_create_draft(), not snt_sn_apply_stage_revision()) — there is no parent post yet to stage a core revision against; "revision" here just means the request goes through the same dry_run-by-default, human-review-first posture as everywhere else in this tool. |
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

// Session 6c appended 'create_draft' (the arc's finale) — every gate
// function below switches on this list, never a hardcoded count; the
// tests/abilities-sn-apply-delegation-sweep.php ALL-TYPES sweep loops this
// same constant, so it REDs on its own count pin the moment a type is added
// here without a matching sweep-table entry (watched RED, see FINDINGS.md).
const SNT_SN_APPLY_CHANGE_TYPES = array(
	'block_migration', 'pattern_adoption', 'alt_text', 'link_insert',
	'drift_replace', 'surfaces', 'og_card', 'anchor_sweep', 'create_draft',
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
		case 'alt_text':
		case 'surfaces':
			return array( 'modes' => array( 'revision', 'publish' ), 'reason' => null );
		case 'og_card':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'og_card regenerates a PNG file on disk (sn_generate_og_card()) — not a post field. There is no WordPress revision of a file; this change type is publish-only.',
			);
		case 'anchor_sweep':
			return array(
				'modes'  => array( 'publish' ),
				'reason' => 'anchor_sweep dispatches a live HTTP call to the provenance Worker (sn_prov_run_sweep()) — an external side effect with no post entity to stage a revision of. Publish-only.',
			);
		case 'create_draft':
			return array(
				'modes'  => array( 'revision' ),
				'reason' => 'create_draft can never publish — post_status is hard-coded to "draft" and this tool will never make it live. Drafts are scheduled by hand; that manual step is the human review gate. This change type only supports mode:"revision", which performs the actual (reversible via rollback:delete_draft) draft insert directly — there is no live post yet to stage a WordPress core revision against.',
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

		case 'drift_replace':
		case 'link_insert':
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$fp          = (string) ( $change['fingerprint'] ?? '' );
			if ( 'drift_replace' === $type ) {
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

		default:
			// og_card, anchor_sweep — no textual diff to preview.
			return array( 'before' => null, 'after' => null, 'blocks_touched' => 0 );
	}
}
