<?php
/**
 * Signal & Noise Tools: sn_apply executors, the write dispatch (one function, moved whole).
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
			// v10.66.0: N candidates in ONE post produce ONE write. Without this,
			// a two-candidate post took two calls, two wp_update_post()s and — for
			// a Note — two anchored ledger versions for one logical edit.
			if ( isset( $payload['edits'] ) ) {
				$result = snt_sn_apply_batch_edits_impl( $resolved['post_id'], $type, $payload['edits'], $fp, $cb );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'ok'           => true,
					'diff'         => array( 'before' => $result['old_content'], 'after' => $result['new_content'], 'blocks_touched' => 0, 'edits_applied' => $result['count'] ),
					'revision_id'  => $revision_id,
					'write_result' => $result,
				);
			}
			if ( 'emdash_replace' === $type ) {
				// v10.65.2: its own named impl, which preserves the replacement's edge
				// whitespace. Delegating straight to the drift impl trimmed ': ' to ':'
				// and shipped `reach for:the studio` to a live page.
				$result = snt_emdash_apply_impl( $resolved['post_id'], (string) ( $payload['phrase'] ?? '' ), (int) ( $payload['position'] ?? -1 ), (string) ( $payload['replacement'] ?? '' ), $fp, (string) ( $payload['context_snippet'] ?? '' ), $cb );
			} elseif ( 'drift_replace' === $type ) {
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
			// inc/sn-apply/sentence-replace.php; whole-post content_hash
			// fingerprint, plain-prose splice, same write-callback contract
			// as the drift family above.
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			// v10.66.0: multi-edit form. One content_hash covers the whole batch —
			// the caller proves it is editing the post as it currently exists, and
			// every edit is located against that same original.
			if ( isset( $payload['edits'] ) ) {
				$result = snt_sn_apply_batch_edits_impl( $resolved['post_id'], $type, $payload['edits'], (string) ( $change['fingerprint'] ?? '' ), $cb );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array(
					'ok'           => true,
					'diff'         => array( 'before' => $result['old_content'], 'after' => $result['new_content'], 'blocks_touched' => 0, 'edits_applied' => $result['count'] ),
					'revision_id'  => $revision_id,
					'write_result' => $result,
				);
			}
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
			// impl in inc/sn-apply/link-reshape.php, sentence_replace's
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

		case 'batch':
			// v13.94.0: N heterogeneous changes, ONE wp_update_post(), ONE ledger
			// version. The impl carries the same write-callback seam every body
			// type uses (so mode:"revision" stages identically) and the SHARED
			// scheduled-post guard, so a batch can no more publish a scheduled
			// note early than a single block edit can.
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$result      = snt_sn_apply_batch_changes_impl(
				$resolved['post_id'],
				(array) ( $payload['changes'] ?? array() ),
				(string) ( $change['fingerprint'] ?? '' ),
				$cb
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$diff = array_merge(
				array(
					'before'          => $result['old_content'] ?? '',
					'after'           => $result['new_content'] ?? '',
					'blocks_touched'  => (int) ( $result['count'] ?? 0 ),
					'changes_applied' => (int) ( $result['count'] ?? 0 ),
				),
				is_array( $result['prose_delta'] ?? null ) ? $result['prose_delta'] : array()
			);
			return array(
				'ok'           => true,
				'diff'         => $diff,
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'block_insert':
		case 'block_replace':
		case 'block_delete':
		case 'block_move':
			// v13.2.0 (+ delete/move v13.5.0): the caller-composed block edit family — impl in
			// inc/sn-apply/block-edit.php, sentence_replace's write-callback
			// contract, plus the scheduled-post status/date guard and the
			// prose-delta report (the ledger consequence, visible in the diff
			// of the REAL write exactly as in the dry run's).
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$result      = snt_sn_apply_block_edit_impl(
				$resolved['post_id'],
				$type,
				$payload,
				(string) ( $change['fingerprint'] ?? '' ),
				$cb
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$diff = array_merge(
				array( 'before' => $result['old_content'] ?? '', 'after' => $result['new_content'] ?? '', 'blocks_touched' => 1 ),
				is_array( $result['prose_delta'] ?? null ) ? $result['prose_delta'] : array()
			);
			if ( 'block_replace' === $type ) {
				$diff['replaced_block'] = $result['replaced_block'] ?? null;
			}
			if ( 'block_delete' === $type ) {
				$diff['removed_block'] = $result['removed_block'] ?? null;
			}
			if ( 'block_move' === $type ) {
				$diff['moved_block'] = $result['moved_block'] ?? null;
			}
			return array(
				'ok'           => true,
				'diff'         => $diff,
				'revision_id'  => $revision_id,
				'write_result' => $result,
			);

		case 'unlink':
			// v10.59.0: link_reshape's sibling — same module, same contracts.
			$revision_id = null;
			$cb          = 'revision' === $mode ? snt_sn_apply_revision_write_callback( $revision_id ) : null;
			$result      = snt_sn_apply_unlink_impl(
				$resolved['post_id'],
				(string) ( $payload['anchor_text'] ?? '' ),
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
			// impl (inc/sn-apply/roadmap-board.php) shapes its own diff
			// (before = the pre-write effective board).
			return snt_sn_apply_write_roadmap_board( $payload );

		case 'change_class_backfill':
			// mode:publish only; the write re-plans and re-checks the fingerprint.
			return snt_sn_apply_write_change_class_backfill( $change );

		case 'merge_tags':
			// mode:publish only. Routes to the REAL ability, never a
			// re-implementation; gate 2 has already fenced the payload.
			$result = snt_ability_merge_tags( array(
				'from_slugs' => (array) ( $payload['from_slugs'] ?? array() ),
				'into_slug'  => (string) ( $payload['into_slug'] ?? '' ),
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'clear_template_overrides':
			// mode:publish only. The ability takes NO input — its input_schema's
			// properties map is empty, so inventing a payload here would invent
			// a contract.
			$result = snt_ability_clear_template_overrides();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'schedule_cron_event':
			// mode:publish only. Routes to the REAL ability, which carries the
			// SN-owned bound and every refusal — this never calls
			// wp_schedule_single_event() itself.
			$result = snt_ability_schedule_cron_event( array(
				'hook'  => (string) ( $payload['hook'] ?? '' ),
				'args'  => (array) ( $payload['args'] ?? array() ),
				'delay' => (int) ( $payload['delay'] ?? 0 ),
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'dismiss':
			// mode:publish only — see snt_sn_apply_mode_support(). Routes to the
			// REAL ability (never a re-implementation), whose own per-surface
			// impls own the store. Gate 2 has already fenced the surface enum
			// and the required payload keys.
			$result = snt_ability_dismiss_candidate( array(
				'surface'           => (string) ( $payload['surface'] ?? '' ),
				'post_id'           => (int) ( $resolved['post_id'] ?? 0 ),
				'block_fingerprint' => (string) ( $payload['block_fingerprint'] ?? '' ),
				'candidate_type'    => (string) ( $payload['candidate_type'] ?? '' ),
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'anchor_sweep':
			// mode:publish only — see snt_sn_apply_mode_support().
			$result = snt_ability_anchor_sweep( array() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'ok' => true, 'diff' => array( 'before' => null, 'after' => $result, 'blocks_touched' => 0 ), 'revision_id' => null, 'write_result' => $result );

		case 'create_draft':
			// mode:revision only — see snt_sn_apply_mode_support(). This is
			// create_draft's OWN write mechanism (inc/sn-apply/create-draft.php),
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
			// (inc/sn-apply/delete-draft.php).
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
			// session 7's acceptance path (inc/sn-apply/restore-revision.php):
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

