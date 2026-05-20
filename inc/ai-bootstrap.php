<?php
/**
 * Signal & Noise Tools — AI integration bootstrap.
 *
 * Central function_exists() gate + shared helpers for WP 7.0's bundled AI
 * Client (and the wp-ai-client backport plugin on 6.x). All AI-feature
 * code is conditional on wp_has_ai_client() so the plugin behaves
 * identically on WP 6.x without the backport — dormant, no errors.
 *
 * Why wp_has_ai_client() and not function_exists('wp_ai_client_prompt'):
 * `wp_has_ai_client()` is THE canonical compatibility function shipped by
 * the wp-ai-client package itself (see WordPress/wp-ai-client/autoload.php).
 * It's the recommended check per the package's own bootstrap — switching
 * between 7.0 native vs 6.x backport doesn't change the answer.
 *
 * Why our code is provider-agnostic (no temperature/top_p/top_k):
 * The WP AI Client routes through whatever provider the user configures
 * in Settings > Connectors. Anthropic's Claude Opus 4.7 specifically
 * removed sampling parameters (returns 400 if you send them). The
 * portable choice is to set NO sampling params and rely on prompt
 * engineering + system instructions instead. See docs/WP-7.0-AI-API-MAP.md
 * in the theme repo for the full reasoning.
 *
 * Verified against:
 *   - WordPress/wp-ai-client/autoload.php — wp_has_ai_client() detection
 *   - WordPress/php-ai-client/src/AiClient.php — fluent API
 *   - WP make blog 7.0 Field Guide — provider model + Connectors UI
 *
 * Added in v1.16.0 (2026-05-17). Activates on WP 7.0 + Anthropic provider
 * (or 6.x + wp-ai-client plugin + Anthropic provider).
 *
 * @package SignalNoiseTools
 * @since 1.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the WP AI Client wired up with at least one provider that can
 * generate text on this install?
 *
 * Canonical check per the WordPress 7.0 AI Client dev note (2026-03-24):
 *   https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
 *
 *   > "Available support check methods include is_supported_for_text_generation(),
 *   >  is_supported_for_image_generation(), and others — NOT wp_has_ai_client()."
 *
 * The check builds a no-cost prompt and asks the resulting builder whether
 * its configuration (no provider/model pinned, default sampling) is
 * supported by any registered, configured provider. Per the dev note,
 * this is deterministic — no API calls fired.
 *
 * Returns false when:
 *   - WP < 7.0 (and no wp-ai-client backport plugin)
 *   - AI Client present but no provider plugin installed (e.g. Anthropic provider missing)
 *   - Provider plugin present but no API key configured in Settings > Connectors
 *
 * @since v2.5.0 — replaces wp_has_ai_client() gate that returned true even
 * when no provider was configured (causing AI buttons to render then 503).
 *
 * @return bool
 */
function snt_ai_can_text_generate() {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return false;
	}

	try {
		$builder = wp_ai_client_prompt( 'check' );
		if ( ! is_object( $builder ) ) {
			return false;
		}
		if ( ! method_exists( $builder, 'is_supported_for_text_generation' ) ) {
			return false; // Older wp-ai-client backport without the feature-detection method.
		}
		return (bool) $builder->is_supported_for_text_generation();
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Back-compat alias — every existing call site of snt_ai_is_available()
 * gets the corrected behavior automatically.
 *
 * @return bool
 */
function snt_ai_is_available() {
	return snt_ai_can_text_generate();
}

/**
 * Generate text via the WP AI Client with SN's standard constraints.
 *
 * Wraps wp_ai_client_prompt() with:
 *   - A system instruction (caller-provided)
 *   - A max-tokens cap (caller-provided; defaults to 256)
 *   - Provider-agnostic call (no temperature/top_p/top_k — see file docblock)
 *   - WP_Error-on-everything error handling (wp_ai_client_prompt already
 *     wraps SDK exceptions as WP_Error; we add a guard if the client
 *     isn't available)
 *
 * @param string $prompt              The user-content prompt (post body, image alt context, etc.).
 * @param string $system_instruction  Constraints/voice/format rules. Use to specify output
 *                                    shape, length, tone — anything you'd otherwise have
 *                                    used temperature/top_p to control.
 * @param int    $max_tokens          Output cap. Default 256. For meta descriptions ~150
 *                                    is enough; for longer-form output bump higher.
 * @return string|WP_Error            Generated text or WP_Error on failure.
 */
function snt_ai_generate_with_constraints( $prompt, $system_instruction, $max_tokens = 256 ) {
	if ( ! snt_ai_is_available() ) {
		return new WP_Error(
			'snt_ai_unavailable',
			__( 'WP AI Client is not installed or activated. Install the wp-ai-client plugin (WP 6.x) or upgrade to WordPress 7.0+.', 'signal-noise-tools' ),
			array( 'status' => 503 )
		);
	}

	$max_tokens = max( 1, min( 4096, (int) $max_tokens ) );

	try {
		$result = wp_ai_client_prompt( (string) $prompt )
			->using_system_instruction( (string) $system_instruction )
			->using_max_tokens( $max_tokens )
			->generate_text();
	} catch ( \Throwable $e ) {
		// Defense in depth — the WP wrapper already converts SDK exceptions
		// to WP_Error, but a misconfigured provider or PHP runtime error
		// can still bubble. Catch + convert so callers always get WP_Error.
		return new WP_Error(
			'snt_ai_runtime_error',
			sprintf( __( 'AI runtime error: %s', 'signal-noise-tools' ), $e->getMessage() ),
			array( 'status' => 500 )
		);
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$text = trim( (string) $result );
	if ( '' === $text ) {
		return new WP_Error(
			'snt_ai_empty_response',
			__( 'AI returned an empty response. Verify your provider is configured in Settings > Connectors.', 'signal-noise-tools' ),
			array( 'status' => 502 )
		);
	}

	return $text;
}

/**
 * Truncate post content to N words, stripping all HTML/shortcodes first.
 *
 * Used by AI features to bound input token cost. The first ~1000 words of
 * any post is sufficient context for SEO meta description / OG title /
 * tag suggestion tasks — quality plateaus well before context-window
 * limits, but token cost scales linearly.
 *
 * @param int $post_id  Post ID.
 * @param int $words    Word cap. Default 1000.
 * @return string       Plain-text excerpt, trimmed.
 */
function snt_ai_extract_post_text( $post_id, $words = 1000 ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return '';
	}

	$raw = $post->post_content;
	$raw = strip_shortcodes( $raw );
	$raw = wp_strip_all_tags( $raw );

	// wp_trim_words doesn't add an ellipsis when the more= arg is empty —
	// we want a clean truncation, not "..." appended.
	return (string) wp_trim_words( $raw, max( 50, (int) $words ), '' );
}
