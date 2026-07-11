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
 * Per-call AI token-usage observability (v6.29.0).
 *
 * snt_ai_generate_with_constraints() records every call's token usage to a
 * capped FIFO option so AI spend is trendable in SN's own data — without
 * depending on the WordPress/ai plugin's off-by-default "AI Request Logs"
 * experiment or the provider console. Token counts only: the wp-ai-client
 * TokenUsage DTO exposes no cache-read/write breakdown.
 */
define( 'SN_AI_USAGE_LOG_OPT', 'sn_ai_usage_log' );
define( 'SN_AI_USAGE_LOG_CAP', 200 );

// v9.26.0: durable month-to-date AI spend rollup, keyed YYYY-MM. Unlike the
// capped FIFO log above (which evicts old entries), this never loses history
// within the retained window, so month-to-date spend is exact even under heavy
// use. Feeds the optional monthly budget cap and the AI-spend readout. Keep ~1
// year of buckets.
define( 'SN_AI_SPEND_ROLLUP_OPT', 'sn_ai_spend_month' );
define( 'SN_AI_SPEND_MONTHS', 13 );

// v6.52.0: the preferred + safety-net text model for ALL SN AI generation.
// SN_AI_DEFAULT_MODEL is the model SN pins by default; SN_AI_FALLBACK_MODEL is
// appended as a SECOND preference so the variadic using_model_preference() has
// a known-good Sonnet to fall to. This matters because the WP AI Client resolves
// ids LIVE from the provider's /v1/models: if a just-released id is not yet in
// the provider's cached list, an unguarded single pin would fall through to the
// provider's most-capable default (Opus/Fable) — the exact expensive-model
// surprise the pin exists to prevent. With the fallback it degrades to Sonnet
// 5 instead. Both are alias ids (no date suffix). See snt_ai_model_pricing().
// The fallback tracks the default (both Sonnet 5): same price as the retired
// 4.6 pin but strictly better, and now universally resolvable, so it is the
// current known-good net for any owner-picked id the provider can't resolve.
define( 'SN_AI_DEFAULT_MODEL',  'claude-sonnet-5' );
define( 'SN_AI_FALLBACK_MODEL', 'claude-sonnet-5' );

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
	$cache = &snt_ai_availability_cache();
	if ( null !== $cache ) {
		return $cache;
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		$cache = false;
		return $cache;
	}

	try {
		$builder = wp_ai_client_prompt( 'check' );
		if ( ! is_object( $builder ) ) {
			$cache = false;
			return $cache;
		}
		$cache = (bool) $builder->is_supported_for_text_generation();
		return $cache;
	} catch ( \Throwable $e ) {
		// v3.7.6: server-log surfacing per v3.7.1 lesson. The bug-of-record
		// for SN AI features (6 months of silently no-op'ing in v2.5.0–v3.7.0)
		// was a silent guard. Even though this catch returns false correctly
		// from the v3.7.1 fix, runtime exceptions deserve a log trail so future
		// regressions surface in PHP error log instead of vanishing.
		//
		// v6.39.2: caching the false below means this catch (and its error_log)
		// fires AT MOST ONCE per request even on a persistently broken provider,
		// where pre-cache it spammed the log on all 15-23 admin call sites.
		error_log( 'snt_ai_can_text_generate exception: ' . $e->getMessage() );
		$cache = false;
		return $cache;
	}
}

/**
 * Request-static storage for the AI availability check (v6.39.2 PERF).
 *
 * Returned BY REFERENCE so both snt_ai_can_text_generate() (which writes the
 * memoized bool) and snt_ai_reset_availability_cache() (which clears it) share
 * one slot without a global. `null` = not yet derived; `true`/`false` = derived.
 *
 * Availability is genuinely request-stable in production (provider/connector
 * config does not change inside a single PHP request), so a parameterless
 * request-static is correct. The only reason it needs a reset at all is the
 * standalone test harness, which toggles provider state between assertion
 * blocks in one process — see tests/ai-bootstrap.php::fixture_reset().
 *
 * @return bool|null Reference to the cache slot.
 *
 * @since 6.39.2
 */
function &snt_ai_availability_cache() {
	static $cache = null;
	return $cache;
}

/**
 * Clear the request-static AI availability cache, forcing the next
 * snt_ai_can_text_generate() call to re-derive from the provider.
 *
 * Production has no need to call this (availability is request-stable). It
 * exists for the test harness, and as an escape hatch if a future feature
 * legitimately changes provider config mid-request (it would call this after).
 *
 * @return void
 *
 * @since 6.39.2
 */
function snt_ai_reset_availability_cache() {
	$cache = &snt_ai_availability_cache();
	$cache = null;
}

/**
 * Public name for the same check. Convention (audit D-12, v4.1.6):
 *
 *   - **`snt_ai_is_available()`** — preferred at CALL SITES (admin enqueue
 *     guards, REST permission_callbacks, conditional rendering). Reads as
 *     "is AI available?" which is what the caller actually wants to know.
 *   - **`snt_ai_can_text_generate()`** — internal implementation detail
 *     (the underlying capability check against wp-ai-client). Stays as the
 *     impl-named function for clarity when reading ai-bootstrap.php itself.
 *
 * Every external caller in inc/ uses `snt_ai_is_available()` after the
 * v4.1.1 D-03 consolidation (verified by `grep -rn 'snt_ai_can_text_generate' inc/`).
 * Keep both names indefinitely — renaming the impl would touch the wp-ai-client
 * provenance trail in the docblock above.
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
		__( 'AI text generation is not available. Upgrade to WordPress 7.0+ and configure a provider in Settings > Connectors.', 'signal-and-noise-tools' ),
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

/**
 * v6.48.0: route the 'alt-text' feature to a vision-capable Gemini Flash model.
 *
 * Registered as a default `snt_ai_model_preference` filter so the routing lives
 * in the repo, not in a deployment-time filter. Alt text is fundamentally about
 * describing what is IN the image, so it goes to a multimodal model (which also
 * receives the attached image via ->with_file() in the seam above); every other
 * feature stays on the pinned Claude Sonnet text model. The Gemini id is itself
 * filterable via `snt_ai_alt_text_model` so the owner can re-pin (e.g. to
 * gemini-2.5-flash) with NO release — the WP AI Client resolves Gemini ids LIVE
 * from Google's API, so the exact resolvable id depends on the configured provider
 * + key/region. Default = gemini-2.5-flash-lite (Google's cheapest multimodal
 * Flash, ideal for bulk alt-text). Registered with accepted_args=4 so the callback
 * receives $feature.
 *
 * Registered via a named function (not a bare add_filter at file scope) so the
 * test harness can re-register it after it clears its filter registry between
 * blocks.
 *
 * @since 6.48.0
 */
function snt_ai_register_alt_text_model_route() {
	add_filter(
		'snt_ai_model_preference',
		function ( $model, $prompt, $system_instruction, $feature = 'generic' ) {
			if ( 'alt-text' === $feature ) {
				// v7.3.0: the settings dropdown (theme.ai_alt_model) feeds the
				// DEFAULT; the snt_ai_alt_text_model filter still wins for
				// code-level pins. Absent setting = the original pin.
				$alt_default = function_exists( 'sn_setting' )
					? (string) sn_setting( 'theme.ai_alt_model', 'gemini-2.5-flash-lite' )
					: 'gemini-2.5-flash-lite';
				return (string) apply_filters( 'snt_ai_alt_text_model', $alt_default );
			}
			return $model;
		},
		10,
		4
	);
}
snt_ai_register_alt_text_model_route();

/**
 * v9.26.0: the feature labels routed to the economy text model.
 *
 * Short, mechanical prose one-liners a human judges at a glance — NOT the
 * structured-JSON suggesters (link/orphan/pair, whose parse-robustness we care
 * about) or the reasoning calls (insights, insights_narration, drift_detect,
 * release_notes), which stay on the default model. Filterable so a deployment
 * can add or drop economy features with no release.
 *
 * @since 9.26.0
 * @return string[] Economy feature labels (see snt_ai_generate_with_constraints $feature).
 */
function snt_ai_economy_features() {
	return (array) apply_filters(
		'snt_ai_economy_features',
		array( 'meta_desc', 'excerpt', 'og_title', 'drift_phrase', 'tag_suggest' )
	);
}

/**
 * v9.26.0: economy-tier model routing for the short one-liner features.
 *
 * The default text model (Sonnet 5) is right for reasoning-heavy calls, but the
 * HIGHEST-FREQUENCY calls are tiny prose one-liners that fire on every post
 * save (a 150-char meta description, a 60-token OG title, a tag list). Those
 * don't need a premium model: Haiku 4.5 is ~3x cheaper ($1/$5 vs $3/$15 per
 * MTok) at effectively equal quality on a glance-judged task. That is a
 * decision, not a preference, so it ships as a default FLOOR rather than a
 * settings toggle.
 *
 * Priority 20 — AFTER the owner's model dropdown (sn_tf_ai_model, priority 10)
 * — makes it a hard floor: economy features run on Haiku even if the owner
 * picks Opus for everything else. Every non-economy feature still follows the
 * dropdown. The alt-text route (priority 10) is disjoint ('alt-text' is not an
 * economy feature) and unaffected.
 *
 * Escape hatch: `snt_ai_economy_model` receives (model, feature,
 * inherited_model) — return the inherited model to opt a feature back onto the
 * owner's choice, or a different id to re-pin. Named function (not a bare
 * add_filter) so the test harness can re-register after clearing filters,
 * mirroring snt_ai_register_alt_text_model_route().
 *
 * @since 9.26.0
 * @return void
 */
function snt_ai_register_economy_model_route() {
	add_filter(
		'snt_ai_model_preference',
		function ( $model, $prompt, $system_instruction, $feature = 'generic' ) {
			if ( in_array( $feature, snt_ai_economy_features(), true ) ) {
				return (string) apply_filters( 'snt_ai_economy_model', 'claude-haiku-4-5', $feature, $model );
			}
			return $model;
		},
		20,
		4
	);
}
snt_ai_register_economy_model_route();

/**
 * Record one AI call's token usage to the capped FIFO log option.
 *
 * Reads the TokenUsage DTO off the GenerativeAiResult — the metadata that
 * the prior ->generate_text() path discarded inside the SDK. Every accessor
 * is is_callable()-guarded so a provider/connector that doesn't populate
 * usage degrades to a no-op rather than fataling.
 *
 * Two model fields are stored: `model` is the requested preference string
 * (kept as the cost-summary key for backward-compat), and `served_model`
 * (v6.39.2) is the model the provider ACTUALLY served — read via the now-
 * verified GenerativeAiResult::getModelMetadata()->getId() accessor — so
 * attribution survives a provider substituting a model. `served_model`
 * degrades to '' when the accessor is absent.
 *
 * @param string $feature Feature label (e.g. 'insights', 'insights_narration').
 * @param string $model   Requested model preference string.
 * @param mixed  $result  GenerativeAiResult from generate_text_result().
 * @return void
 *
 * @since 6.29.0
 */
function snt_ai_record_usage( $feature, $model, $result ) {
	if ( ! is_object( $result ) || ! is_callable( array( $result, 'getTokenUsage' ) ) ) {
		return;
	}
	$usage = $result->getTokenUsage();
	if ( ! is_object( $usage ) ) {
		return;
	}

	$prompt_t     = is_callable( array( $usage, 'getPromptTokens' ) ) ? (int) $usage->getPromptTokens() : 0;
	$completion_t = is_callable( array( $usage, 'getCompletionTokens' ) ) ? (int) $usage->getCompletionTokens() : 0;
	$total_t      = is_callable( array( $usage, 'getTotalTokens' ) ) ? (int) $usage->getTotalTokens() : ( $prompt_t + $completion_t );

	// v6.39.2: also record the model the provider ACTUALLY served, so cost
	// attribution survives a provider substituting a different model than the
	// pinned `$model` preference (e.g. Sonnet requested, a fallback served). The
	// requested `model` field stays as-is for backward-compat with the usage
	// summary; `served_model` is additive. Every hop is is_callable-guarded —
	// an older SDK / a provider that doesn't populate ModelMetadata degrades to
	// '' rather than fataling. Accessor verified against php-ai-client trunk:
	// GenerativeAiResult::getModelMetadata(): ModelMetadata → getId(): string.
	$served_model = '';
	if ( is_callable( array( $result, 'getModelMetadata' ) ) ) {
		$meta = $result->getModelMetadata();
		if ( is_object( $meta ) && is_callable( array( $meta, 'getId' ) ) ) {
			$served_model = (string) $meta->getId();
		}
	}

	$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'ts'           => time(),
		'feature'      => (string) $feature,
		'model'        => (string) $model,
		'served_model' => $served_model,
		'prompt'       => $prompt_t,
		'completion'   => $completion_t,
		'total'        => $total_t,
	);
	if ( count( $log ) > SN_AI_USAGE_LOG_CAP ) {
		$log = array_slice( $log, -SN_AI_USAGE_LOG_CAP );
	}
	update_option( SN_AI_USAGE_LOG_OPT, $log, false );

	// v9.26.0: also fold this call's cost into the durable monthly rollup, which
	// (unlike the capped FIFO log above) never evicts — so the budget cap and the
	// spend readout see a full month even under heavy use. Served model wins for
	// attribution when the provider substituted a different one.
	snt_ai_add_month_spend(
		snt_ai_estimate_cost( '' !== $served_model ? $served_model : (string) $model, $prompt_t, $completion_t )
	);
}

/**
 * v9.26.0: the current spend-rollup bucket key, site-local YYYY-MM.
 *
 * Uses wp_date() (site timezone) when available so the "month" matches the
 * owner's calendar; falls back to gmdate() outside WordPress (CLI tests).
 *
 * @return string
 * @since 9.26.0
 */
function snt_ai_spend_month_key() {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m' ) : gmdate( 'Y-m' );
}

/**
 * v9.26.0: fold one call's USD cost into the current month's rollup bucket.
 *
 * A single autoload=no option keyed YYYY-MM, pruned to the last
 * SN_AI_SPEND_MONTHS buckets (keys sort chronologically). No-op on a
 * zero/negative cost (e.g. an unpriced model, per snt_ai_estimate_cost).
 *
 * @param float $cost USD cost of the call.
 * @return void
 * @since 9.26.0
 */
function snt_ai_add_month_spend( $cost ) {
	$cost = (float) $cost;
	if ( $cost <= 0 ) {
		return;
	}
	$roll = get_option( SN_AI_SPEND_ROLLUP_OPT, array() );
	if ( ! is_array( $roll ) ) {
		$roll = array();
	}
	$key          = snt_ai_spend_month_key();
	$roll[ $key ] = round( ( isset( $roll[ $key ] ) ? (float) $roll[ $key ] : 0.0 ) + $cost, 6 );
	if ( count( $roll ) > SN_AI_SPEND_MONTHS ) {
		ksort( $roll );
		$roll = array_slice( $roll, -SN_AI_SPEND_MONTHS, null, true );
	}
	update_option( SN_AI_SPEND_ROLLUP_OPT, $roll, false );
}

/**
 * v9.26.0: this calendar month's accumulated AI spend in USD (0.0 if none).
 *
 * Reads the durable rollup, so it reflects the whole month regardless of the
 * FIFO usage log's 200-entry cap. Feeds the budget cap and the spend readout.
 *
 * @return float
 * @since 9.26.0
 */
function snt_ai_spend_this_month() {
	$roll = get_option( SN_AI_SPEND_ROLLUP_OPT, array() );
	if ( ! is_array( $roll ) ) {
		return 0.0;
	}
	$key = snt_ai_spend_month_key();
	return isset( $roll[ $key ] ) ? (float) $roll[ $key ] : 0.0;
}

/**
 * Per-model list pricing (USD per 1M tokens) for estimating AI spend from the
 * token counts snt_ai_record_usage() already logs.
 *
 * Rates are Anthropic's published list prices
 * (https://platform.claude.com/docs/en/about-claude/pricing) — UPDATE this map
 * when Anthropic changes pricing. Keyed by model ID, matched against each
 * call's SERVED model (falling back to the requested preference). The estimate
 * is list-price based: it does NOT reflect prompt-cache or batch discounts, so
 * it is a close upper bound on real spend, not the billed figure. The WordPress
 * AI Request Logs (Settings → AI) and the provider Console hold the
 * authoritative per-request record.
 *
 * Filterable via `snt_ai_model_pricing` so a deployment can override or extend
 * rates without editing the plugin.
 *
 * @since 6.41.0
 * @return array<string, array{in: float, out: float}> Rates per 1M tokens.
 */
function snt_ai_model_pricing() {
	$rates = array(
		'claude-opus-4-8'   => array( 'in' => 5.0, 'out' => 25.0 ),
		'claude-opus-4-7'   => array( 'in' => 5.0, 'out' => 25.0 ),
		'claude-opus-4-6'   => array( 'in' => 5.0, 'out' => 25.0 ),
		'claude-opus-4-5'   => array( 'in' => 5.0, 'out' => 25.0 ),
		// v6.52.0: Claude Sonnet 5 at standard list ($3/$15 per MTok). The intro
		// $2/$10 runs through 2026-08-31, but this readout is a durable list-price
		// estimate that already disclaims discounts, so we hold the standard rate.
		'claude-sonnet-5'   => array( 'in' => 3.0, 'out' => 15.0 ),
		'claude-sonnet-4-6' => array( 'in' => 3.0, 'out' => 15.0 ),
		'claude-sonnet-4-5' => array( 'in' => 3.0, 'out' => 15.0 ),
		'claude-haiku-4-5'  => array( 'in' => 1.0, 'out' => 5.0 ),
		'claude-fable-5'    => array( 'in' => 10.0, 'out' => 50.0 ),
		// v6.48.1: Google Gemini Flash rates (the v6.48.0 alt-text vision route
		// uses gemini-2.5-flash-lite by default; flash listed too for a re-pin via
		// the snt_ai_alt_text_model filter). Standard paid tier, text/image input
		// (audio input is dearer but SN never sends audio); per 1M tokens, from
		// Google's official pricing page (ai.google.dev/gemini-api/docs/pricing,
		// verified 2026-06-28). Image input is billed at the text/image input rate.
		'gemini-2.5-flash-lite' => array( 'in' => 0.10, 'out' => 0.40 ),
		'gemini-2.5-flash'      => array( 'in' => 0.30, 'out' => 2.50 ),
	);
	return apply_filters( 'snt_ai_model_pricing', $rates );
}

/**
 * Estimate the USD cost of one AI call from its token split + model.
 *
 * Returns 0.0 for a model absent from snt_ai_model_pricing() — an unpriced
 * model contributes no dollar estimate rather than a fabricated one. Callers
 * that need to disclose unpriced volume count those calls separately (see
 * snt_ai_usage_summary()'s `cost_unpriced_calls`).
 *
 * @since 6.41.0
 * @param string $model      Model ID (served model preferred).
 * @param int    $prompt     Prompt/input tokens.
 * @param int    $completion Completion/output tokens.
 * @return float USD cost (0.0 when the model is unpriced).
 */
function snt_ai_estimate_cost( $model, $prompt, $completion ) {
	$rates = snt_ai_model_pricing();
	$key   = (string) $model;
	if ( ! isset( $rates[ $key ]['in'], $rates[ $key ]['out'] ) ) {
		return 0.0;
	}
	return ( (int) $prompt * (float) $rates[ $key ]['in']
		+ (int) $completion * (float) $rates[ $key ]['out'] ) / 1000000.0;
}

/**
 * Summarize recorded AI token usage (and estimated cost) over the trailing
 * $days window.
 *
 * @param int $days Trailing window in days. Default 30.
 * @return array {
 *   calls, prompt, completion, total (ints),
 *   cost (float, USD list-price estimate), cost_unpriced_calls (int),
 *   window_start (int, oldest counted entry timestamp; 0 when none),
 *   by_feature: { <feature> => { calls, total, cost } }
 * }
 *
 * @since 6.29.0
 * @since 6.41.0 Adds `cost`, `cost_unpriced_calls`, `window_start`, and a per-feature `cost`.
 */
function snt_ai_usage_summary( $days = 30 ) {
	$out = array(
		'calls'               => 0,
		'prompt'              => 0,
		'completion'          => 0,
		'total'               => 0,
		'cost'                => 0.0,
		'cost_unpriced_calls' => 0,
		'window_start'        => 0,
		'by_feature'          => array(),
	);
	$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		return $out;
	}
	// Hoist the rate map once — snt_ai_usage_summary( 1 ) runs on the prepop
	// path, so we avoid re-running the `snt_ai_model_pricing` filter per entry.
	$rates  = snt_ai_model_pricing();
	$cutoff = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) || (int) ( $entry['ts'] ?? 0 ) < $cutoff ) {
			continue;
		}
		++$out['calls'];
		$ts = (int) ( $entry['ts'] ?? 0 );
		if ( 0 === $out['window_start'] || $ts < $out['window_start'] ) {
			$out['window_start'] = $ts;
		}
		$prompt_t     = (int) ( $entry['prompt'] ?? 0 );
		$completion_t = (int) ( $entry['completion'] ?? 0 );
		$out['prompt']     += $prompt_t;
		$out['completion'] += $completion_t;
		$out['total']      += (int) ( $entry['total'] ?? 0 );
		$f = (string) ( $entry['feature'] ?? 'generic' );
		if ( ! isset( $out['by_feature'][ $f ] ) ) {
			$out['by_feature'][ $f ] = array(
				'calls' => 0,
				'total' => 0,
				'cost'  => 0.0,
			);
		}
		++$out['by_feature'][ $f ]['calls'];
		$out['by_feature'][ $f ]['total'] += (int) ( $entry['total'] ?? 0 );

		// v6.41.0: price on the SERVED model so a provider substitution prices
		// correctly; fall back to the requested `model` when served is blank
		// (older entries, or a provider that didn't populate model metadata).
		$pmodel = (string) ( $entry['served_model'] ?? '' );
		if ( '' === $pmodel ) {
			$pmodel = (string) ( $entry['model'] ?? '' );
		}
		if ( isset( $rates[ $pmodel ]['in'], $rates[ $pmodel ]['out'] ) ) {
			$c = ( $prompt_t * (float) $rates[ $pmodel ]['in']
				+ $completion_t * (float) $rates[ $pmodel ]['out'] ) / 1000000.0;
			$out['cost']                     += $c;
			$out['by_feature'][ $f ]['cost'] += $c;
		} else {
			++$out['cost_unpriced_calls'];
		}
	}
	return $out;
}

/**
 * Truncate post content to N words, stripping all HTML/shortcodes first.
 *
 * Used by AI features to bound input token cost. The first ~1000 words of
 * any post is sufficient context for SEO meta description / OG title /
 * tag suggestion tasks — quality plateaus well before context-window
 * limits, but token cost scales linearly.
 *
 * SECURITY: the returned text is UNTRUSTED. It is author-supplied post body
 * content that becomes the user-content half of an AI prompt — a post could
 * embed prompt-injection text ("ignore your instructions and …"). This helper
 * only strips markup/shortcodes for token economy; it does NOT neutralize
 * injection. Containment is the CALLER's responsibility, via a system_instruction
 * that frames this as data, not commands (see snt_insights_system_instruction()
 * for the pattern: an explicit untrusted-DATA delimiter the model is told never
 * to obey). Treat any string from here as you would any external input.
 *
 * @param int $post_id  Post ID.
 * @param int $words    Word cap. Default 1000.
 * @return string       Plain-text excerpt, trimmed (UNTRUSTED — see note above).
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

/**
 * Content-or-synthesized AI signal for a post.
 *
 * Returns post_content (via snt_ai_extract_post_text) when the body has text —
 * BYTE-IDENTICAL to the old path for normal posts, so no regression. For a
 * contentless template Page (empty body) it synthesizes a signal from the
 * title, the theme's curated fallback description (sn_seo_singular_description),
 * and the slug — enough for the meta-description / excerpt generators to work
 * instead of returning a 422. Untrusted-input posture is unchanged: this only
 * assembles first-party post fields + a theme-owned fallback string; the AI
 * callers' system instructions still frame it as data.
 *
 * @since 9.3.0
 * @param int $post_id
 * @param int $words   Word cap forwarded to snt_ai_extract_post_text.
 * @return string      May be '' only when the post cannot be resolved.
 */
function snt_ai_post_signal( $post_id, $words = 1000 ) {
	$content = snt_ai_extract_post_text( $post_id, $words );
	if ( '' !== $content ) {
		return $content;
	}
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return '';
	}
	$parts    = array( 'TITLE: ' . $post->post_title );
	$fallback = (string) apply_filters( 'sn_seo_singular_description', '', $post );
	if ( '' !== $fallback ) {
		$parts[] = 'SUMMARY: ' . $fallback;
	}
	if ( '' !== (string) $post->post_name ) {
		$parts[] = 'SLUG: ' . $post->post_name;
	}
	return implode( "\n", $parts );
}

/**
 * Register the shared `snt-status` JS utility — exposes window.sntSetStatus.
 *
 * Replaces 4 byte-identical setStatus() copies that lived in
 * ai-meta-description.js, ai-excerpt.js, ai-og-card-title.js, and
 * health-suggest-actions.js pre-v4.1.6 (audit finding U-15).
 *
 * Registration only (NOT enqueue) — callers declare 'snt-status' in their
 * deps array and WP chains the load. Registering here in ai-bootstrap (which
 * is required early in the plugin bootstrap, before any AI feature file)
 * guarantees the handle exists at the time the consumer scripts enqueue.
 *
 * MUST be unconditional. health-suggest-actions.js — one of the consumers that
 * declares 'snt-status' as a dep — is enqueued UNCONDITIONALLY on the Health +
 * Tools tabs (since v4.5.2), because it also drives the NON-AI pattern-adoption
 * and block-migration Suggest buttons, which render with no AI gate. If
 * 'snt-status' is missing, WP_Dependencies::all_deps() silently DROPS the whole
 * dependent script (the handle is never queued → never printed → every Suggest
 * button is dead with no console error). The pre-v6.47.x gate on
 * snt_ai_is_available() therefore left those buttons inert on any no-AI /
 * broken-provider / WP<7.0 install — the same dead-button class v4.5.1 fixed at
 * the enqueue layer but not at the dependency layer. Registration != enqueue:
 * a registered-but-unenqueued handle is never output, so registering it on
 * every admin page is free.
 *
 * @since 4.1.6
 */
function snt_register_status_script() {
	wp_register_script(
		'snt-status',
		plugins_url( 'assets/snt-status.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'snt_register_status_script' );
