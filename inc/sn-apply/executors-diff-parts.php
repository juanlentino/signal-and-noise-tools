<?php
/**
 * Signal & Noise Tools: sn_apply executors, the surfaces writer and the dry-run diff.
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

require_once __DIR__ . '/../surface-fields.php';

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
	// Read before writing, so the response carries what the write replaced,
	// the same {before, after} the dry run previews (2026-10-29).
	$diff = snt_sn_apply_surfaces_diff( $post_id, $payload );
	if ( 'publish' === $mode ) {
		$input           = $payload;
		$input['post_id'] = $post_id;
		$result           = snt_ability_update_post_surfaces( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'ok' => true, 'diff' => $diff, 'revision_id' => null, 'write_result' => $result );
	}

	// mode: revision.
	$revision_id = null;
	$staged_meta = array();
	if ( array_key_exists( 'excerpt', $payload ) ) {
		$staged = snt_sn_apply_stage_revision( $post_id, array( 'post_excerpt' => (string) $payload['excerpt'] ) );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$revision_id = (int) $staged['revision_id'];
	}
	foreach ( snt_surface_meta_keys() as $meta_field => $meta_key ) {
		if ( array_key_exists( $meta_field, $payload ) ) {
			$staged = snt_sn_apply_stage_meta( $post_id, $meta_key, (string) $payload[ $meta_field ], $fingerprint );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}
			$staged_meta[ $meta_field ] = $staged;
		}
	}

	return array(
		'ok'           => true,
		'diff'         => $diff,
		'revision_id'  => $revision_id,
		'write_result' => array( 'ok' => true, 'card_regenerated' => false, 'staged_meta' => $staged_meta ),
	);
}

/**
 * surfaces' {before, after}: the stored value of every field the payload
 * names, beside the value it asks for. One reader for the dry run and both
 * write modes, so a preview and the write it approves report the same thing.
 * sn-posts reads the meta fields through snt_surface_value() too, so its row
 * and this `before` cannot disagree.
 *
 * @param int   $post_id
 * @param array $payload
 * @return array{before:array,after:array,blocks_touched:int}
 */
function snt_sn_apply_surfaces_diff( $post_id, array $payload ) {
	$before = array();
	$after  = array();
	if ( array_key_exists( 'excerpt', $payload ) ) {
		$post              = get_post( $post_id );
		$before['excerpt'] = $post ? (string) $post->post_excerpt : '';
		$after['excerpt']  = (string) $payload['excerpt'];
	}
	foreach ( array_keys( snt_surface_meta_keys() ) as $field ) {
		if ( array_key_exists( $field, $payload ) ) {
			$before[ $field ] = snt_surface_value( $post_id, $field );
			$after[ $field ]  = (string) $payload[ $field ];
		}
	}
	return array( 'before' => $before, 'after' => $after, 'blocks_touched' => 0 );
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
		case 'unlink':
			$post   = get_post( $resolved['post_id'] ?? 0 );
			$before = $post ? (string) $post->post_content : '';
			return array(
				'before'         => $before,
				'after'          => $gate1['new_content'] ?? $before,
				'blocks_touched' => in_array( $type, array( 'block_migration', 'pattern_adoption' ), true ) ? 1 : 0,
			);

		case 'batch':
			// The COMBINED diff and a SINGLE ledger_impact — the whole point of
			// batching is that the caller sees one editorial act, so the preview
			// must show the end state rather than N intermediate ones. gate 1
			// already ran the shared planner; reuse its content rather than
			// re-planning, so preview and write cannot disagree.
			$post   = get_post( $resolved['post_id'] ?? 0 );
			$before = $post ? (string) $post->post_content : '';
			$n      = is_array( $payload['changes'] ?? null ) ? count( $payload['changes'] ) : 0;

			// v13.95.1 — A REFUSED BATCH HAS NO "AFTER", AND APPLIED NOTHING.
			// gate 1 returns new_content only when the plan SUCCEEDED. The
			// previous `?? $before` fallback diffed the post against itself,
			// which produced prose_changed:false and — worse —
			// ledger_impact:"coalesces", the reading that means "safe, no new
			// version". Paired with changes_applied reporting the REQUESTED
			// count, a refusal rendered as a benign no-op that touched N
			// blocks. Nothing was written either way, but the readout could
			// not be distinguished from a successful restructure.
			if ( null === ( $gate1['new_content'] ?? null ) ) {
				return array(
					'before'            => $before,
					'after'             => null,   // unknown: there is no plan
					'blocks_touched'    => 0,
					'changes_applied'   => 0,
					'changes_requested' => $n,     // what was ASKED for, named as such
					'prose_changed'     => null,
					'prose_added'       => null,
					'prose_removed'     => null,
					'ledger_impact'     => null,   // no plan, so no ledger consequence to report
				);
			}

			$after = (string) $gate1['new_content'];
			return array_merge(
				array(
					'before'            => $before,
					'after'             => $after,
					'blocks_touched'    => $n,
					'changes_applied'   => $n,
					'changes_requested' => $n,
				),
				function_exists( 'snt_sn_apply_block_edit_prose_delta' ) ? snt_sn_apply_block_edit_prose_delta( $before, $after ) : array()
			);

		case 'block_insert':
		case 'block_replace':
		case 'block_delete':
		case 'block_move':
			// v13.2.0 (+ delete/move v13.5.0): the content-family preview PLUS the prose delta — the
			// ledger consequence must be visible BEFORE the write, so the dry
			// run carries the same {prose_changed, prose_added, prose_removed,
			// ledger_impact} fields the real write's diff does (one shared
			// helper, never two computations drifting apart). block_replace
			// additionally reports the replaced block's serialized form —
			// gate 1 only carries new_content, so the (read-only, side-effect
			// free) compute runs once more here to recover it.
			$post   = get_post( $resolved['post_id'] ?? 0 );
			$before = $post ? (string) $post->post_content : '';
			$after  = $gate1['new_content'] ?? $before;
			$diff   = array_merge(
				array( 'before' => $before, 'after' => $after, 'blocks_touched' => 1 ),
				function_exists( 'snt_sn_apply_block_edit_prose_delta' ) ? snt_sn_apply_block_edit_prose_delta( $before, (string) $after ) : array()
			);
			if ( in_array( $type, array( 'block_replace', 'block_delete', 'block_move' ), true ) && null !== ( $gate1['new_content'] ?? null ) ) {
				$computed = snt_sn_apply_block_edit_compute( $before, $type, $payload );
				if ( is_array( $computed ) ) {
					if ( 'block_replace' === $type ) { $diff['replaced_block'] = $computed['replaced_block']; }
					if ( 'block_delete' === $type ) { $diff['removed_block'] = $computed['removed_block'] ?? null; }
					if ( 'block_move' === $type ) { $diff['moved_block'] = $computed['moved_block'] ?? null; }
				}
			}
			return $diff;

		case 'alt_text':
			$attachment_id = $resolved['attachment_id'] ?? 0;
			return array(
				'before'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'after'          => (string) ( $payload['text'] ?? $payload['alt_text'] ?? '' ),
				'blocks_touched' => 0,
			);

		case 'surfaces':
			return snt_sn_apply_surfaces_diff( $resolved['post_id'] ?? 0, $payload );

		case 'create_draft':
			// {title, block_count, word_count} — not a before/after content
			// diff (there is no "before": nothing exists yet). See
			// inc/sn-apply/create-draft.php's snt_sn_apply_create_draft_preview().
			return array(
				'before'         => null,
				'after'          => snt_sn_apply_create_draft_preview( $payload ),
				'blocks_touched' => 0,
			);

		case 'restore_revision':
			// {before, after, fields_changed} — session 7's revision_diff()
			// shape (inc/sn-apply/revision.php), deliberately NOT the
			// {before,after,blocks_touched} shape every other type uses: a
			// full-field restore isn't measured in "blocks touched", and
			// fields_changed is the more honest, more useful preview here.
			// The output_schema's `diff` property is an open object
			// (type:[object,null], no fixed properties), so this shape
			// difference is not a schema violation — documented in
			// FINDINGS.md session 7 as a deliberate, minor deviation.
			$revision_id = (int) ( $payload['revision_id'] ?? 0 );
			$diff        = $revision_id > 0 ? snt_sn_apply_revision_diff( $revision_id ) : null;
			$diff        = is_array( $diff ) ? $diff : array( 'before' => null, 'after' => null, 'fields_changed' => array() );
			// v10.64.0 (threat model R1): acceptance co-publishes the staged-
			// meta queue (apply_staged_meta defaults true), so the dry-run
			// review must SHOW that queue — the diff carries every pending row
			// the write step would apply. Empty array = nothing queued; the
			// pre-index blind spot is inherited and documented at the helper.
			$diff['staged_meta_pending'] = snt_sn_apply_pending_staged_meta_for_post( (int) ( $resolved['post_id'] ?? 0 ) );
			return $diff;

		case 'roadmap_board':
			// before = the CURRENT effective board — deliberately doubling as
			// the type's read surface (see inc/sn-apply/roadmap-board.php's
			// docblock: the observe step is a dry_run call itself).
			return snt_sn_apply_roadmap_board_diff( $change );

		case 'change_class_backfill':
			return snt_sn_apply_change_class_backfill_diff( $change, $gate1 );

		case 'delete_draft':
			// Identity preview, not a content diff — "is this the draft I
			// think it is" (inc/sn-apply/delete-draft.php).
			return snt_sn_apply_delete_draft_diff( $resolved );

		default:
			// og_card, anchor_sweep — no textual diff to preview.
			return array( 'before' => null, 'after' => null, 'blocks_touched' => 0 );
	}
}
