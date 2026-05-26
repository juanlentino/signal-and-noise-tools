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
 * @since v3.7.1 — removed `method_exists()` guard that always returned false.
 * Prompt_Builder dispatches snake_case methods via `__call` magic, which
 * `method_exists()` cannot detect (only `is_callable()` can). The try/catch
 * already handles a missing method (BadMethodCallException → return false),
 * so the guard's only effect was to unconditionally disable ALL SN AI
 * features since v2.5.0. Verified against wp-ai-client trunk:
 *   https://github.com/WordPress/wp-ai-client/blob/trunk/includes/Builders/Prompt_Builder.php
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
		return (bool) $builder->is_supported_for_text_generation();
	} catch ( \Throwable $e ) {
		// v3.7.6: server-log surfacing per v3.7.1 lesson. The bug-of-record
		// for SN AI features (6 months of silently no-op'ing in v2.5.0–v3.7.0)
		// was a silent guard. Even though this catch returns false correctly
		// from the v3.7.1 fix, runtime exceptions deserve a log trail so future
		// regressions surface in PHP error log instead of vanishing.
		error_log( 'snt_ai_can_text_generate exception: ' . $e->getMessage() );
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
 * v4.1.1 (D-03): single-source-of-truth gate for AI-feature impl functions.
 *
 * Returns null when AI text generation is available, or a uniform WP_Error
 * with code 'snt_ai_unavailable' (HTTP 503) when it isn't. Every AI impl
 * file (alt-text-suggest, alt-inline-suggest, drift-phrase-suggest,
 * meta-description, og-card-title, excerpt, orphan-suggest) opens with the
 * same gate — pre-v4.1.1 each duplicated the 4-line if-block with subtly
 * divergent error messages (one file already used a shortened "AI text
 * generation is not available." while the other six used the longer text).
 * Centralizing here eliminates the drift and gives us one message to localize.
 *
 * Usage:
 *
 *     function snt_ai_foo_impl( ... ) {
 *         $gate = snt_ai_require_text_generation();
 *         if ( $gate ) { return $gate; }
 *         // ... real impl
 *     }
 *
 * The inner gate inside snt_ai_generate_with_constraints() also fires
 * (with a slightly different message about the wp-ai-client plugin
 * specifically) — that one stays in place as defense-in-depth. This
 * outer gate lets impls short-circuit BEFORE building expensive prompts.
 *
 * @return WP_Error|null  WP_Error when AI is unavailable, null when it is.
 *
 * @since 4.1.1
 */
function snt_ai_require_text_generation() {
	if ( snt_ai_can_text_generate() ) {
		return null;
	}
	return new WP_Error(
		'snt_ai_unavailable',
		__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-noise-tools' ),
		array( 'status' => 503 )
	);
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

	/**
	 * Pinned model preference for ALL SN AI text generation.
	 *
	 * Why pin rather than let the AI Client pick:
	 * - The Anthropic provider plugin defaults to the most-capable available
	 *   model (currently `claude-opus-4-7`), which is ~5x the cost of Sonnet.
	 * - The v3.6.0 Insights plan budgeted ~$0.01/scan based on Sonnet pricing.
	 *   Unpinned, Opus 4.7 was being used → ~$0.10/scan in production (verified
	 *   via AI Request Logs 2026-05-21: single Insights call was 4.9K tokens
	 *   at claude-opus-4-7 = ~$0.10).
	 * - Pinning by string model ID (per usingModelPreference signature) lets
	 *   the call still route through any provider that exposes the same model
	 *   ID, so the pin is portable across provider changes.
	 *
	 * Filter `snt_ai_model_preference` lets callers override per-feature if
	 * the quality differential ever justifies Opus for a specific surface.
	 *
	 * Per php-ai-client/src/Builders/PromptBuilder.php (line 288):
	 * `usingModelPreference(...$preferredModels)` — accepts string IDs,
	 * ModelInterface instances, or [providerId, modelId] tuples.
	 *
	 * @since 3.7.2
	 */
	$model_preference = apply_filters( 'snt_ai_model_preference', 'claude-sonnet-4-6', $prompt, $system_instruction );

	try {
		$result = wp_ai_client_prompt( (string) $prompt )
			->using_model_preference( (string) $model_preference )
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
