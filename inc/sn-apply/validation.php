<?php
/**
 * Signal & Noise Tools — sn_apply gates 1 (fingerprint) and 2 (server-side
 * validation). MCP consolidation session 6b.
 *
 * Split out of inc/sn-apply/executors.php purely for the 450-line file
 * budget — these three functions are still part of the same per-change-type
 * executor layer (inc/sn-apply/executors.php holds target resolution, the
 * mode-support matrix, and the write dispatch; inc/abilities-sn-apply.php
 * orchestrates all four gates in order).
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v13.49.0. schedule_cron_event's delay ceiling, in seconds. Declared HERE
// rather than reused from WP because this module is deliberately free of
// WordPress constants — every gate in it loads under the standalone test
// harness, where HOUR_IN_SECONDS does not exist. snt_cron_schedule_event_impl()
// clamps to the same ceiling via HOUR_IN_SECONDS, and a test pins the two equal
// so they cannot drift apart silently.
const SNT_SN_APPLY_CRON_DELAY_MAX = 3600;

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
					return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => $plan->get_error_message(), 'new_content' => null );
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

/**
 * Gate 2 for `merge_tags`.
 *
 * Mirrors the ability's OWN required input (`from_slugs`, `into_slug`, read from
 * inc/abilities-content.php) rather than anything inferred from the change
 * type's name — the mistake that produced two wrong `dismiss` designs before
 * this one.
 *
 * @param array $change
 * @return array
 */
function snt_sn_apply_gate2_merge_tags( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'merge_tags|' . (string) ( $payload['into_slug'] ?? '' );

	$from = $payload['from_slugs'] ?? null;
	if ( ! is_array( $from ) || empty( $from ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'payload_complete',
			'error',
			__( 'merge_tags requires payload.from_slugs (a non-empty array of term slugs).', 'signal-and-noise-tools' ),
			null,
			'from_slugs',
			array(),
			$identity
		);
	}
	if ( '' === (string) ( $payload['into_slug'] ?? '' ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'payload_complete',
			'error',
			__( 'merge_tags requires payload.into_slug.', 'signal-and-noise-tools' ),
			null,
			'into_slug',
			array(),
			$identity
		);
	}
	// Merging a term into ITSELF is a no-op that would still delete the source:
	// refuse rather than reassign-then-delete the same term.
	if ( is_array( $from ) && in_array( (string) ( $payload['into_slug'] ?? '' ), array_map( 'strval', $from ), true ) ) {
		$findings[] = snt_sn_validate_finding(
			'merge_tags',
			'no_self_merge',
			'error',
			__( 'into_slug appears in from_slugs: merging a term into itself would delete it.', 'signal-and-noise-tools' ),
			(string) ( $payload['into_slug'] ?? '' ),
			'a slug not present in from_slugs',
			array(),
			$identity
		);
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

/**
 * Gate 2 for `schedule_cron_event`.
 *
 * Mirrors the ability's OWN input (`hook`, optional `args`, optional `delay`,
 * read from inc/abilities-cron.php) rather than anything inferred from the
 * change type's name. The SN-owned bound itself is NOT re-checked here — that
 * lives in snt_cron_schedule_event_impl() and would drift if copied. Gate 2
 * fences the payload's SHAPE; the impl owns which hooks are schedulable.
 *
 * @since 13.49.0
 * @param array $change
 * @return array
 */
function snt_sn_apply_gate2_schedule_cron_event( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'schedule_cron_event|' . (string) ( $payload['hook'] ?? '' );

	if ( '' === (string) ( $payload['hook'] ?? '' ) ) {
		$findings[] = snt_sn_validate_finding(
			'schedule_cron_event',
			'payload_complete',
			'error',
			__( 'schedule_cron_event requires payload.hook.', 'signal-and-noise-tools' ),
			null,
			'hook',
			array(),
			$identity
		);
	}
	if ( isset( $payload['args'] ) && ! is_array( $payload['args'] ) ) {
		$findings[] = snt_sn_validate_finding(
			'schedule_cron_event',
			'payload_shape',
			'error',
			__( 'payload.args must be an array when supplied — cron matches events by exact args signature.', 'signal-and-noise-tools' ),
			null,
			'args',
			array(),
			$identity
		);
	}
	// Bound the delay HERE as well as in the impl, because a caller reading the
	// dry run must see the refusal rather than a silently clamped booking.
	if ( isset( $payload['delay'] ) ) {
		$delay = $payload['delay'];
		if ( ! is_numeric( $delay ) || (int) $delay < 0 || (int) $delay > SNT_SN_APPLY_CRON_DELAY_MAX ) {
			$findings[] = snt_sn_validate_finding(
				'schedule_cron_event',
				'delay_in_range',
				'error',
				__( 'payload.delay must be between 0 and 3600 seconds.', 'signal-and-noise-tools' ),
				is_scalar( $delay ) ? (string) $delay : null,
				'0-3600',
				array(),
				$identity
			);
		}
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

/**
 * Gate 2 for `dismiss`.
 *
 * THE CONTRACT IS THE ABILITY'S, NOT THE TOOL'S. dismiss-candidate
 * (inc/abilities-dismiss.php) requires surface + post_id + block_fingerprint +
 * candidate_type. It does NOT take sn-scan's candidate_id — an earlier design
 * assumed it did, and assumed the candidate_id was therefore the staleness
 * binding. Both were wrong; the payload mirrors the real input_schema.
 *
 * THE SURFACE ENUM IS EXACTLY THREE. sn-scan has NINE scan_types, so six of
 * them have no dismissal store at all (sn-scan's own description says so). A
 * surface with no store must refuse BY NAME: a silent no-op would report a
 * dismissal that was never recorded, and the caller would keep re-seeing the
 * candidate with no way to tell why.
 *
 * @since 13.47.0
 * @param array $change
 * @return array|WP_Error
 */
function snt_sn_apply_gate2_dismiss( $change ) {
	$payload  = isset( $change['payload'] ) && is_array( $change['payload'] ) ? $change['payload'] : array();
	$findings = array();
	$identity = 'dismiss|' . (string) ( $payload['block_fingerprint'] ?? '' );

	foreach ( array( 'surface', 'block_fingerprint', 'candidate_type' ) as $key ) {
		if ( '' === (string) ( $payload[ $key ] ?? '' ) ) {
			$findings[] = snt_sn_validate_finding(
				'dismiss',
				'payload_complete',
				'error',
				sprintf(
					/* translators: %s: the missing payload key. */
					__( 'dismiss requires payload.%s.', 'signal-and-noise-tools' ),
					$key
				),
				null,
				$key,
				array(),
				$identity
			);
		}
	}

	// THE SURFACE ENUM IS EXACTLY THREE, while sn-scan has NINE scan_types — so
	// six of them have no dismissal store at all (sn-scan's own description says
	// so). Refuse BY NAME: a silent no-op would report a dismissal that was
	// never recorded, and the caller would keep re-seeing the candidate with no
	// way to tell why.
	$surfaces = array( 'block-migrations', 'pattern-adoption', 'corpus-integrity' );
	$surface  = (string) ( $payload['surface'] ?? '' );
	if ( '' !== $surface && ! in_array( $surface, $surfaces, true ) ) {
		$findings[] = snt_sn_validate_finding(
			'dismiss',
			'surface_has_store',
			'error',
			sprintf(
				/* translators: 1: the requested surface, 2: the surfaces that have a store. */
				__( 'No dismissal store exists for surface "%1$s". Dismissible surfaces are: %2$s.', 'signal-and-noise-tools' ),
				$surface,
				implode( ', ', $surfaces )
			),
			$surface,
			implode( ', ', $surfaces ),
			array(),
			$identity
		);
	}

	return array( 'passed' => empty( $findings ), 'findings' => $findings );
}

/**
 * Gate 2 input: which sn_validate check family (if any) applies to this
 * change type, and what `proposed` shape to hand its INTERNAL check
 * functions directly — never the full signal-noise/sn-validate ABILITY
 * wrapper, because that wrapper requires a corpus post_id (alt_text's
 * target is an attachment, which the corpus gate would reject outright).
 * Calling the check functions directly is still "sn_validate's internal
 * implementation" — the ability wrapper is itself a thin per-surface
 * dispatch loop over exactly these functions (inc/abilities-sn-validate.php).
 *
 * @param string $type
 * @param array  $resolved
 * @param array  $change
 * @param string $new_content Pre-computed new post_content for body-family
 *                             types (drift_replace/block_migration/
 *                             pattern_adoption) — null when gate 1 hasn't
 *                             produced one yet (fingerprint failed) or
 *                             doesn't apply.
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_validation( $type, array $resolved, array $change, $new_content = null ) {
	$payload = (array) ( $change['payload'] ?? array() );

	switch ( $type ) {
		case 'alt_text':
			$items    = array( array( 'attachment_id' => $resolved['attachment_id'], 'text' => (string) ( $payload['text'] ?? $payload['alt_text'] ?? '' ) ) );
			$findings = function_exists( 'snt_sn_validate_check_alt_text' ) ? snt_sn_validate_check_alt_text( $items, $resolved['attachment_id'] ) : array();
			return array( 'checks' => array( 'alt_text' ), 'findings' => $findings );

		case 'link_insert':
			$post_id = $resolved['post_id'];
			$post    = get_post( $post_id );
			$body    = $post ? (string) $post->post_content : '';
			$items   = array( array( 'anchor_text' => (string) ( $payload['anchor'] ?? '' ), 'target_post_id' => (int) ( $payload['target_post_id'] ?? 0 ) ) );
			$findings = function_exists( 'snt_sn_validate_check_links' ) ? snt_sn_validate_check_links( $items, $post_id, $body ) : array();
			return array( 'checks' => array( 'links' ), 'findings' => $findings );

		case 'surfaces':
			$post_id  = $resolved['post_id'];
			$post     = get_post( $post_id );
			$checks   = array();
			$findings = array();
			if ( array_key_exists( 'excerpt', $payload ) && function_exists( 'snt_sn_validate_check_excerpt' ) ) {
				$checks[]  = 'excerpt';
				$findings  = array_merge( $findings, snt_sn_validate_check_excerpt( (string) $payload['excerpt'], $post_id ) );
			}
			if ( array_key_exists( 'meta_description', $payload ) && function_exists( 'snt_sn_validate_check_meta_description' ) ) {
				$checks[]  = 'meta_description';
				$findings  = array_merge( $findings, snt_sn_validate_check_meta_description( (string) $payload['meta_description'], $post_id ) );
			}
			if ( array_key_exists( 'og_card_title', $payload ) && function_exists( 'snt_sn_validate_check_og_card_title' ) ) {
				$checks[]  = 'og_card_title';
				$title     = $post ? (string) $post->post_title : '';
				$findings  = array_merge( $findings, snt_sn_validate_check_og_card_title( (string) $payload['og_card_title'], $post_id, $title ) );
			}
			return array( 'checks' => $checks, 'findings' => $findings );

		case 'drift_replace':
		case 'sentence_replace':
		case 'link_reshape':
		case 'unlink':
		case 'block_migration':
		case 'pattern_adoption':
			if ( null === $new_content || ! function_exists( 'snt_sn_validate_check_body' ) ) {
				return array( 'checks' => array(), 'findings' => array() );
			}
			return array( 'checks' => array( 'body' ), 'findings' => snt_sn_validate_check_body( $new_content, $resolved['post_id'] ?? 0 ) );

		case 'batch':
			// The batch validates the RESULT, not each change: gate 1 already
			// produced the exact body the write will land, and that body is what
			// a reader will see. Validating changes individually would pass a
			// batch whose combined result is invalid.
			if ( null === $new_content || ! function_exists( 'snt_sn_validate_check_body' ) ) {
				return array( 'checks' => array(), 'findings' => array() );
			}
			return array( 'checks' => array( 'body' ), 'findings' => snt_sn_validate_check_body( $new_content, $resolved['post_id'] ?? 0 ) );

		case 'block_insert':
		case 'block_replace':
		case 'block_delete':
		case 'block_move':
			// v13.2.0 (+ delete/move v13.5.0) — the body family's check, PLUS the brand-voice evidence
			// pass over the PAYLOAD's own prose (spec: "reuse the brand_voice
			// em-dash check against the payload's text"). brand_voice findings
			// are severity 'info' by contract (evidence, never a verdict —
			// inc/sn-validate-checks-media.php), so they surface in findings
			// without ever failing the gate; only a severity-'error' body
			// finding refuses.
			if ( null === $new_content || ! function_exists( 'snt_sn_validate_check_body' ) ) {
				return array( 'checks' => array(), 'findings' => array() );
			}
			$checks   = array( 'body' );
			$findings = snt_sn_validate_check_body( $new_content, $resolved['post_id'] ?? 0 );
			// delete/move carry no payload.blocks — nothing composed to run the
			// voice evidence over; the body check above covers the result.
			if ( isset( $payload['blocks'] ) && function_exists( 'snt_sn_validate_brand_voice_findings' ) && function_exists( 'snt_sn_apply_link_prose_normalize' ) ) {
				$checks[]   = 'brand_voice';
				$blocks_txt = snt_sn_apply_link_prose_normalize( (string) ( $payload['blocks'] ?? '' ) );
				$findings   = array_merge( $findings, snt_sn_validate_brand_voice_findings( 'body', $blocks_txt, (int) ( $resolved['post_id'] ?? 0 ) ) );
			}
			return array( 'checks' => $checks, 'findings' => $findings );

		case 'create_draft':
			// Its own gate-2 assembly (excerpt/body/block-pattern/tags plus
			// two structural checks unique to a create) — see
			// inc/sn-apply/create-draft.php's docblock.
			return snt_sn_apply_gate2_create_draft( $payload );

		case 'restore_revision':
			// Its own gate-2 assembly, run against the REVISION's fields
			// (the would-be live state), never the live post's current
			// fields — see inc/sn-apply/restore-revision.php's docblock.
			return snt_sn_apply_gate2_restore_revision( $resolved, $change );

		case 'roadmap_board':
			// Its own gate-2 assembly (inc/sn-apply/roadmap-board.php):
			// structure bounds + plain-prose + the banned-token sweep that
			// mirrors the public page's leak-sweep test.
			return snt_sn_apply_gate2_roadmap_board( $change );

		case 'dismiss':
			return snt_sn_apply_gate2_dismiss( $change );

		case 'merge_tags':
			return snt_sn_apply_gate2_merge_tags( $change );

		case 'schedule_cron_event':
			return snt_sn_apply_gate2_schedule_cron_event( $change );

		case 'clear_template_overrides':
			// The ability's input_schema has an EMPTY properties map, so there
			// is nothing to validate. Returning a passing gate here is honest;
			// inventing a required key would invent a contract.
			return array( 'passed' => true, 'findings' => array() );

		case 'delete_draft':
			// Draft-status + post_type fence (inc/sn-apply/delete-draft.php);
			// the write primitive re-checks both immediately before trashing.
			return snt_sn_apply_gate2_delete_draft( $resolved );

		default:
			// og_card, anchor_sweep — no applicable check family.
			return array( 'checks' => array(), 'findings' => array() );
	}
}

/**
 * Pre-compute the new post_content a structural (block-family) apply would
 * produce, WITHOUT writing anything — reuses the shared engine's own
 * find/sanitize/replace primitives (never a second copy of that logic) so
 * gate 2's body check and the dry_run diff see EXACTLY what the real write
 * would produce.
 *
 * @param int    $post_id
 * @param string $fingerprint
 * @param string $replacement_markup
 * @return array{old_content:string,new_content:string}|WP_Error
 */
function snt_sn_apply_compute_block_replacement( $post_id, $fingerprint, $replacement_markup ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_sn_apply_target_not_found', __( 'Post not found.', 'signal-and-noise-tools' ), array( 'status' => 404 ) );
	}
	$old_content      = (string) $post->post_content;
	$blocks           = parse_blocks( $old_content );
	$replacement      = parse_blocks( (string) $replacement_markup );
	$replacement_node = $replacement[0] ?? null;
	if ( ! is_array( $replacement_node ) || empty( $replacement_node['blockName'] ) ) {
		return new WP_Error( 'snt_sn_apply_invalid_markup', __( 'Replacement markup did not parse to a valid block.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$replacement_node = snt_block_fp_sanitize_node( $replacement_node );
	$found = false;
	snt_block_fp_replace_in_tree( $blocks, $fingerprint, $replacement_node, $found, (int) $post_id );
	if ( ! $found ) {
		return new WP_Error( 'snt_sn_apply_conflict', __( 'Block changed or removed since scan.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}
	return array( 'old_content' => $old_content, 'new_content' => serialize_blocks( $blocks ) );
}
