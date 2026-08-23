<?php
/**
 * Signal & Noise — AI bootstrap: the constrained generation call and its error shaping.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_generate_with_constraints(), snt_ai_error_with_message()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * @param string $feature             Optional feature label for the usage log
 *                                    (e.g. 'insights', 'insights_narration'). Default 'generic'.
 *                                    ALSO routes the model: passed to the
 *                                    snt_ai_model_preference filter so a feature
 *                                    can use a different model (e.g. 'alt-text'
 *                                    → a vision-capable Gemini model). @since 6.48.0.
 * @param string $image_path          Optional ABSOLUTE local path to an image to
 *                                    attach for vision (multimodal input). When
 *                                    readable, base64-inlined via ->with_file().
 *                                    Pass a LOCAL PATH, never a URL (CF-safe).
 *                                    Empty = text-only (byte-identical to before).
 *                                    @since 6.48.0.
 * @param string $image_mime          MIME type of $image_path (e.g. 'image/jpeg').
 *                                    Required alongside $image_path. @since 6.48.0.
 * @return string|WP_Error            Generated text or WP_Error on failure.
 */
function snt_ai_generate_with_constraints( $prompt, $system_instruction, $max_tokens = 256, $feature = 'generic', $image_path = '', $image_mime = '' ) {
	if ( ! snt_ai_is_available() ) {
		return new WP_Error(
			'snt_ai_unavailable',
			__( 'WP AI Client is not installed or activated. Install the wp-ai-client plugin (WP 6.x) or upgrade to WordPress 7.0+.', 'signal-and-noise-tools' ),
			array( 'status' => 503 )
		);
	}

	// v9.26.0: monthly spend cap. When the owner sets a budget (> 0) and this
	// month's accumulated spend has already reached it, stop before spending
	// more. Centralized here so EVERY AI feature is gated uniformly and
	// predictably. Default 0 = off, so nothing changes until a budget is set.
	$sn_ai_budget = function_exists( 'sn_setting' ) ? (float) sn_setting( 'theme.ai_monthly_budget', 0 ) : 0.0;
	if ( $sn_ai_budget > 0 ) {
		$sn_ai_spent = snt_ai_spend_this_month();
		if ( $sn_ai_spent >= $sn_ai_budget ) {
			return new WP_Error(
				'snt_ai_over_budget',
				sprintf(
					/* translators: 1: month-to-date spend, 2: monthly budget, both USD */
					__( 'Monthly AI budget reached (%1$s of %2$s). Raise it on the Front-End settings tab, or wait for next month.', 'signal-and-noise-tools' ),
					'$' . number_format( $sn_ai_spent, 2 ),
					'$' . number_format( $sn_ai_budget, 2 )
				),
				array( 'status' => 402 )
			);
		}
	}

	$max_tokens = max( 1, min( 4096, (int) $max_tokens ) );

	/**
	 * Pinned model preference for ALL SN AI text generation.
	 *
	 * Why pin rather than let the AI Client pick:
	 * - The Anthropic provider plugin defaults to the most-capable available
	 *   model (the Fable/Opus tier), several times the cost of Sonnet.
	 * - The v3.6.0 Insights plan budgeted ~$0.01/scan based on Sonnet pricing.
	 *   Unpinned, the most-capable model was being used → ~$0.10/scan in
	 *   production (verified via AI Request Logs 2026-05-21: a single Insights
	 *   call at the Opus tier was 4.9K tokens = ~$0.10).
	 * - Pinning by string model ID (per usingModelPreference signature) lets
	 *   the call still route through any provider that exposes the same model
	 *   ID, so the pin is portable across provider changes.
	 *
	 * Filter `snt_ai_model_preference` lets callers override per feature; the
	 * Front-End settings tab feeds the owner's dropdown choice through it (see
	 * sn_tf_ai_model in inc/theme-filters.php) and the alt-text route overrides
	 * to a vision model below. The default is SN_AI_DEFAULT_MODEL.
	 *
	 * Per php-ai-client/src/Builders/PromptBuilder.php:
	 * `usingModelPreference(...$preferredModels)` accepts a LIST — the provider
	 * picks the first id it actually exposes — so we pass [resolved, fallback].
	 *
	 * @since 3.7.2
	 */
	// v6.48.0: $feature is now passed to the filter (4th arg) so callers can route
	// per feature, e.g. alt-text → a vision-capable Gemini model (see the default
	// route registered below in snt_ai_register_alt_text_model_route). Existing
	// single-arg filters keep working — the extra arg is ignored by them.
	$model_preference = apply_filters( 'snt_ai_model_preference', SN_AI_DEFAULT_MODEL, $prompt, $system_instruction, $feature );

	// v6.52.0: build the variadic preference LIST — the resolved model first, then
	// SN_AI_FALLBACK_MODEL as a known-good Sonnet safety net (deduped so a pin that
	// already equals the fallback stays a single id). using_model_preference()
	// picks the first id the configured provider exposes, so a just-released id not
	// yet in the provider's cached /v1/models list degrades to Sonnet 5 instead
	// of falling through to the provider's most-capable (expensive) default.
	$model_list = array_values( array_unique( array_filter( array( (string) $model_preference, SN_AI_FALLBACK_MODEL ) ) ) );

	// v6.48.0: optional image input (vision). When a readable local image path +
	// mime are supplied, attach it via the wp-ai-client builder's ->with_file()
	// (snake_case, __call-routed to PromptBuilder::withFile(), which base64-inlines
	// a local file). Pass the LOCAL PATH, never a URL: this site is behind
	// Cloudflare with Cache-Everything HTML + possible challenge pages, so a
	// provider-side URL fetch could land on a challenge/interstitial and corrupt
	// the image (memory reference_cf_challenge_cache_poisoning_static_assets). The
	// text-only path stays byte-identical to pre-v6.48.0 when no image is passed.
	$has_image = ( '' !== (string) $image_path && '' !== (string) $image_mime && is_readable( (string) $image_path ) );

	try {
		$builder = wp_ai_client_prompt( (string) $prompt )
			->using_model_preference( ...$model_list )
			->using_system_instruction( (string) $system_instruction )
			->using_max_tokens( $max_tokens );
		if ( $has_image ) {
			$builder = $builder->with_file( (string) $image_path, (string) $image_mime );
		}
		$result = $builder->generate_text_result();
	} catch ( \Throwable $e ) {
		// Defense in depth — the WP wrapper already converts SDK exceptions
		// to WP_Error, but a misconfigured provider or PHP runtime error
		// can still bubble. Catch + convert so callers always get WP_Error.
		return new WP_Error(
			'snt_ai_runtime_error',
			/* translators: %s is the runtime error message */
			sprintf( __( 'AI runtime error: %s', 'signal-and-noise-tools' ), $e->getMessage() ),
			array( 'status' => 500 )
		);
	}

	if ( is_wp_error( $result ) ) {
		// v8.1.1: the WP wrapper's converted SDK errors can carry an EMPTY
		// message (seen live 2026-07-02 as an undiagnosable "Unknown error."
		// in the Health-tab JS). Guarantee a code-labeled message.
		return snt_ai_error_with_message( $result );
	}

	// v6.29.0: capture token usage BEFORE extracting the body. The prior
	// ->generate_text() returned a bare string and the SDK discarded
	// TokenUsage internally; ->generate_text_result() preserves it. Record
	// here — even an empty body still consumed prompt tokens, so spend is
	// logged regardless of whether the body validates below.
	snt_ai_record_usage( $feature, (string) $model_preference, $result );

	// v7.1.2 CRITICAL: the wp-ai-client's toText() THROWS
	// (WordPress\AiClient\Common\Exception\RuntimeException "No text content found
	// in first candidate", GenerativeAiResult.php:197) when the model returns a
	// result whose first candidate has no text part — an empty / stopped / refused
	// / non-text completion — instead of returning ''. Called bare, that uncaught
	// exception fataled the WHOLE request live (a critical-error page on the
	// Insights scan + Weekly digest, confirmed via the Cloudways PHP error log).
	// Catch it and fall through to the graceful empty-response WP_Error below, so
	// a no-text result degrades to a normal error notice, never a site crash.
	// Every SN AI feature (scan, digest, alt-text, meta, titles…) routes through
	// this one helper, so the guard protects all of them.
	try {
		$body = ( is_object( $result ) && is_callable( array( $result, 'toText' ) ) ) ? (string) $result->toText() : '';
	} catch ( \Throwable $e ) {
		$body = '';
	}
	$text = trim( $body );
	if ( '' === $text ) {
		return new WP_Error(
			'snt_ai_empty_response',
			__( 'AI returned an empty response (the model produced no text). Try again; if it recurs, the prompt may be hitting the model output limit.', 'signal-and-noise-tools' ),
			array( 'status' => 502 )
		);
	}

	// v4.1.6 (D-10): centralized post-AI quote-strip. Sonnet 4.6 + competing
	// models occasionally wrap single-line outputs in surrounding double or
	// single quotes despite explicit "no quotes" system instructions. Pre-v4.1.6
	// the trim was duplicated in 4 caller sites (ai-alt-text-suggest:138,
	// ai-alt-inline-suggest:174, ai-drift-phrase-suggest:235, ai-meta-description:120).
	// Centralizing here means callers receive a clean string; any future caller
	// gets the same defense automatically. JSON-shaped outputs (where the model
	// returns `{"foo":"bar"}`) are unaffected because the outermost characters
	// are braces, not quotes — trim() with this charset only strips MATCHING
	// outer quote characters at the very start/end of the string.
	$text = trim( $text, "\"'" );

	return $text;
}

/**
 * Guarantee a transport WP_Error carries a human-readable message.
 *
 * v8.1.1: wp-ai-client converts SDK exceptions to WP_Error using the
 * exception's message, which can be EMPTY (e.g. provider overload/rate-limit
 * shapes). An empty message rides the REST error response to the admin JS,
 * whose fallback rendered the undiagnosable "Unknown error." (live
 * 2026-07-02, Suggest-All burst). Errors with text pass through untouched;
 * empty ones get a code-labeled message and an error_log line for forensics.
 *
 * @param WP_Error $error The transport error.
 * @return WP_Error Same instance, or a re-messaged copy (code + data kept).
 *
 * @since 8.1.1
 */
function snt_ai_error_with_message( $error ) {
	if ( '' !== trim( (string) $error->get_error_message() ) ) {
		return $error;
	}
	$code = (string) $error->get_error_code();
	if ( '' === $code ) {
		$code = 'snt_ai_transport_error';
	}
	error_log( 'snt_ai transport error with empty message, code: ' . $code );
	$data = $error->get_error_data();
	return new WP_Error(
		$code,
		/* translators: %s is the transport error code */
		sprintf( __( 'AI transport error (%s). Try again; if it recurs, check the provider status page.', 'signal-and-noise-tools' ), $code ),
		is_array( $data ) && ! empty( $data ) ? $data : array( 'status' => 502 )
	);
}
