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
 * v10.70.0: Anthropic prompt-cache price multipliers, relative to the model's
 * INPUT rate. A cache write costs more than fresh input; a cache read costs an
 * order of magnitude less. Pricing a cached span at 1x — which is what the WP
 * AI Client's flattened inputTokens forces — over-bills by up to 10x.
 *
 * 1.25x is the 5-minute-TTL write rate (the 1-hour TTL is 2x). We pin the
 * 5-minute figure because that is the only TTL reachable today: nothing in the
 * provider emits `cache_control` at all, so no TTL is selectable. Revisit
 * alongside WordPress/ai-provider-for-anthropic#33.
 */
define( 'SN_AI_CACHE_WRITE_MULT', 1.25 );
define( 'SN_AI_CACHE_READ_MULT', 0.1 );

// The surface lives one directory down, one file per concern. This file stays:
// it is required BY PATH from the plugin bootstrap and from several suites, and
// it keeps the parts that RUN at load time — the SN_AI_* constants above, the
// two model-route registrations and the admin_enqueue_scripts hook below.
//
// The requires sit between them deliberately: the route registrations are bare
// calls, not hooks, so the functions they invoke must already be declared.
//
// __DIR__ rather than SNT_PATH: several suites require this file without the
// plugin bootstrap, so that constant is not guaranteed to be defined.
require_once __DIR__ . '/ai-bootstrap/availability.php';
require_once __DIR__ . '/ai-bootstrap/capability.php';
require_once __DIR__ . '/ai-bootstrap/generate.php';
require_once __DIR__ . '/ai-bootstrap/model-routes.php';
require_once __DIR__ . '/ai-bootstrap/usage-log.php';
require_once __DIR__ . '/ai-bootstrap/spend.php';
require_once __DIR__ . '/ai-bootstrap/pricing.php';
require_once __DIR__ . '/ai-bootstrap/usage-summary.php';








snt_ai_register_alt_text_model_route();


snt_ai_register_economy_model_route();








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

/**
 * Shared editor-asset enqueue for the gen-1 per-post AI buttons (v9.81.0).
 *
 * ai-excerpt.php, ai-meta-description.php, and ai-og-card-title.php each
 * carried a near-identical ~35-line admin_enqueue_scripts closure that had
 * already drifted (inconsistent function_exists guards around the AI gate).
 * This helper is the single copy: post-edit screens only, gated on
 * snt_ai_is_available() + edit_posts, base dep set (wp-api-fetch, wp-i18n,
 * snt-status for window.sntSetStatus, snt-ability-run for the ability
 * transport) plus per-caller extras, localization, and script translations.
 *
 * @param string $hook_suffix The admin_enqueue_scripts hook suffix.
 * @param string $handle      Script handle to register/enqueue.
 * @param string $file        Filename under assets/.
 * @param string $object_name JS object name for wp_localize_script.
 * @param array  $data        Localized data.
 * @param array  $extra_deps  Per-caller deps appended to the base set.
 * @return void
 *
 * @since 9.81.0
 */
function snt_ai_enqueue_editor_script( $hook_suffix, $handle, $file, $object_name, $data, $extra_deps = array() ) {
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
		$handle,
		plugins_url( 'assets/' . $file, SNT_PATH . 'signal-and-noise-tools.php' ),
		array_merge( array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ), $extra_deps ),
		SNT_VERSION,
		true
	);

	wp_localize_script( $handle, $object_name, $data );
	wp_enqueue_script( $handle );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'signal-and-noise-tools' );
	}
}
