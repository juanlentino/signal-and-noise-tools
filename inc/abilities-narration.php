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
		'description'         => 'Generates a fresh weekly analytics narration — a short prose "what happened this week" digest (headline + 2-3 paragraphs + terse numeric highlights) over the last 7 days of first-party analytics: totals, week-over-week deltas, engagement, top pages/sources/events, and (when present) non-human edge traffic. Cookieless: only aggregate counts, never sessions or per-visitor journeys. Cached for 7 days; pass force=true to bypass the cache and regenerate.',
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
				'generated_at' => array( 'type' => 'integer' ),
				'elapsed_ms'   => array( 'type' => 'integer' ),
				'headline'     => array( 'type' => 'string' ),
				'paragraphs'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'highlights'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
	if ( ! function_exists( 'snt_narration_run' ) ) {
		return new WP_Error( 'snt_narration_unavailable', 'Narration module not loaded.', array( 'status' => 500 ) );
	}
	$force = is_array( $input ) && ! empty( $input['force'] );
	return snt_narration_run( $force );
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
