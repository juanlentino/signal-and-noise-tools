<?php
/**
 * Signal & Noise Tools — Abilities API: login hardening audit log.
 *
 * Three abilities wrapping the v3.8.3 audit-log module:
 *   - signal-noise/get-audit-log    (read-only — view=summary|counters|logins;
 *     the v7.7.0 consolidation of the three single-view reads, whose
 *     deprecated wrappers were removed in v8.0.0)
 *   - signal-noise/export-audit-log (read-only — CSV/JSON export)
 *   - signal-noise/run-audit-prune  (destructive — drops old data)
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

// v9.51.0 (lane SEC-C, R8): PII minimization on get-audit-log's view=logins
// rows — a per-call page-size cap (independent of the days/retention window)
// + default-redacted usernames unless include_pii:true is explicitly passed.
// Independent, tiny duplicate of inc/audit-log-export.php's own
// mask/cap/redact helpers (same file-independence rationale as
// inc/mcp/mcp-rw-audit.php's docblock elsewhere in this arc) — this is a
// separate ability with its own input_schema and its own callers.
const SNT_AUDIT_LOG_LOGINS_PAGE_CAP = 500; // Same magnitude as inc/audit-log.php's SN_AUDIT_LOGIN_SUCCESS_CAP.

/**
 * Mask a plaintext username: keep the first character, star the rest (fully
 * star a 1-char username). See inc/audit-log-export.php's
 * sn_audit_export_pii_mask_username() for the identical rationale — this file
 * owns its own copy rather than cross-depending on that one.
 *
 * @param string $username
 * @return string
 */
function snt_audit_log_pii_mask_username( $username ) {
	$username = (string) $username;
	$len      = strlen( $username );
	if ( 0 === $len ) {
		return '';
	}
	if ( 1 === $len ) {
		return '*';
	}
	return substr( $username, 0, 1 ) . str_repeat( '*', $len - 1 );
}

/**
 * Redact the 'user' field of every login row, unless $include_pii is true.
 *
 * @param array<int,array> $rows
 * @param bool              $include_pii
 * @return array<int,array>
 */
function snt_audit_log_redact_login_rows( array $rows, $include_pii ) {
	if ( (bool) $include_pii ) {
		return $rows;
	}
	return array_map( function( $row ) {
		if ( isset( $row['user'] ) ) {
			$row['user'] = snt_audit_log_pii_mask_username( $row['user'] );
		}
		return $row;
	}, $rows );
}

/**
 * Cap a rows array to at most $cap entries, keeping the first N — the source
 * impl (snt_audit_get_login_successes_impl()) already returns newest-first.
 *
 * @param array<int,array> $rows
 * @param int               $cap
 * @return array<int,array>
 */
function snt_audit_log_cap_rows( array $rows, $cap ) {
	$cap = (int) $cap;
	if ( count( $rows ) <= $cap ) {
		return $rows;
	}
	return array_slice( $rows, 0, $cap );
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
				'include_pii' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'v9.51.0: when false (default), view=logins usernames are masked (first char + stars) and capped to the most recent ' . SNT_AUDIT_LOG_LOGINS_PAGE_CAP . ' rows. Pass true to receive real plaintext usernames.',
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

	wp_register_ability( 'signal-noise/export-audit-log', array(
		'label'               => 'Export login-audit log',
		'description'         => 'Returns the full login-audit log (per-day counters + successful-login rows over the retention window) as a downloadable CSV or JSON payload. v9.51.0: usernames are masked by default (first char + stars) and login rows are capped independent of the retention window — pass include_pii:true for real plaintext. Read-only.',
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
				'include_pii' => array(
					'type'        => 'boolean',
					'default'     => false,
					// Deliberately a literal, not a cross-file constant reference:
					// SNT_AUDIT_EXPORT_LOGINS_PAGE_CAP lives in inc/audit-log-export.php,
					// which this file's own wp_abilities_api_init closure must not
					// hard-depend on having loaded first (see that constant's own
					// value — kept in sync by hand, same magnitude, on purpose).
					'description' => 'v9.51.0: when false (default), login-row usernames are masked and capped to the most recent 500 rows. Pass true to receive real plaintext usernames.',
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
 * Routes the requested view to the snt_audit_*_impl() helpers (shared with the
 * admin UI; the three deprecated single-view abilities that also wrapped them
 * were removed in v8.0.0) and returns the payload under the view's own key
 * (the other two keys are null). The unknown-view guard is
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
			// R8 (v9.51.0): login rows carry plaintext usernames — the reason this
			// ability is rw-door-only. Cap the page, then mask usernames unless the
			// caller explicitly asks for PII (default-drop, mirroring export-audit-log).
			$rows          = snt_audit_log_cap_rows( (array) snt_audit_get_login_successes_impl( $days ), SNT_AUDIT_LOG_LOGINS_PAGE_CAP );
			$out['logins'] = snt_audit_log_redact_login_rows( $rows, ! empty( $input['include_pii'] ) );
			return $out;
	}

	return new WP_Error( 'snt_audit_unknown_view', 'Unknown audit view.', array( 'status' => 422 ) );
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
