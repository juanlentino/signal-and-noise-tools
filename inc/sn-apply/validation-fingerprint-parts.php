<?php
/**
 * Signal & Noise Tools: sn_apply gates 1 and 2, gate 1, the fingerprint (one function, moved whole).
 *
 * Split from inc/sn-apply/validation.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate 1: fingerprint. Reuses each absorbed impl's OWN fingerprint scheme —
 * never a parallel one. Types with no fingerprint scheme in the absorbed
 * impl (alt_text: "attachment alt is not versioned, last-write-wins" per
 * inc/ai-alt-text-suggest.php's own docblock; surfaces: update-post-surfaces
 * has no candidate fingerprint at all, only a per-post throttle; og_card /
 * anchor_sweep: no candidate concept exists for either) report a `skipped`
 * reason rather than fabricating a check that would always trivially pass.
 *
 * @param string $type
 * @param array  $resolved Output of snt_sn_apply_resolve_target().
 * @param array  $change   The raw change{} input (payload/fingerprint).
 * @return array{passed:bool,expected:?string,observed:?string,skipped:?string,detail:?string,new_content:?string}
 */
function snt_sn_apply_gate1_fingerprint( $type, array $resolved, array $change ) {
	$fingerprint = isset( $change['fingerprint'] ) ? (string) $change['fingerprint'] : '';
	$payload     = (array) ( $change['payload'] ?? array() );

	switch ( $type ) {
		case 'block_migration':
		case 'pattern_adoption':
			$post = get_post( $resolved['post_id'] );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$blocks = parse_blocks( (string) $post->post_content );
			$found  = function_exists( 'snt_block_fp_find' ) ? snt_block_fp_find( $blocks, $fingerprint, (int) $resolved['post_id'] ) : null;
			if ( null === $found ) {
				return array(
					'passed'      => false,
					'expected'    => $fingerprint,
					'observed'    => null, // No single "current fingerprint" exists for a whole post — this is per-BLOCK. Re-scan is the only re-derivation path.
					'skipped'     => null,
					'detail'      => 'Block changed or removed since scan. Re-run sn_scan for a fresh block_fingerprint.',
					'new_content' => null,
				);
			}
			// Also resolve the full post-replacement content here (reusing the
			// engine's own find/sanitize/replace primitives, never a second
			// copy) so gate 2's body check sees exactly what the write would
			// produce, without a second fingerprint-matching pass.
			$computed = function_exists( 'snt_sn_apply_compute_block_replacement' )
				? snt_sn_apply_compute_block_replacement( $resolved['post_id'], $fingerprint, (string) ( $payload['replacement_markup'] ?? '' ) )
				: null;
			$new_content = ( is_array( $computed ) && isset( $computed['new_content'] ) ) ? $computed['new_content'] : null;
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $fingerprint, 'skipped' => null, 'detail' => null, 'new_content' => $new_content );

		case 'emdash_replace':
		case 'drift_replace':
		case 'link_insert':
			$post = get_post( $resolved['post_id'] );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$content = (string) $post->post_content;
			// v10.66.0, multi-edit form: the preview must be the WHOLE batch, so
			// the diff shows the state that will actually be written and gate 2
			// validates that same body. It runs the identical planner the write
			// path uses — a preview that models the write instead of sharing it is
			// how `reach for:the studio` reached a live page.
			if ( isset( $payload['edits'] ) ) {
				$plan = snt_sn_apply_plan_batch_edits( $content, $type, $payload['edits'] );
				if ( is_wp_error( $plan ) ) {
					// 2026-10-26: the planner's own code and status, as sentence_replace
					// has since v13.95.1. Its 409s (a moved phrase, a stale per-edit
					// fingerprint) stay the fingerprint's failure; anything else is a
					// payload conflict, reported as a validation finding with its 422.
					// An overlap or an active-content splice is refused only after every
					// per-edit fingerprint matched, so the fingerprint gate passed;
					// the earlier refusals (shape, count, ambiguity) never reached it.
					$plan_data = (array) $plan->get_error_data();
					$status    = (int) ( $plan_data['status'] ?? 422 );
					$refusal   = array(
						'passed'       => false,
						'expected'     => $fingerprint,
						'observed'     => null,
						'skipped'      => null,
						'detail'       => $plan->get_error_message(),
						'new_content'  => null,
						'error_code'   => $plan->get_error_code(),
						'error_status' => $status,
					);
					if ( 409 !== $status ) {
						$refusal['plan_error'] = $plan->get_error_message();
						if ( in_array( $plan->get_error_code(), array( 'snt_sn_apply_batch_overlap', 'snt_sn_apply_active_content' ), true ) ) {
							$refusal['fingerprint_ok'] = true;
						} else {
							$refusal['skipped'] = 'plan_refused';
						}
					}
					return $refusal;
				}
				// Per-edit fingerprints were each verified inside the planner, so
				// there is no single expected/observed pair to report here.
				return array( 'passed' => true, 'expected' => null, 'observed' => null, 'skipped' => null, 'detail' => sprintf( '%d edits verified against the live content.', (int) $plan['count'] ), 'new_content' => $plan['new_content'] );
			}
			// emdash_replace shares drift_replace's payload shape (phrase/position/
			// replacement/context_snippet), so it shares this branch verbatim.
			$phrase  = 'link_insert' === $type ? (string) ( $payload['anchor'] ?? '' ) : (string) ( $payload['phrase'] ?? '' );
			$context = (string) ( $payload['context_snippet'] ?? '' );
			$raw_pos = function_exists( 'snt_ai_drift_locate_in_raw' ) ? snt_ai_drift_locate_in_raw( $content, $phrase, $context ) : -1;
			if ( -1 === $raw_pos ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'Phrase/anchor no longer present in post content. Re-run scan.', 'new_content' => null );
			}
			$observed = function_exists( 'snt_ai_drift_fingerprint' ) ? snt_ai_drift_fingerprint( $content, $phrase, $raw_pos ) : '';
			$passed   = ( '' !== $fingerprint && $fingerprint === $observed );
			$new_content = null;
			if ( $passed ) {
				// Mirrors the exact splice each real apply impl performs —
				// link_insert wraps the anchor in <a>, drift_replace swaps
				// the phrase text. Read-only preview: no write happens here.
				$replacement_text = 'link_insert' === $type
					? ( '<a href="' . esc_url( (string) ( $payload['target_url'] ?? '' ) ) . '">' . $phrase . '</a>' )
					: trim( (string) ( $payload['replacement'] ?? '' ) );
				$new_content = substr_replace( $content, $replacement_text, $raw_pos, strlen( $phrase ) );
			}
			return array( 'passed' => $passed, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => $passed ? null : 'Post changed since suggest/scan. Re-run scan to refresh.', 'new_content' => $new_content );

		case 'batch':
			// v13.94.0 — the heterogeneous batch. ONE whole-post content_hash
			// covers every change in the list by construction: each claim is
			// located against exactly the content that hash names. The planner
			// that runs here is the SAME one the write path runs — a preview that
			// models the write instead of sharing it is how `reach for:the studio`
			// reached a live page.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for batch: pass the content_hash observed via sn_posts for this post before proposing the changes.',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			if ( ! hash_equals( $observed, $fingerprint ) ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).', 'new_content' => null );
			}
			$plan = snt_sn_apply_plan_changes( (string) $post->post_content, $payload['changes'] ?? array() );
			if ( is_wp_error( $plan ) ) {
				$plan_data = (array) $plan->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $plan->get_error_message(),
					'new_content'  => null,
					'error_code'   => $plan->get_error_code(),
					'error_status' => (int) ( $plan_data['status'] ?? 422 ),
					// v13.95.1 — THE FINGERPRINT MATCHED. Only the plan failed.
					// Without this the response reported fingerprint.passed:false
					// with expected and observed IDENTICAL — a self-contradictory
					// readout that sends the caller to re-fetch a hash that was
					// never stale. The conflict is a VALIDATION failure and is
					// surfaced as one; see snt_sn_apply_apply_one().
					'fingerprint_ok' => true,
					'plan_error'     => $plan->get_error_message(),
				);
			}
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => sprintf( '%d changes verified against the live content.', (int) $plan['count'] ), 'new_content' => $plan['new_content'] );

		case 'sentence_replace':
			// The agent-composed body edit: fingerprint is the LIVE row's
			// content_hash — restore_revision's binding exactly (see that
			// case below for the array_key_exists() rationale) — because no
			// scan/suggest pipeline mints a positional fingerprint for an
			// edit the caller composed itself. REQUIRED: missing is a 422
			// caller error, mismatched is the 409 stale-branch conflict.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			// The multi-edit form carries no top-level phrase/replacement — each
			// edit is pair-checked inside the planner instead, below, once the
			// whole-post fingerprint has been proven.
			$pair = ( function_exists( 'snt_sn_apply_sentence_pair_error' ) && ! isset( $payload['edits'] ) )
				? snt_sn_apply_sentence_pair_error( (string) ( $payload['phrase'] ?? '' ), (string) ( $payload['replacement'] ?? '' ) )
				: true;
			if ( is_wp_error( $pair ) ) {
				$pair_data = (array) $pair->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => null,
					'skipped'      => null,
					'detail'       => $pair->get_error_message(),
					'new_content'  => null,
					'error_code'   => $pair->get_error_code(),
					'error_status' => (int) ( $pair_data['status'] ?? 422 ),
				);
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for sentence_replace: pass the content_hash observed via sn_posts for this post before proposing the edit.',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			if ( ! hash_equals( $observed, $fingerprint ) ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).', 'new_content' => null );
			}
			$content = (string) $post->post_content;
			// v10.66.0, multi-edit form: one content_hash (already proven above)
			// covers the batch; the planner pair-checks and locates every edit
			// against this same original content.
			if ( isset( $payload['edits'] ) ) {
				$plan = snt_sn_apply_plan_batch_edits( $content, $type, $payload['edits'] );
				if ( is_wp_error( $plan ) ) {
					$plan_data = (array) $plan->get_error_data();
					return array(
						'passed'       => false,
						'expected'     => $fingerprint,
						'observed'     => $observed,
						'skipped'      => null,
						'detail'       => $plan->get_error_message(),
						'new_content'  => null,
						'error_code'   => $plan->get_error_code(),
						'error_status' => (int) ( $plan_data['status'] ?? 422 ),
						// v13.95.1 — same correction as the batch branch below: the
						// whole-post hash was PROVEN above, so a planner refusal must
						// not report the fingerprint as the thing that failed.
						'fingerprint_ok' => true,
						'plan_error'     => $plan->get_error_message(),
					);
				}
				return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => sprintf( '%d edits verified against the live content.', (int) $plan['count'] ), 'new_content' => $plan['new_content'] );
			}
			$raw_pos = function_exists( 'snt_ai_drift_locate_in_raw' ) ? snt_ai_drift_locate_in_raw( $content, (string) ( $payload['phrase'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) ) : -1;
			if ( -1 === $raw_pos ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Phrase not found in post content — the match is byte-exact, punctuation and quotes included. Copy the span verbatim from sn_posts\' content field.', 'new_content' => null );
			}
			$phrase      = (string) ( $payload['phrase'] ?? '' );
			$new_content = substr_replace( $content, trim( (string) ( $payload['replacement'] ?? '' ) ), $raw_pos, strlen( $phrase ) );
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => null, 'new_content' => $new_content );

		case 'link_reshape':
			// Audit item 5 (owner-confirmed after item 4): sentence_replace's
			// binding exactly — the LIVE content_hash, REQUIRED (missing 422,
			// stale 409) — plus this type's own pair/locate refusals, each
			// surfaced with its own error_code/error_status override so a 422
			// caller error never masquerades as a 409 conflict.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$pair = function_exists( 'snt_sn_apply_link_reshape_pair_error' )
				? snt_sn_apply_link_reshape_pair_error( (string) ( $payload['current_anchor'] ?? '' ), (string) ( $payload['new_anchor'] ?? '' ) )
				: true;
			if ( is_wp_error( $pair ) ) {
				$pair_data = (array) $pair->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => null,
					'skipped'      => null,
					'detail'       => $pair->get_error_message(),
					'new_content'  => null,
					'error_code'   => $pair->get_error_code(),
					'error_status' => (int) ( $pair_data['status'] ?? 422 ),
				);
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for link_reshape: pass the content_hash observed via sn_posts for this post before proposing the reshape.',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			if ( ! hash_equals( $observed, $fingerprint ) ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).', 'new_content' => null );
			}
			$content = (string) $post->post_content;
			$match   = snt_sn_apply_link_reshape_locate( $content, (string) ( $payload['current_anchor'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) );
			if ( is_wp_error( $match ) ) {
				$match_data = (array) $match->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $match->get_error_message(),
					'new_content'  => null,
					'error_code'   => $match->get_error_code(),
					'error_status' => (int) ( $match_data['status'] ?? 409 ),
				);
			}
			$new_content = snt_sn_apply_link_reshape_compute( $content, $match, (string) ( $payload['current_anchor'] ?? '' ), (string) ( $payload['new_anchor'] ?? '' ) );
			if ( is_wp_error( $new_content ) ) {
				$compute_data = (array) $new_content->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $new_content->get_error_message(),
					'new_content'  => null,
					'error_code'   => $new_content->get_error_code(),
					'error_status' => (int) ( $compute_data['status'] ?? 500 ),
				);
			}
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => null, 'new_content' => $new_content );

		case 'unlink':
			// v10.59.0 — link_reshape's promised sibling, same gate shape:
			// anchor validator (422 overrides), REQUIRED live content_hash,
			// shared locator, identity-asserting compute.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$anchor_ok = function_exists( 'snt_sn_apply_unlink_anchor_error' )
				? snt_sn_apply_unlink_anchor_error( (string) ( $payload['anchor_text'] ?? '' ) )
				: true;
			if ( is_wp_error( $anchor_ok ) ) {
				$anchor_data = (array) $anchor_ok->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => null,
					'skipped'      => null,
					'detail'       => $anchor_ok->get_error_message(),
					'new_content'  => null,
					'error_code'   => $anchor_ok->get_error_code(),
					'error_status' => (int) ( $anchor_data['status'] ?? 422 ),
				);
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for unlink: pass the content_hash observed via sn_posts for this post before proposing the unlink.',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			if ( ! hash_equals( $observed, $fingerprint ) ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).', 'new_content' => null );
			}
			$content = (string) $post->post_content;
			$match   = snt_sn_apply_link_reshape_locate( $content, (string) ( $payload['anchor_text'] ?? '' ), (string) ( $payload['context_snippet'] ?? '' ) );
			if ( is_wp_error( $match ) ) {
				$match_data = (array) $match->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $match->get_error_message(),
					'new_content'  => null,
					'error_code'   => $match->get_error_code(),
					'error_status' => (int) ( $match_data['status'] ?? 409 ),
				);
			}
			$new_content = snt_sn_apply_unlink_compute( $content, $match, (string) ( $payload['anchor_text'] ?? '' ) );
			if ( is_wp_error( $new_content ) ) {
				$compute_data = (array) $new_content->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $new_content->get_error_message(),
					'new_content'  => null,
					'error_code'   => $new_content->get_error_code(),
					'error_status' => (int) ( $compute_data['status'] ?? 500 ),
				);
			}
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => null, 'new_content' => $new_content );

		case 'block_insert':
		case 'block_replace':
		case 'block_delete':
		case 'block_move':
			// v13.2.0 (+ delete/move v13.5.0) — the caller-composed BLOCK edit family: link_reshape's
			// gate shape exactly. Payload/markup refusals are 422 caller
			// errors surfaced BEFORE the fingerprint check (each with its own
			// error_code/error_status override); the fingerprint is the LIVE
			// content_hash, REQUIRED (missing 422, stale 409); locate/compute
			// refusals carry their own codes (anchor_not_found 409,
			// ambiguous/boundary/in_delimiter 422). Success hands gate 2 the
			// exact content the write would produce.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$payload_ok = function_exists( 'snt_sn_apply_block_edit_payload_error' )
				? snt_sn_apply_block_edit_payload_error( $type, $payload )
				: true;
			if ( is_wp_error( $payload_ok ) ) {
				$payload_data = (array) $payload_ok->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => null,
					'skipped'      => null,
					'detail'       => $payload_ok->get_error_message(),
					'new_content'  => null,
					'error_code'   => $payload_ok->get_error_code(),
					'error_status' => (int) ( $payload_data['status'] ?? 422 ),
				);
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => sprintf( 'change.fingerprint is required for %s: pass the content_hash observed via sn_posts for this post before proposing the edit.', $type ),
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			if ( ! hash_equals( $observed, $fingerprint ) ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).', 'new_content' => null );
			}
			$computed = snt_sn_apply_block_edit_compute( (string) $post->post_content, $type, $payload );
			if ( is_wp_error( $computed ) ) {
				$compute_data = (array) $computed->get_error_data();
				return array(
					'passed'       => false,
					'expected'     => $fingerprint,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => $computed->get_error_message(),
					'new_content'  => null,
					'error_code'   => $computed->get_error_code(),
					'error_status' => (int) ( $compute_data['status'] ?? 422 ),
				);
			}
			return array( 'passed' => true, 'expected' => $fingerprint, 'observed' => $observed, 'skipped' => null, 'detail' => null, 'new_content' => $computed['new_content'] );

		case 'restore_revision':
			// Session 7 — a REAL fingerprint scheme (not skipped): binds to
			// the LIVE row's current content_hash, the SAME scheme
			// signal-noise/sn-posts exposes to callers (snt_corpus_content_hash(),
			// inc/corpus-inspect.php — REUSED, never a parallel hash). A
			// restore proposed against a since-edited post is the
			// stale-branch merge conflict this gate exists to catch. Unlike
			// every other type here, the fingerprint is REQUIRED — a missing
			// one is a 422 caller error (`error_code`/`error_status`, read
			// directly by inc/abilities-sn-apply.php's snt_sn_apply_apply_one()
			// to override the generic 409-on-any-gate1-failure default),
			// never treated the same as a stale/mismatched one (409).
			//
			// Review HIGH (REJECT #10): "missing" MUST be detected via
			// array_key_exists(), never via the shared $fingerprint local
			// above (built with isset(), which collapses "key absent" and
			// "key present but ''" into the same ''). snt_corpus_content_hash()
			// (inc/corpus-inspect.php) returns '' — not md5('') — for an
			// empty/whitespace post_content, which is EXACTLY the value
			// sn_posts exposes as content_hash for a blanked live post. Under
			// the isset()-based collapse, a caller who correctly observed and
			// passed fingerprint:'' for a blanked post could never reach the
			// comparison at all — every explicit '' was silently reinterpreted
			// as "missing" (422), making the disaster-recovery restore (this
			// gate's own advertised use, FINDINGS.md's "decisive fact")
			// structurally impossible. array_key_exists() is this codebase's
			// documented present-vs-null idiom; using it here lets an
			// explicitly-passed '' reach hash_equals() like any other value,
			// while a genuinely absent key still 422s.
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$observed             = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			$fingerprint_provided = array_key_exists( 'fingerprint', $change );
			if ( ! $fingerprint_provided ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for restore_revision: pass the content_hash observed via sn_posts for this post before proposing a restore.',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			$passed = hash_equals( $observed, $fingerprint );
			return array(
				'passed'      => $passed,
				'expected'    => $fingerprint,
				'observed'    => $observed,
				'skipped'     => null,
				'detail'      => $passed ? null : 'Live post content has changed since this fingerprint was observed (re-fetch sn_posts\' content_hash and retry: the stale-branch merge conflict).',
				'new_content' => null,
			);

		case 'change_class_backfill':
			// The plan fingerprint (inc/sn-apply/change-class-backfill-parts.php).
			return snt_sn_apply_gate1_change_class_backfill( $change );

		case 'roadmap_board':
			// The board-as-data type (inc/sn-apply/roadmap-board.php): a REAL
			// fingerprint scheme binding to the CURRENT effective board's
			// hash — required (422 when absent via array_key_exists, the
			// restore_revision idiom), stale is the 409 merge conflict.
			return snt_sn_apply_gate1_roadmap_board( $change );

		case 'delete_draft':
			// restore_revision's exact binding + idiom: REQUIRED fingerprint =
			// the draft's CURRENT content_hash (sn_posts' scheme). Trashing a
			// draft someone edited since it was created is the stale-branch
			// conflict; a missing fingerprint is a 422 caller error, and
			// `observed` is always reported so a dry_run:true call doubles as
			// the fingerprint read (the roadmap_board observe idiom —
			// create_draft's rollback object also carries it directly).
			$post = get_post( $resolved['post_id'] ?? 0 );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$observed = function_exists( 'snt_corpus_content_hash' ) ? snt_corpus_content_hash( (string) $post->post_content ) : md5( trim( (string) $post->post_content ) );
			if ( ! array_key_exists( 'fingerprint', $change ) ) {
				return array(
					'passed'       => false,
					'expected'     => null,
					'observed'     => $observed,
					'skipped'      => null,
					'detail'       => 'change.fingerprint is required for delete_draft: pass the content_hash from create_draft\'s rollback object, from sn_posts, or from this response\'s gates.fingerprint.observed (a dry_run:true call is the read step).',
					'new_content'  => null,
					'error_code'   => 'snt_sn_apply_missing_fingerprint',
					'error_status' => 422,
				);
			}
			$passed = hash_equals( $observed, $fingerprint );
			return array(
				'passed'      => $passed,
				'expected'    => $fingerprint,
				'observed'    => $observed,
				'skipped'     => null,
				'detail'      => $passed ? null : 'The draft\'s content has changed since this fingerprint was observed (re-fetch its content_hash and retry: the stale-branch merge conflict).',
				'new_content' => null,
			);

		default:
			// alt_text, surfaces, og_card, anchor_sweep — no fingerprint
			// scheme exists in the absorbed impl (see docblock above).
			return array( 'passed' => true, 'expected' => null, 'observed' => null, 'skipped' => 'no_fingerprint_scheme', 'detail' => null, 'new_content' => null );
	}
}

