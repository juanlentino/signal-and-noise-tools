<?php
/**
 * Signal & Noise Tools — AI-assisted WP excerpt generation.
 *
 * Phase 16, slice 2 — fills WordPress's native `excerpt` field (the
 * Document panel → Excerpt textarea in the block editor) with a 2-3
 * sentence summary of the post via the WP AI Client.
 *
 * Why this targets WP-native `excerpt` instead of a new SN field:
 * `post_excerpt` is the canonical field everywhere (RSS feeds, search
 * results, archive cards, etc.) and is already part of the WP excerpt
 * workflow users know. Adding "ours" alongside would fragment the data
 * model. We just fill the existing field.
 *
 * Two surfaces, both gated on snt_ai_is_available():
 *
 *   1. Ability: signal-noise/ai-generate-excerpt (registered in
 *      inc/abilities-ai-post-editor.php; the bespoke REST route was removed
 *      in v5.0.0 — abilities' /wp-json/abilities/v1 run path is the only
 *      transport). Input: { post_id: int, concise?: bool }. Returns
 *      { ok, excerpt, length, words }. Permission: edit_post for the post.
 *
 *   2. Meta-box button: rendered inside the per-post SN meta box at the
 *      bottom. JS at assets/ai-excerpt.js runs the ability via
 *      sntAbilityRun, then writes the result back to the WP excerpt field
 *      via wp.data.dispatch('core/editor').editPost({ excerpt }) — works
 *      regardless of whether the Excerpt panel is currently expanded.
 *
 * The wp.data path is preferred over DOM polling because the block
 * editor's excerpt textarea has no stable id/class — the React tree
 * may rerender it under different markup across Gutenberg versions.
 * editPost() is the canonical API and is version-stable.
 *
 * Prompt design (rewritten v10.6.1 — the voice fix):
 *   - The excerpt is the OPENING of the argument, not a summary of it.
 *     The old "hook-driven / reason to click" instruction was the root
 *     cause of the "This piece argues…" register: it told the model to
 *     describe the note from outside. The new prompt encodes the positive
 *     behavior (write from inside, state the claim, stop), anchors the
 *     register with a verbatim example, and keeps the banned list as a
 *     final check rather than the primary mechanism — a blocklist alone
 *     just relocates the tell.
 *   - 2-3 sentences, 50-75 words, no sentence over 35 words
 *   - Same provider-agnostic / no-temperature posture as the other AI
 *     surfaces in this plugin
 *
 * @package SignalNoiseTools
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AI_EXCERPT_SYSTEM = 'You are writing the excerpt for an essay. ' .
	'The excerpt is the opening of the argument, not a summary of it. Same voice as the body, continuous with it, shorter. ' .
	'If a reader saw the excerpt and the essay\'s first paragraph side by side, one person should appear to have written both in one sitting. State the claim and stop. ' .
	'Never refer to the essay as an object: no "this piece", "this note", "this article", "this essay", "we explore", "the author argues". ' .
	'Never advertise its contents: no "offers a test", "explains why", "unpacks how". Write from inside the argument, not about it. ' .
	'Form: 50-75 words, 2-3 sentences, no sentence over 35 words. Vary sentence length so the rhythm is uneven. Do not restate the title. End on the claim, not on a summary of it. ' .
	'Banned outright: em dashes in any form or spacing; tricolons and three-part parallel lists; two consecutive sentences opening with the same word; ' .
	'"not just X, but Y" and its variants; hedge stacking such as "may potentially" or "could arguably"; ' .
	'the words delve, landscape, crucial, and leverage used as a verb; the phrases "in today\'s world", "it\'s worth noting", "at its core". ' .
	'Example of the register, written for an essay arguing that signatures prove attribution rather than truth: ' .
	'"Anyone can sign a false claim. The objection is correct, and the answer usually given to it has cost more than the objection ever did. ' .
	'What a signature produces is not truth but attribution, which is a smaller promise and the one that survives contact with a dispute." ' .
	'Output ONLY the excerpt text. No quotes, no preamble, no labels, no markdown.';

const SNT_AI_EXCERPT_MAX_TOKENS    = 200;
const SNT_AI_EXCERPT_INPUT_WORDS   = 1200;

// v4.8.0 introduced a tighter concise variant for auto-prepopulation.
// v10.6.1 unifies it with the default: the register spec is one design
// (50-75 words, written from inside the argument) and two prompts drift.
// The constant stays so the concise API contract (and the tests pinning
// which constant each path selects) is unchanged.
const SNT_AI_EXCERPT_SYSTEM_CONCISE = SNT_AI_EXCERPT_SYSTEM;

// 75 words ≈ 110 tokens; the old 120 cap could clip the final sentence.
const SNT_AI_EXCERPT_MAX_TOKENS_CONCISE = 160;

/**
 * Generate a 2-3 sentence post excerpt via the WP AI Client.
 *
 * Pure function behind the signal-noise/ai-generate-excerpt ability's
 * execute callback (the pre-v5.0.0 bespoke REST handler is gone).
 *
 * @param int $post_id
 * @return array{ok:bool,excerpt:string,length:int,words:int}|WP_Error
 * @since v2.5.0
 */
function snt_ai_excerpt_impl( $post_id, $concise = false ) {
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	// v9.3.0: signal helper handles contentless template Pages (title + fallback).
	$content = function_exists( 'snt_ai_post_signal' )
		? snt_ai_post_signal( $post_id, SNT_AI_EXCERPT_INPUT_WORDS )
		: '';

	if ( '' === $content ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'Post has no content to summarize. Add some body text first.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$result = snt_ai_generate_with_constraints(
		$content,
		$concise ? SNT_AI_EXCERPT_SYSTEM_CONCISE : SNT_AI_EXCERPT_SYSTEM,
		$concise ? SNT_AI_EXCERPT_MAX_TOKENS_CONCISE : SNT_AI_EXCERPT_MAX_TOKENS,
		'excerpt'
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$excerpt = trim( $result );
	$excerpt = trim( $excerpt, "\"'" );

	return array(
		'ok'      => true,
		'excerpt' => $excerpt,
		'length'  => strlen( $excerpt ),
		'words'   => snt_word_count( $excerpt ), // v10.24.0: Unicode-safe.
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * META-BOX UI INJECTION
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// v9.81.0: shared helper (ai-bootstrap.php) replaces the drifted local copy.
	// wp-data extra dep: editPost() writes the excerpt back into the editor store.
	snt_ai_enqueue_editor_script( $hook_suffix, 'snt-ai-excerpt', 'ai-excerpt.js', 'sntAiExcerpt', array(
		'metaBoxClass' => 'sn-post-settings',
	), array( 'wp-data' ) );
} );
