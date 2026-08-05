<?php
/**
 * Signal & Noise Tools — sn_apply gates 1 (fingerprint) and 2 (server-side
 * validation). MCP consolidation session 6b.
 *
 * Split out of inc/sn-apply-executors.php purely for the 450-line file
 * budget — these three functions are still part of the same per-change-type
 * executor layer (inc/sn-apply-executors.php holds target resolution, the
 * mode-support matrix, and the write dispatch; inc/abilities-sn-apply.php
 * orchestrates all four gates in order).
 *
 * @package SignalNoiseTools
 * @since 10.40.0
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
			$found  = function_exists( 'snt_block_fp_find' ) ? snt_block_fp_find( $blocks, $fingerprint ) : null;
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

		case 'drift_replace':
		case 'link_insert':
			$post = get_post( $resolved['post_id'] );
			if ( ! $post ) {
				return array( 'passed' => false, 'expected' => $fingerprint, 'observed' => null, 'skipped' => null, 'detail' => 'post_not_found', 'new_content' => null );
			}
			$content = (string) $post->post_content;
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

		default:
			// alt_text, surfaces, og_card, anchor_sweep — no fingerprint
			// scheme exists in the absorbed impl (see docblock above).
			return array( 'passed' => true, 'expected' => null, 'observed' => null, 'skipped' => 'no_fingerprint_scheme', 'detail' => null, 'new_content' => null );
	}
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
		case 'block_migration':
		case 'pattern_adoption':
			if ( null === $new_content || ! function_exists( 'snt_sn_validate_check_body' ) ) {
				return array( 'checks' => array(), 'findings' => array() );
			}
			return array( 'checks' => array( 'body' ), 'findings' => snt_sn_validate_check_body( $new_content, $resolved['post_id'] ?? 0 ) );

		case 'create_draft':
			// Its own gate-2 assembly (excerpt/body/block-pattern/tags plus
			// two structural checks unique to a create) — see
			// inc/sn-apply-create-draft.php's docblock.
			return snt_sn_apply_gate2_create_draft( $payload );

		case 'restore_revision':
			// Its own gate-2 assembly, run against the REVISION's fields
			// (the would-be live state), never the live post's current
			// fields — see inc/sn-apply-restore-revision.php's docblock.
			return snt_sn_apply_gate2_restore_revision( $resolved, $change );

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
	snt_block_fp_replace_in_tree( $blocks, $fingerprint, $replacement_node, $found );
	if ( ! $found ) {
		return new WP_Error( 'snt_sn_apply_conflict', __( 'Block changed or removed since scan.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}
	return array( 'old_content' => $old_content, 'new_content' => serialize_blocks( $blocks ) );
}
