<?php
/**
 * Signal & Noise Tools — Abilities API: weekly digest (narration).
 *
 * Exposes the EXISTING weekly-digest narration (inc/insights-narration.php,
 * v6.30.0) to AI / automation callers, mirroring the two Insights abilities:
 *
 *   - signal-noise/run-narration  (force a fresh weekly digest; cached 7d)
 *   - signal-noise/get-narration   (return the cached digest, or null)
 *
 * Both are thin wrappers over the narration impl helpers — no logic is
 * duplicated. Narration already powers the Insights-tab digest card + the opt-in
 * weekly cron; these abilities add only the agent read / generate path.
 *
 * @package SignalNoiseTools
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/run-narration', array(
		'label'               => 'Generate Weekly Analytics Digest',
		'description'         => 'QUEUES a fresh weekly analytics narration — a short prose "what happened this week" digest (headline + 2-3 paragraphs + terse numeric highlights) over the last 7 days of first-party analytics: totals, week-over-week deltas, engagement, top pages/sources/events, and (when present) non-human edge traffic. Generation runs in the BACKGROUND (a large AI call that would exceed a request timeout inline), so this returns immediately with a queued status — read the finished digest with get-narration a moment later. Cookieless: only aggregate counts, never sessions or per-visitor journeys. Cached for 7 days; if a digest is already cached this reports so instead of regenerating — pass force=true to regenerate anyway.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_run_narration',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, bypass the 7-day cache and regenerate the digest via a fresh AI call.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'scheduled' => array( 'type' => 'boolean', 'description' => 'True if a background generation was newly queued by this call.' ),
				'cached'    => array( 'type' => 'boolean', 'description' => 'True if a digest is already cached — read it with get-narration.' ),
				'message'   => array( 'type' => 'string', 'description' => 'Human-readable status pointing at get-narration.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// force=true regenerates via a live AI call + rewrites the cache, so a
				// retry can return different prose → not idempotent.
				'idempotent'      => false,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-narration', array(
		'label'               => 'Get Weekly Analytics Digest',
		'description'         => 'Returns the cached weekly analytics digest (headline + paragraphs + highlights + metadata), or null when none has been generated yet. Read-only — never triggers an AI call.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_narration',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'generated_at' => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'   => array( 'type' => array( 'integer', 'null' ) ),
				'headline'     => array( 'type' => array( 'string', 'null' ) ),
				'paragraphs'   => array( 'type' => 'array' ),
				'highlights'   => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/run-narration.
 * Thin wrapper around snt_narration_run().
 *
 * @param array|null $input { force?: bool }
 * @return array|WP_Error The generated digest, or WP_Error.
 */
function snt_ability_run_narration( $input ) {
	if ( ! function_exists( 'snt_narration_schedule' ) ) {
		return new WP_Error( 'snt_narration_unavailable', 'Narration module not loaded.', array( 'status' => 500 ) );
	}
	$force  = is_array( $input ) && ! empty( $input['force'] );
	$cached = function_exists( 'snt_narration_last' ) ? snt_narration_last() : null;

	// v9.51.2: this ability is a TRIGGER, not the generator. The digest is the
	// plugin's largest AI call and must never run in this (MCP/REST) request —
	// it would exceed the provider HTTP timeout. Queue a background generation
	// and return immediately; get-narration reads the result once it lands. Not
	// forced + already cached ⇒ nothing to do, just point the caller at the read.
	if ( ! $force && is_array( $cached ) ) {
		return array(
			'scheduled' => false,
			'cached'    => true,
			'message'   => 'A weekly digest is already cached. Read it with get-narration, or pass force=true to regenerate.',
		);
	}

	$scheduled = snt_narration_schedule( $force );
	return array(
		'scheduled' => (bool) $scheduled,
		'cached'    => is_array( $cached ),
		'message'   => $scheduled
			? 'Digest generation queued (runs in the background). Call get-narration in a moment to read the result.'
			: 'Digest generation was already queued. Call get-narration in a moment to read the result.',
	);
}

/**
 * Ability execute callback: signal-noise/get-narration.
 * Thin wrapper around snt_narration_last().
 *
 * @param array|null $input Unused.
 * @return array|null|WP_Error The cached digest, null, or WP_Error.
 */
function snt_ability_get_narration( $input ) {
	if ( ! function_exists( 'snt_narration_last' ) ) {
		return new WP_Error( 'snt_narration_unavailable', 'Narration module not loaded.', array( 'status' => 500 ) );
	}
	return snt_narration_last();
}
