<?php
/**
 * Signal & Noise Tools — AI-assisted SEO meta description generation.
 *
 * Phase 12, slice 1 — fills `_sn_meta_description` (the per-post meta
 * box field shipped in v1.10.0) from post content via the WP AI Client.
 *
 * Two surfaces, both gated on snt_ai_is_available():
 *
 *   1. Ability: signal-noise/ai-generate-meta-description (registered in
 *      inc/abilities-ai-post-editor.php; the bespoke REST route was removed
 *      in v5.0.0 — abilities' /wp-json/abilities/v1 run path is the only
 *      transport). Input: { post_id: int, concise?: bool }. Returns
 *      { ok, description, length }. Permission: edit_post for the post.
 *
 *   2. Meta-box button: rendered inside the existing per-post SN meta box
 *      (next to the meta description textarea, post-settings.php). JS in
 *      assets/ai-meta-description.js runs the ability via sntAbilityRun,
 *      fills the textarea on success, shows error inline.
 *
 * Prompt design — see docs/WP-7.0-AI-API-MAP.md in theme repo §SN-specific
 * use cases for the full reasoning. Short version: 140-160 chars, active
 * voice, no marketing fluff, output only the description text.
 *
 * Why no temperature/top_p: provider-agnostic — sampling params are
 * removed on Claude Opus 4.7 (400 error) and inconsistent across
 * providers. Constraints live in the system instruction instead.
 *
 * @package SignalNoiseTools
 * @since 1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v10.6.1 — the voice fix. The reader is scanning a results page with no
// context beyond the title: the description states what is true in the
// essay, never what the essay "will do" for the reader, and never refers
// to the essay as an object. FOCUS KEYWORD / EXISTING EXCERPT context
// lines are prepended by snt_ai_meta_desc_impl() when available — the
// instructions referencing them are inert otherwise.
const SNT_AI_META_DESC_SYSTEM = 'Write a meta description for a search results page. ' .
	'The reader is scanning results, has never seen the site, and has no context beyond the title sitting above the snippet. ' .
	'It is not the excerpt trimmed to length: state the single most load-bearing true thing the essay establishes, in its own register. ' .
	'Form: 140-160 characters, hard limits. It must read as a finished sentence at 160 characters, never trailing off mid-clause. One or two short declarative sentences, active voice. ' .
	'If a FOCUS KEYWORD line is supplied with the content, that keyword must appear verbatim; otherwise use the most specific topic noun from the title verbatim. ' .
	'If an EXISTING EXCERPT line is supplied, do not open with the same words it opens with; the two texts may appear together and must not look like one text cut twice. ' .
	'State what is true, not what the piece will do for the reader: no "learn why", no "discover how". ' .
	'Never refer to the piece as an object: no "this piece", "this article", "this note", "explores", "unpacks", "argues that". ' .
	'Banned outright: em dashes in any form or spacing; tricolons and three-part parallel lists; "not just X, but Y" and its variants; ' .
	'hedge stacking such as "may potentially" or "could arguably"; the words delve, landscape, crucial, and leverage used as a verb; ' .
	'the phrases "in today\'s world", "it\'s worth noting", "at its core". ' .
	'Output ONLY the description text. No quotes, no preamble, no labels, no markdown.';

const SNT_AI_META_DESC_MAX_TOKENS = 150;
const SNT_AI_META_DESC_INPUT_WORDS = 1000;

// v4.8.0 introduced a tighter concise variant for auto-prepopulation;
// v10.6.1 unifies it with the default (one register spec, one prompt).
// The constant survives so the concise API contract and the tests pinning
// which constant each path selects are unchanged. The truncation guard in
// the impl stays as the char-ceiling backstop, now at 160 to match the
// prompt's own hard limit.
const SNT_AI_META_DESC_SYSTEM_CONCISE = SNT_AI_META_DESC_SYSTEM;

const SNT_AI_META_DESC_MAX_TOKENS_CONCISE = 80;

/**
 * Trim a meta description to a word boundary at or below $max chars.
 * Backstop for the concise path — models overshoot character ceilings.
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function snt_ai_truncate_meta_description( $text, $max = 155 ) {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > 0 ) {
		$cut = mb_substr( $cut, 0, $space );
	}
	return rtrim( $cut, " \t\n\r\0\x0B.,;:" );
}

/**
 * Generate a meta description for a post via the WP AI Client.
 *
 * Pure function behind the signal-noise/ai-generate-meta-description
 * ability's execute callback (the pre-v5.0.0 bespoke REST handler is
 * gone). Single source of truth.
 *
 * @param int    $post_id
 * @param bool   $concise       Concise prepop variant (kept for API compat; same prompt since v10.6.1).
 * @param string $focus_keyword Optional keyword that must appear verbatim (v10.6.1).
 *                              The plugin stores no focus keyword itself, so callers
 *                              that know it (agents, future meta-box field) pass it in;
 *                              absent, the prompt falls back to the title's topic noun.
 * @return array{ok:bool,description:string,length:int}|WP_Error
 * @since v2.5.0
 */
function snt_ai_meta_desc_impl( $post_id, $concise = false, $focus_keyword = '' ) {
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	// v9.3.0: signal helper falls back to title + theme description for
	// contentless template Pages instead of returning an empty string (422).
	$content = snt_ai_post_signal( $post_id, SNT_AI_META_DESC_INPUT_WORDS );
	if ( '' === $content ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'Post has no content to summarize. Add some body text first.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	// v10.6.1: context lines the system prompt's conditional instructions
	// key off. An instruction alone cannot keep the description from
	// opening like an excerpt the model has never seen.
	$context       = '';
	$focus_keyword = trim( (string) $focus_keyword );
	if ( '' !== $focus_keyword ) {
		$context .= 'FOCUS KEYWORD (must appear verbatim): ' . $focus_keyword . "\n\n";
	}
	$existing_excerpt = '';
	if ( function_exists( 'get_post' ) ) {
		$post             = get_post( $post_id );
		$existing_excerpt = $post ? trim( (string) $post->post_excerpt ) : '';
	}
	if ( '' !== $existing_excerpt ) {
		$context .= 'EXISTING EXCERPT (do not open with the same words): ' . $existing_excerpt . "\n\n";
	}
	$content = $context . $content;

	$result = snt_ai_generate_with_constraints(
		$content,
		$concise ? SNT_AI_META_DESC_SYSTEM_CONCISE : SNT_AI_META_DESC_SYSTEM,
		$concise ? SNT_AI_META_DESC_MAX_TOKENS_CONCISE : SNT_AI_META_DESC_MAX_TOKENS,
		'meta_desc'
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Defensive trim — some providers add stray newlines or trailing spaces
	// despite the system prompt's "no preamble" instruction.
	$description = trim( $result );
	if ( $concise ) {
		// v10.6.1: ceiling raised 155 → 160 to match the prompt's own hard
		// limit — trimming a 158-char finished sentence broke it mid-clause.
		$description = snt_ai_truncate_meta_description( $description, 160 );
	}

	// v4.1.6 (D-10): centralized — surrounding-quote strip now happens in
	// snt_ai_generate_with_constraints() before this caller receives $description.

	return array(
		'ok'          => true,
		'description' => $description,
		'length'      => strlen( $description ),
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * META-BOX UI INJECTION
 *
 * The existing per-post meta box renders in inc/post-settings.php. We
 * don't edit that file — instead we enqueue a small JS file on the post
 * edit screen that injects the "Generate with AI" button next to the
 * meta description textarea (id="sn_meta_description") at DOM-ready.
 *
 * This keeps post-settings.php focused on field rendering (not AI
 * concerns) and means the button only appears when AI is actually
 * available (we check on enqueue, not in PHP-rendered markup).
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// v9.81.0: shared helper (ai-bootstrap.php) replaces the drifted local copy.
	snt_ai_enqueue_editor_script( $hook_suffix, 'snt-ai-meta-description', 'ai-meta-description.js', 'sntAiMetaDesc', array(
		'targetId' => 'sn_meta_description', // matches inc/post-settings.php:146
	) );
} );
