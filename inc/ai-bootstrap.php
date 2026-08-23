<?php
/**
 * Signal & Noise Tools — AI bootstrap (loader).
 *
 * The AI surface lives in inc/ai-bootstrap/, one file per concern. This file
 * held all of it — 1,054 lines — until the v12.21.4 split.
 *
 * Unlike the admin-post and content-migration splits before it, this layer has
 * NO registry and NO dispatch map. Other modules call these functions DIRECTLY:
 * snt_ai_is_available() from 26 files, snt_ai_generate_with_constraints() from
 * 25, snt_ai_require_text_generation() from 17. The public surface IS the
 * contract, which is what tests/ai-bootstrap-surface-coverage.php pins — all 21
 * declarations, the eight SN_AI_* constants, the two load-time route
 * registrations, and the single admin_enqueue_scripts hook.
 *
 * This file keeps everything that RUNS at load time, in a deliberate order:
 *
 *   1. the SN_AI_* constants,
 *   2. the requires,
 *   3. the two model-route registrations and the admin_enqueue_scripts hook.
 *
 * Step 3 must follow step 2. Those registrations are BARE CALLS, not hooks, so
 * the functions they invoke have to be declared by the time they execute. Lose
 * one of those calls in a move and no filter is registered — alt-text generation
 * silently falls back to the default model, at the wrong price, with nothing
 * raised anywhere.
 *
 * One declaration here returns BY REFERENCE — `function &snt_ai_availability_cache()`
 * in ai-bootstrap/availability.php. The `&` is load-bearing and is asserted
 * verbatim by the surface suite; a regex that inventories declarations must
 * account for it or the function becomes invisible.
 *
 * @package SignalNoiseTools
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
// it is required BY PATH from the plugin bootstrap and from several suites.
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

// Load-time wiring. These are bare calls, not hooks — the requires above must
// already have declared the functions they invoke. Each adds one filter on
// snt_ai_model_preference; running either twice would add its filter twice.
snt_ai_register_alt_text_model_route();
snt_ai_register_economy_model_route();

add_action( 'admin_enqueue_scripts', 'snt_register_status_script' );
