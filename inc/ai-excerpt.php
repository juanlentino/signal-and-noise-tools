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
 *   1. REST endpoint:  POST signal-noise/v1/ai/generate-excerpt
 *      Body: { post_id: int }
 *      Returns: { ok: true, excerpt: string, length: int }
 *      Permission: edit_post for the given post_id
 *
 *   2. Meta-box button: rendered inside the per-post SN meta box at the
 *      bottom. JS at assets/ai-excerpt.js calls the REST endpoint via
 *      wp.apiFetch, then writes the result back to the WP excerpt field
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

/* ════════════════════════════════════════════════════════════════════════
 * REST ENDPOINT
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/ai/generate-excerpt', array(
		'methods'             => 'POST',
		'callback'            => 'snt_ai_excerpt_rest_handler',
		'permission_callback' => 'snt_ai_excerpt_rest_permission',
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		),
	) );
} );

function snt_ai_excerpt_rest_permission( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Generate a 2-3 sentence post excerpt via the WP AI Client.
 *
 * Pure function called by both the (@deprecated since 2.5.0) REST handler
 * AND the signal-noise/ai-generate-excerpt ability execute callback.
 *
 * @param int $post_id
 * @return array{ok:bool,excerpt:string,length:int,words:int}|WP_Error
 * @since v2.5.0
 */
function snt_ai_excerpt_impl( $post_id ) {
	if ( ! function_exists( 'snt_ai_can_text_generate' ) || ! snt_ai_can_text_generate() ) {
		return new WP_Error(
			'snt_ai_unavailable',
			__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-noise-tools' ),
			array( 'status' => 503 )
		);
	}

	$content = function_exists( 'snt_ai_extract_post_text' )
		? snt_ai_extract_post_text( $post_id, SNT_AI_EXCERPT_INPUT_WORDS )
		: '';

	if ( '' === $content ) {
		return new WP_Error(
			'snt_ai_empty_post',
			__( 'Post has no content to summarize. Add some body text first.', 'signal-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$result = snt_ai_generate_with_constraints(
		$content,
		SNT_AI_EXCERPT_SYSTEM,
		SNT_AI_EXCERPT_MAX_TOKENS
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

/**
 * @deprecated since 2.5.0 — prefer
 *   POST /wp-abilities/v1/signal-noise/ai-generate-excerpt/run
 * via the WP Abilities REST surface. This endpoint stays wired for
 * back-compat with v2.4.0+ JS clients on installs running pre-v2.5.0.
 */
function snt_ai_excerpt_rest_handler( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$result  = snt_ai_excerpt_impl( $post_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

/* ════════════════════════════════════════════════════════════════════════
 * META-BOX UI INJECTION
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		'snt-ai-excerpt',
		plugins_url( 'assets/ai-excerpt.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'wp-i18n', 'wp-data' ),
		SNT_VERSION,
		true
	);

	wp_localize_script( 'snt-ai-excerpt', 'sntAiExcerpt', array(
		'restPath'      => '/signal-noise/v1/ai/generate-excerpt',
		'metaBoxClass'  => 'sn-post-settings',
	) );

	wp_enqueue_script( 'snt-ai-excerpt' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-ai-excerpt', 'signal-noise-tools' );
	}
} );
