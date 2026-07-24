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
 * Prompt design:
 *   - 2-3 sentences, ~50-75 words
 *   - Hook-driven: capture the reader's reason to click
 *   - Source content's voice, not generic marketing
 *   - Same provider-agnostic / no-temperature posture as the other AI
 *     surfaces in this plugin
 *
 * @package SignalNoiseTools
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_AI_EXCERPT_SYSTEM = 'Generate a WordPress excerpt for a published article. Constraints: ' .
	'(1) 2-3 sentences, between 50 and 75 words total; ' .
	'(2) Hook-driven — capture the single most useful reason a reader would click; ' .
	'(3) Match the source article\'s voice — don\'t turn an editorial piece into generic marketing; ' .
	'(4) Active voice. Declarative. No fluff; ' .
	'(5) Avoid: amazing, ultimate, best, powerful, revolutionary, transformative, cutting-edge, dive into, unlock, unleash; ' .
	'(6) No quotes, no preamble, no "Excerpt:" labels, no markdown. ' .
	'Output ONLY the excerpt text.';

const SNT_AI_EXCERPT_MAX_TOKENS    = 200;
const SNT_AI_EXCERPT_INPUT_WORDS   = 1200;

// v4.8.0: concise variant for auto-prepopulation. Tighter than the 50-75
// word default — punchy, not comprehensive.
const SNT_AI_EXCERPT_SYSTEM_CONCISE = 'Generate a WordPress excerpt for a published article. Constraints: ' .
	'(1) Up to 3 SHORT sentences, 40-60 words total — punchy, not comprehensive; ' .
	'(2) Hook-driven — capture the single most useful reason a reader would click; ' .
	'(3) Match the source article\'s voice — don\'t turn an editorial piece into generic marketing; ' .
	'(4) Active voice. Declarative. No fluff; ' .
	'(5) Avoid: amazing, ultimate, best, powerful, revolutionary, transformative, cutting-edge, dive into, unlock, unleash; ' .
	'(6) No quotes, no preamble, no "Excerpt:" labels, no markdown. ' .
	'Output ONLY the excerpt text.';

const SNT_AI_EXCERPT_MAX_TOKENS_CONCISE = 120;

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
		'words'   => str_word_count( $excerpt ),
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
