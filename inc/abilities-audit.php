<?php
/**
 * Signal & Noise Tools — Abilities API: login hardening audit log.
 *
 * Five abilities wrapping the v3.8.3 audit-log module:
 *   - signal-noise/get-audit-summary          (read-only hero summary)
 *   - signal-noise/get-audit-counters         (per-day event counters)
 *   - signal-noise/get-audit-login-successes  (recent successful logins)
 *   - signal-noise/export-audit-log           (read-only — CSV/JSON export)
 *   - signal-noise/run-audit-prune            (destructive — drops old data)
 *
 * The export-audit-log execute callback (snt_ability_export_audit_log) lives
 * in inc/audit-log-export.php alongside the pure builders + download handler.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 3.8.3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-audit-summary', array(
		'label'               => 'Get audit log hero summary',
		'description'         => 'Returns last-24h totals, 7-day trend vs. prior, unique attackers in 24h, and LLA lockout status. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_audit_summary',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'last_24h'             => array( 'type' => 'object' ),
				'last_7d_vs_prior'     => array( 'type' => 'object' ),
				'unique_attackers_24h' => array( 'type' => 'integer' ),
				'lla'                  => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-counters', array(
		'label'               => 'Get audit log counter timeline',
		'description'         => 'Returns per-day event counters for the last N days (default 30, max 90). Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_audit_counters',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-login-successes', array(
		'label'               => 'Get recent successful logins',
		'description'         => 'Returns recent per-event successful login records for the last N days (default 30, max 90). Each row: timestamp + username. No IP info. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_audit_login_successes',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/export-audit-log', array(
		'label'               => 'Export login-audit log',
		'description'         => 'Returns the full login-audit log (per-day counters + successful-login rows over the retention window) as a downloadable CSV or JSON payload. NOTE: the payload contains plaintext usernames. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_export_audit_log',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'format' => array(
					'type'    => 'string',
					'enum'    => array( 'json', 'csv' ),
					'default' => 'json',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'format'  => array( 'type' => 'string', 'enum' => array( 'json', 'csv' ) ),
				'content' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/run-audit-prune', array(
		'label'               => 'Run audit log prune now',
		'description'         => 'Manually drops counter buckets and login_success rows older than 90 days. Also polls LLA for new lockouts. Destructive of historical data — NOT exposed to AI.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_run_audit_prune',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'counter_buckets_dropped' => array( 'type' => 'integer' ),
				'login_rows_dropped'      => array( 'type' => 'integer' ),
				'lla_delta'               => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );
} );

/**
 * Execute callback for signal-noise/get-audit-summary.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_summary() {
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_get_summary_impl();
}

/**
 * Execute callback for signal-noise/get-audit-counters.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_counters( $input ) {
	if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_counters_impl( $days );
}

/**
 * Execute callback for signal-noise/get-audit-login-successes.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_login_successes( $input ) {
	if ( ! function_exists( 'snt_audit_get_login_successes_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_login_successes_impl( $days );
}

/**
 * Execute callback for signal-noise/run-audit-prune.
 *
 * @since 3.8.3
 */
function snt_ability_run_audit_prune() {
	if ( ! function_exists( 'snt_audit_prune_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_prune_impl();
}
