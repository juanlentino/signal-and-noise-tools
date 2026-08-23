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
require_once __DIR__ . '/ai-bootstrap/post-signal.php';
require_once __DIR__ . '/ai-bootstrap/editor-assets.php';








snt_ai_register_alt_text_model_route();


snt_ai_register_economy_model_route();










add_action( 'admin_enqueue_scripts', 'snt_register_status_script' );
