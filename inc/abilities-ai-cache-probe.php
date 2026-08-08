<?php
/**
 * Signal & Noise Tools — Abilities API: prompt-cache probe readout.
 *
 * Exposes the EXISTING read-only probe (inc/ai-cache-probe.php, v10.50.0) to
 * AI / automation callers. Until now the verdict rendered in exactly one place
 * — the Insights admin page (inc/insights-admin.php) — so the one question the
 * probe exists to answer ("would Anthropic prompt caching pay on this site,
 * and where?") could only be read by a human at a browser.
 *
 * Thin wrapper: no logic is duplicated. snt_ai_cache_probe_verdict() is the
 * single derive layer; this hands its return value through unchanged so the
 * admin panel and an agent can never disagree about the verdict.
 *
 * READ-ONLY, like the probe itself. Nothing here records, mutates, or enables
 * caching — turning caching on remains a separate, later decision that this
 * data exists to inform.
 *
 * @package SignalNoiseTools
 * @since 10.69.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/ai-cache-probe-status', array(
		'label'               => 'AI Prompt-Cache Probe Status',
		'description'         => 'Returns the prompt-cache probe verdict: whether enabling Anthropic prompt caching would pay on this site, and for which model. Call this before proposing or enabling prompt caching for any AI feature, and when reviewing AI spend — it answers with recorded evidence instead of an estimate. Read-only; never enables caching and never makes an AI call. The probe records every Anthropic call the site makes (including other plugins routed through the WP AI Client) via a read-only http_response hook, keeping a rolling 200 entries with a 300-second repeat window. `state` is the answer: `candidate` (a prefix clears the model minimum AND repeats inside the TTL — caching would pay), `no_repeats` (prefix is large enough but never repeats, so a cache write would never be read), `below_floor` (prefix never reaches the model minimum cacheable size — 1024 tokens on Sonnet 5, 4096 on Haiku 4.5, so the same prompt can be cacheable on one model and silently not on another), `unknown_floor` (the model minimum is not in the probe table), `caching_active` (a non-zero cache read was already observed — caching is on somewhere), `no_data` (no calls recorded yet). `best` names the strongest candidate model, or null. Note the WP AI Client cannot report this itself: its Anthropic provider sums cache-creation and cache-read tokens into one inputTokens figure, destroying the split before any caller sees it.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_ai_cache_probe_status',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'   => array(
					'type'        => 'string',
					'enum'        => array( 'no_data', 'caching_active', 'candidate', 'no_repeats', 'below_floor', 'unknown_floor' ),
					'description' => 'The verdict. See the ability description for what each state means.',
				),
				'summary' => array(
					'type'        => 'object',
					'description' => 'Recorded totals across the rolling log: call count, token figures, and the cache-read count that distinguishes caching_active from every other state.',
				),
				'models'  => array(
					'type'        => 'array',
					'description' => 'Per-model rows, strongest candidate first: max prefix size seen, whether it may clear that model\'s floor, and how many times a prefix repeated inside the TTL.',
				),
				'best'    => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'The strongest candidate row, or null when no model clears its floor.',
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// readonly => the run controller requires GET. This is a pure read of
				// an already-recorded option; it triggers no HTTP call of its own.
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/ai-cache-probe-status.
 *
 * Thin wrapper around snt_ai_cache_probe_verdict(). Returns the verdict
 * unchanged — the admin panel and this ability read the same derive layer, so
 * they cannot drift apart.
 *
 * @param array|null $input Unused; the ability takes no arguments.
 * @return array|WP_Error The verdict, or WP_Error when the probe is unavailable.
 */
function snt_ability_ai_cache_probe_status( $input ) {
	unset( $input );

	if ( ! function_exists( 'snt_ai_cache_probe_verdict' ) ) {
		return new WP_Error(
			'snt_ai_cache_probe_unavailable',
			'Prompt-cache probe module not loaded.',
			array( 'status' => 500 )
		);
	}

	$verdict = snt_ai_cache_probe_verdict();

	// The derive layer returns an array on every path today. Guard anyway: an
	// ability that fatals is worse than one that reports it cannot answer, and
	// this is the boundary where a future change there would surface.
	if ( ! is_array( $verdict ) ) {
		return new WP_Error(
			'snt_ai_cache_probe_unavailable',
			'Prompt-cache probe returned no verdict.',
			array( 'status' => 500 )
		);
	}

	return $verdict;
}
