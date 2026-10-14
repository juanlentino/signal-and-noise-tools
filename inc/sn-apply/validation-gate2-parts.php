<?php
/**
 * Signal & Noise Tools: sn_apply gates 1 and 2, gate 2's validation dispatcher and the block replacement compute.
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
