<?php
/**
 * Signal & Noise Tools — Abilities API: login hardening audit log.
 *
 * Six abilities wrapping the v3.8.3 audit-log module:
 *   - signal-noise/get-audit-log              (read-only — view=summary|counters|logins;
 *     the v7.7.0 consolidation of the three single-view reads below)
 *   - signal-noise/get-audit-summary          (DEPRECATED 7.7.0 → get-audit-log view=summary)
 *   - signal-noise/get-audit-counters         (DEPRECATED 7.7.0 → get-audit-log view=counters)
 *   - signal-noise/get-audit-login-successes  (DEPRECATED 7.7.0 → get-audit-log view=logins)
 *   - signal-noise/export-audit-log           (read-only — CSV/JSON export)
 *   - signal-noise/run-audit-prune            (destructive — drops old data)
 *
 * The three deprecated reads keep full behavior through v7.x (removal v8.0.0)
 * and emit snt_ability_deprecated_notice() at their execute wrappers only —
 * never in the shared snt_audit_*_impl() helpers get-audit-log also calls.
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

	// v7.7.0: the consolidated audit read. One ability, three views — the
	// output echoes `view` and fills exactly ONE of summary/counters/logins
	// (the other two are null) so agent callers never guess which key holds
	// the payload.
	wp_register_ability( 'signal-noise/get-audit-log', array(
		'label'               => 'Get login-audit log (summary, counters, or logins)',
		'description'         => 'Returns one view of the login-audit log. view=summary: last-24h totals + 7-day trend + unique attackers + LLA lockout status. view=counters: per-day event counters for the last N days. view=logins: recent successful login rows (timestamp + username, no IP). Exactly one of the summary/counters/logins output keys is non-null — the one matching the requested view. Read-only. Replaces get-audit-summary / get-audit-counters / get-audit-login-successes.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_audit_log',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'view' ),
			'properties'           => array(
				'view' => array(
					'type'        => 'string',
					'enum'        => array( 'summary', 'counters', 'logins' ),
					'description' => 'Which audit view to return.',
				),
				'days' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 90,
					'default'     => 30,
					'description' => 'Window for counters/logins views (ignored by summary).',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'view'     => array( 'type' => 'string', 'enum' => array( 'summary', 'counters', 'logins' ) ),
				'summary'  => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'Non-null only for view=summary: { last_24h, last_7d_vs_prior, unique_attackers_24h, lla }.',
				),
				'counters' => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Non-null only for view=counters: per-day counter rows.',
				),
				'logins'   => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Non-null only for view=logins: successful-login rows.',
				),
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

	wp_register_ability( 'signal-noise/get-audit-summary', array(
		'label'               => 'Get audit log hero summary',
		'description'         => 'DEPRECATED since 7.7.0 — use signal-noise/get-audit-log with view="summary" instead (same payload under its summary key). Returns last-24h totals, 7-day trend vs. prior, unique attackers in 24h, and LLA lockout status. Read-only.',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'signal-noise/get-audit-log with view="summary"',
			),
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-counters', array(
		'label'               => 'Get audit log counter timeline',
		'description'         => 'DEPRECATED since 7.7.0 — use signal-noise/get-audit-log with view="counters" instead (same payload under its counters key). Returns per-day event counters for the last N days (default 30, max 90). Read-only.',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'signal-noise/get-audit-log with view="counters"',
			),
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-login-successes', array(
		'label'               => 'Get recent successful logins',
		'description'         => 'DEPRECATED since 7.7.0 — use signal-noise/get-audit-log with view="logins" instead (same payload under its logins key). Returns recent per-event successful login records for the last N days (default 30, max 90). Each row: timestamp + username. No IP info. Read-only.',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'signal-noise/get-audit-log with view="logins"',
			),
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
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
 * Execute callback for signal-noise/get-audit-log (v7.7.0 consolidated read).
 *
 * Routes the requested view to the same snt_audit_*_impl() helpers the three
 * deprecated single-view abilities wrap, and returns the payload under the
 * view's own key (the other two keys are null). The unknown-view guard is
 * defense in depth — the input_schema enum already blocks it at the run
 * controller, but direct callers (tests, internal dispatch) bypass schemas.
 *
 * @since 7.7.0
 * @param array $input { view: 'summary'|'counters'|'logins', days?: int }.
 * @return array{view:string,summary:?array,counters:?array,logins:?array}|WP_Error
 */
function snt_ability_get_audit_log( $input ) {
	$view = isset( $input['view'] ) ? (string) $input['view'] : '';
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;

	$out = array(
		'view'     => $view,
		'summary'  => null,
		'counters' => null,
		'logins'   => null,
	);

	switch ( $view ) {
		case 'summary':
			if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
				return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
			}
			$out['summary'] = snt_audit_get_summary_impl();
			return $out;

		case 'counters':
			if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
				return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
			}
			$out['counters'] = snt_audit_get_counters_impl( $days );
			return $out;

		case 'logins':
			if ( ! function_exists( 'snt_audit_get_login_successes_impl' ) ) {
				return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
			}
			$out['logins'] = snt_audit_get_login_successes_impl( $days );
			return $out;
	}

	return new WP_Error( 'snt_audit_unknown_view', 'Unknown audit view.', array( 'status' => 422 ) );
}

/**
 * Execute callback for signal-noise/get-audit-summary.
 *
 * @since 3.8.3
 * @deprecated 7.7.0 Use signal-noise/get-audit-log with view="summary".
 */
function snt_ability_get_audit_summary() {
	snt_ability_deprecated_notice( 'signal-noise/get-audit-summary', 'signal-noise/get-audit-log with view="summary"' );
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_get_summary_impl();
}

/**
 * Execute callback for signal-noise/get-audit-counters.
 *
 * @since 3.8.3
 * @deprecated 7.7.0 Use signal-noise/get-audit-log with view="counters".
 */
function snt_ability_get_audit_counters( $input ) {
	snt_ability_deprecated_notice( 'signal-noise/get-audit-counters', 'signal-noise/get-audit-log with view="counters"' );
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
 * @deprecated 7.7.0 Use signal-noise/get-audit-log with view="logins".
 */
function snt_ability_get_audit_login_successes( $input ) {
	snt_ability_deprecated_notice( 'signal-noise/get-audit-login-successes', 'signal-noise/get-audit-log with view="logins"' );
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
