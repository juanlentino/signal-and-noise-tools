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








snt_ai_register_alt_text_model_route();


snt_ai_register_economy_model_route();


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
 * v10.70.0: optionally takes the TRUE token split observed at the HTTP layer.
 * Cached input does not bill at the input rate — reads bill at 0.1x and writes
 * at 1.25x — so pricing a flattened figure over-bills a cached span by up to
 * 10x. When $split is supplied it supersedes $prompt entirely; when it is null
 * the 3-arg behaviour is unchanged, which is what every caller got before and
 * what a caller still gets when no observation matched.
 *
 * @since 6.41.0
 * @param string     $model      Model ID (served model preferred).
 * @param int        $prompt     Prompt/input tokens (the DTO's flattened sum).
 * @param int        $completion Completion/output tokens.
 * @param array|null $split      Optional { in, cache_write, cache_read }.
 * @return float USD cost (0.0 when the model is unpriced).
 */
function snt_ai_estimate_cost( $model, $prompt, $completion, $split = null ) {
	$rates = snt_ai_model_pricing();
	$key   = (string) $model;
	if ( ! isset( $rates[ $key ]['in'], $rates[ $key ]['out'] ) ) {
		return 0.0;
	}

	// Effective input units: fresh tokens at 1x, cache writes dearer, cache
	// reads an order of magnitude cheaper.
	$input_units = (int) $prompt;
	if ( is_array( $split ) && isset( $split['in'], $split['cache_write'], $split['cache_read'] ) ) {
		$input_units = (float) $split['in']
			+ (float) $split['cache_write'] * SN_AI_CACHE_WRITE_MULT
			+ (float) $split['cache_read'] * SN_AI_CACHE_READ_MULT;
	}

	return ( $input_units * (float) $rates[ $key ]['in']
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
