<?php
/**
 * Signal & Noise — AI bootstrap: the model price table and cost estimation.
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
 * Provides: snt_ai_model_pricing(), snt_ai_estimate_cost()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
