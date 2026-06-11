<?php
/**
 * Signal & Noise Tools — AI-assisted SEO meta description generation.
 *
 * Phase 12, slice 1 — fills `_sn_meta_description` (the per-post meta
 * box field shipped in v1.10.0) from post content via the WP AI Client.
 *
 * Two surfaces, both gated on snt_ai_is_available():
 *
 *   1. REST endpoint:  POST signal-noise/v1/ai/generate-meta-description
 *      Body: { post_id: int }
 *      Returns: { ok: true, description: string } or WP_Error
 *      Permission: edit_post for the given post_id
 *
 *   2. Meta-box button: rendered inside the existing per-post SN meta box
 *      (next to the meta description textarea, post-settings.php). JS in
 *      assets/ai-meta-description.js calls the REST endpoint via
 *      wp.apiFetch, fills the textarea on success, shows error inline.
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

const SNT_AI_META_DESC_SYSTEM = 'Generate a meta description for SEO. Output 140-160 characters. ' .
	'Active voice. Direct and declarative. No marketing fluff — avoid words like: amazing, powerful, ' .
	'ultimate, best, revolutionary, transformative, cutting-edge. No first person plural ("we") unless ' .
	'the source clearly uses it. Capture the single most useful thing a search-result reader would ' .
	'want to know about this content. Output ONLY the description text — no quotes, no preamble, ' .
	'no "Meta description:" labels, no markdown.';

const SNT_AI_META_DESC_MAX_TOKENS = 150;
const SNT_AI_META_DESC_INPUT_WORDS = 1000;

// v4.8.0: concise variant for auto-prepopulation at publish. Hard char
// ceiling (SERP display limit) wins over sentence count; the truncation
// guard below is the backstop because models count chars unreliably.
const SNT_AI_META_DESC_SYSTEM_CONCISE = 'Generate a meta description for SEO. ' .
	'Output AT MOST 155 characters — count carefully. One or two short, declarative sentences. ' .
	'Active voice. No marketing fluff — avoid words like: amazing, powerful, ultimate, best, ' .
	'revolutionary, transformative, cutting-edge. Capture the single most useful thing a ' .
	'search-result reader would want to know. Output ONLY the description text — no quotes, ' .
	'no preamble, no labels, no markdown.';

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
 * Pure function called by both the (@deprecated since 2.5.0) REST handler
 * AND the signal-noise/ai-generate-meta-description ability execute
 * callback. Single source of truth.
 *
 * @param int $post_id
 * @return array{ok:bool,description:string,length:int}|WP_Error
 * @since v2.5.0
 */
function snt_ai_meta_desc_impl( $post_id, $concise = false ) {
	// v4.1.1 (D-03): shared AI-gate helper.
	$gate = snt_ai_require_text_generation();
	if ( $gate ) { return $gate; }

	$content = snt_ai_extract_post_text( $post_id, SNT_AI_META_DESC_INPUT_WORDS );
	if ( '' === $content ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'Post has no content to summarize. Add some body text first.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$result = snt_ai_generate_with_constraints(
		$content,
		$concise ? SNT_AI_META_DESC_SYSTEM_CONCISE : SNT_AI_META_DESC_SYSTEM,
		$concise ? SNT_AI_META_DESC_MAX_TOKENS_CONCISE : SNT_AI_META_DESC_MAX_TOKENS
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Defensive trim — some providers add stray newlines or trailing spaces
	// despite the system prompt's "no preamble" instruction.
	$description = trim( $result );
	if ( $concise ) {
		$description = snt_ai_truncate_meta_description( $description, 155 );
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
	// Only on post edit screens (block editor + classic editor both fire this).
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	if ( ! snt_ai_is_available() ) {
		return; // Skip enqueue entirely — no button, no JS, no overhead.
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		'snt-ai-meta-description',
		plugins_url( 'assets/ai-meta-description.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		// v4.1.6 (U-15): snt-status provides window.sntSetStatus (replaces local setStatus copy).
		array( 'wp-api-fetch', 'wp-i18n', 'snt-status' ),
		SNT_VERSION,
		true
	);

	wp_localize_script( 'snt-ai-meta-description', 'sntAiMetaDesc', array(
		'targetId' => 'sn_meta_description', // matches inc/post-settings.php:146
	) );

	wp_enqueue_script( 'snt-ai-meta-description' );

	// Set translations on the script for wp.i18n.__ — only effective if
	// the .pot/.po files exist; otherwise falls back to the source string.
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-ai-meta-description', 'signal-noise-tools' );
	}
} );
