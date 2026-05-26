<?php
/**
 * Signal & Noise Tools — Abilities API: insights.
 *
 * Two abilities exposing the Content Opportunity Advisor (cross-system
 * synthesis of Plausible analytics + publish history + webhook delivery
 * patterns + cron freshness, producing 5 actionable recommendations).
 *
 *   - signal-noise/run-insights-scan  (triggers a fresh scan; cached 7d)
 *   - signal-noise/get-insights        (returns the cached result)
 *
 * Both wrap impl helpers in inc/insights.php. Extracted from
 * inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 3.6.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/run-insights-scan', array(
		'label'               => 'Run Insights Synthesis Scan',
		'description'         => 'Triggers a cross-system synthesis scan that combines Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable content recommendations. Cached for 7 days. Pass force=true to bypass the cache.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_run_insights_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, bypass the 7-day cache and run a fresh AI call.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'scanned_at'      => array( 'type' => 'integer' ),
				'elapsed_ms'      => array( 'type' => 'integer' ),
				'recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array( 'type' => 'string' ),
							'type'           => array( 'type' => 'string', 'enum' => array( 'write_about', 'update_post', 'cadence_change', 'topic_double_down', 'topic_pivot' ) ),
							'title'          => array( 'type' => 'string' ),
							'rationale'      => array( 'type' => 'string' ),
							'evidence_pills' => array( 'type' => 'array' ),
							'target'         => array( 'type' => array( 'object', 'null' ) ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-insights', array(
		'label'               => 'Get Last Insights Scan',
		'description'         => 'Returns the cached result of the last synthesis scan (recommendations array + metadata). Returns null when no scan has run yet.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_insights',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'scanned_at'      => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'      => array( 'type' => array( 'integer', 'null' ) ),
				'recommendations' => array( 'type' => 'array' ),
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
 * Ability execute callback: signal-noise/run-insights-scan.
 * Thin wrapper around snt_insights_run_scan().
 * @since 3.6.0
 */
function snt_ability_run_insights_scan( $input ) {
	if ( ! function_exists( 'snt_insights_run_scan' ) ) {
		return new WP_Error( 'snt_insights_unavailable', 'Insights module not loaded.', array( 'status' => 500 ) );
	}
	$force = is_array( $input ) && ! empty( $input['force'] );
	return snt_insights_run_scan( $force );
}

/**
 * Ability execute callback: signal-noise/get-insights.
 * Thin wrapper around snt_insights_last_scan().
 * @since 3.6.0
 */
function snt_ability_get_insights( $input ) {
	if ( ! function_exists( 'snt_insights_last_scan' ) ) {
		return new WP_Error( 'snt_insights_unavailable', 'Insights module not loaded.', array( 'status' => 500 ) );
	}
	return snt_insights_last_scan();
}
