<?php
/**
 * Signal & Noise Tools — Abilities API: Content-Health scan (read-only).
 *
 * Exposes the cached Content-Health scan (inc/health-checks.php) to AI /
 * automation callers as a compact summary — the agent-readable equivalent of the
 * "S&N Health" dashboard widget + the Health tab. Projected through the shared
 * summary accessors (inc/health-summary.php) so it never disagrees with those
 * surfaces on "what is off".
 *
 *   - signal-noise/get-health-scan  (cached scan summary, or null)
 *
 * Read-only: reads sn_health_last_scan() ONLY and NEVER triggers a scan — a scan
 * walks all posts + does remote probes (expensive); the human "Run scan" button
 * and the scheduled scan own that.
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

	wp_register_ability( 'signal-noise/get-health-scan', array(
		'label'               => 'Get Content-Health Scan Summary',
		'description'         => 'Returns a compact summary of the last cached Content-Health scan: the total finding count, the flagged checks ranked by count (each with its label, count, and fix hint), and the passed/total check tally. Returns null when no scan has run yet. Read-only — never triggers a scan (a scan walks all posts and probes links).',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_health_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'scanned_at'    => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'    => array( 'type' => array( 'integer', 'null' ) ),
				'finding_total' => array( 'type' => 'integer' ),
				'checks_total'  => array( 'type' => 'integer' ),
				'checks_passed' => array( 'type' => 'integer' ),
				'flagged'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'check'    => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'count'    => array( 'type' => 'integer' ),
							'fix_hint' => array( 'type' => 'string' ),
						),
					),
				),
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
 * Ability execute callback: signal-noise/get-health-scan.
 *
 * Reads the cached scan (never runs one) and projects it through the shared
 * summary accessors so the agent view matches the widget + Health tab exactly.
 *
 * @param array|null $input Unused.
 * @return array|null|WP_Error Summary object, null when no scan, or WP_Error.
 */
function snt_ability_get_health_scan( $input ) {
	if ( ! function_exists( 'sn_health_last_scan' ) || ! function_exists( 'sn_health_finding_total' ) ) {
		return new WP_Error( 'snt_health_unavailable', 'Health module not loaded.', array( 'status' => 500 ) );
	}
	$scan = sn_health_last_scan();
	if ( ! is_array( $scan ) ) {
		return null;
	}
	$checks  = is_array( $scan['checks'] ?? null ) ? $scan['checks'] : array();
	$flagged = array();
	foreach ( sn_health_flagged_checks( $scan ) as $key => $check ) {
		$flagged[] = array(
			'check'    => (string) $key,
			'label'    => (string) ( $check['label'] ?? $key ),
			'count'    => (int) ( $check['count'] ?? 0 ),
			'fix_hint' => (string) ( $check['fix_hint'] ?? '' ),
		);
	}
	return array(
		'scanned_at'    => isset( $scan['scanned_at'] ) ? (int) $scan['scanned_at'] : null,
		'elapsed_ms'    => isset( $scan['elapsed_ms'] ) ? (int) $scan['elapsed_ms'] : null,
		'finding_total' => sn_health_finding_total( $scan ),
		'checks_total'  => count( $checks ),
		'checks_passed' => count( $checks ) - count( $flagged ),
		'flagged'       => $flagged,
	);
}
