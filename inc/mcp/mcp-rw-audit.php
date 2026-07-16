<?php
/**
 * Signal & Noise — MCP rw-door audit log + owner notification (v9.51.0, lane
 * SEC-B, ranks R4+R5 of the hardening research). Two independent concerns
 * that share one file because they share one call site and one data row:
 *
 *   R4. A write-path audit log: every tools/call reaching the rw door
 *       (SN_MCP_DOOR_RW), win or lose, is appended to a schema-versioned
 *       capped option (sn_mcp_rw_audit_log) — the schema-versioned
 *       capped-option idiom mirrors inc/audit-log.php's SN_AUDIT_OPTION
 *       exactly (lazy-init blob, rolling cap, age-based prune).
 *   R5. An opt-in immediate owner notification (sn_mcp_rw_notify, default
 *       OFF) — mirrors inc/security-digest.php's opt-in + wp_mail +
 *       durable-last-sent pattern, but sends per-call instead of weekly
 *       (single-owner, low-volume door; waiting a week defeats the point).
 *       A coalescing window keeps a runaway agent loop from mailbombing.
 *
 * The read door (/mcp) never reaches this file: inc/mcp/mcp-tools.php's
 * sn_mcp_call_tool() only calls sn_mcp_rw_audit_record() when
 * SN_MCP_DOOR_RW === $door (three call sites, all past the ability's own
 * permission check — see that file's docblock). A test proves the read door
 * writes NOTHING to sn_mcp_rw_audit_log, ever.
 *
 * Redaction is DEFAULT-DROP: sn_mcp_rw_audit_safe_args() keeps ONLY an
 * explicit allowlist of known-safe scalar keys gathered by reading every one
 * of the 34 rw-door abilities' real input_schema (inc/abilities-*.php) —
 * post_id, view, format, hook, etc. Anything else — an ability's actual
 * content payload (ai-alt-apply's `alt_text`, draft-release-notes'
 * `changelog_delta`, block-migrations/pattern-adoption's
 * `replacement_markup`), any future secret-shaped argument, any array/object
 * value even under a safe-looking key name — is dropped, never logged. This
 * file has NO per-slug special-casing in v1: the $slug parameter is threaded
 * through for a future narrower per-slug override, but the allowlist today is
 * one global list applied identically regardless of which of the 34 tools
 * was called.
 *
 * Independent of inc/mcp/mcp-rw-guard.php (lane SEC-A) on purpose: this file
 * duplicates the tiny function_exists('rest_get_authenticated_app_password')
 * guard rather than depending on that file's sn_mcp_rw_authenticated_app_password_uuid(),
 * so tests/mcp-rw-audit.php can exercise this module without pulling in
 * SEC-A's guard file. Both copies implement the identical WP 5.7+ guard.
 *
 * @package SignalNoiseTools
 * @since 9.51.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_RW_AUDIT_OPTION          = 'sn_mcp_rw_audit_log';
const SN_MCP_RW_AUDIT_CAP             = 1000; // Rolling row cap — mirrors cron-history's per-hook magnitude (SNT_CRON_HISTORY_PER_HOOK_CAP).
const SN_MCP_RW_AUDIT_RETENTION_DAYS  = 30;    // Age cutoff — mirrors cron-history's SNT_CRON_HISTORY_RETENTION_DAYS; this is a forensics window, not a permanent ledger.

const SN_MCP_RW_NOTIFY_OPTION            = 'sn_mcp_rw_notify';              // Opt-in, default OFF.
const SN_MCP_RW_NOTIFY_LAST_SENT_OPTION  = 'sn_mcp_rw_notify_last_sent';
const SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION = 'sn_mcp_rw_notify_last_error';
const SN_MCP_RW_NOTIFY_OVERFLOW_OPTION   = 'sn_mcp_rw_notify_overflow_count';
const SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS = 60; // >1 send per 60s coalesces into the next mail's overflow count.

/**
 * The redaction allowlist (R4: "default-drop, not default-keep"). Every key
 * here was found by reading the REAL input_schema of one of the 34 rw-door
 * abilities (inc/abilities-content.php, inc/abilities-ai-post-editor.php,
 * inc/abilities-block-migrations.php, inc/abilities-ai-pattern-adoption.php,
 * inc/abilities-audit.php, inc/abilities-cron.php, inc/abilities-dismiss.php,
 * inc/abilities-system.php, inc/abilities-prepop-dismiss.php) as of
 * 2026-07-15/16. Deliberately EXCLUDED even though they are real argument
 * names on rw-door abilities: `alt_text` (ai-alt-apply — the actual AI
 * output text), `replacement_markup` (block-migrations-apply /
 * pattern-adoption-apply — full block markup), `changelog_delta`
 * (draft-release-notes — raw changelog prose), `args` (unschedule-cron-event
 * — an arbitrary array, dropped by TYPE below regardless of key name anyway).
 * `include_pii` is pre-added for the SEC-C rank-8 arg landing on
 * get-audit-log/export-audit-log — harmless to allowlist a boolean flag
 * ahead of that lane merging.
 *
 * @return string[]
 */
function sn_mcp_rw_audit_safe_arg_keys() {
	return array(
		'view',
		'days',
		'format',
		'surface',
		'post_id',
		'attachment_id',
		'block_fingerprint',
		'candidate_type',
		'pattern_type',
		'migration_type',
		'hook',
		'args_signature',
		'limit',
		'sn_only',
		'force',
		'force_refresh',
		'concise',
		'include_template_overrides',
		'include_pii',
	);
}

/**
 * Redact a tools/call args array down to only the known-safe scalar keys.
 * DEFAULT-DROP: a key not on the allowlist is gone, full stop — there is no
 * path by which an unlisted key (or a listed key holding a non-scalar value)
 * survives into the audit log or the notification email. $slug is accepted
 * but unused in v1 (see file docblock) — reserved for a future per-slug
 * narrower override without changing every call site's signature.
 *
 * @param string $slug Ability slug (unused in v1; reserved).
 * @param mixed  $args The raw tools/call arguments.
 * @return array<string,mixed>
 */
function sn_mcp_rw_audit_safe_args( $slug, $args ) {
	unset( $slug ); // Reserved for a future per-slug override; not used in v1.
	if ( ! is_array( $args ) ) {
		return array();
	}
	$safe = array();
	foreach ( sn_mcp_rw_audit_safe_arg_keys() as $key ) {
		if ( ! array_key_exists( $key, $args ) ) {
			continue;
		}
		$value = $args[ $key ];
		// A safe KEY holding an unsafe SHAPE (array/object) is still dropped —
		// the allowlist gates key AND scalar-ness, not key alone.
		if ( is_scalar( $value ) || null === $value ) {
			$safe[ $key ] = $value;
		}
	}
	return $safe;
}

/**
 * Hash an IP for the audit row. Reuses inc/audit-log.php's
 * snt_audit_hash_ip() (same salt, same 16-char fragment, same collision
 * bound) when that module is loaded; falls back to an equivalent
 * wp_salt('auth')-salted sha256 fragment when it isn't (this file must not
 * hard-depend on inc/audit-log.php loading first).
 *
 * @param string $ip
 * @return string
 */
function sn_mcp_rw_audit_hash_ip( $ip ) {
	if ( function_exists( 'snt_audit_hash_ip' ) ) {
		return snt_audit_hash_ip( $ip );
	}
	$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
	return substr( hash( 'sha256', (string) $ip . $salt ), 0, 16 );
}

/**
 * The current request's remote IP, unslashed. '' when unavailable (CLI, test
 * harness).
 *
 * @return string
 */
function sn_mcp_rw_audit_current_ip() {
	// Same isset-guard + wp_unslash() shape as inc/audit-log.php's
	// snt_audit_capture_login_failed_cb() — the raw value is NEVER stored
	// anywhere; its only consumer is sn_mcp_rw_audit_hash_ip() immediately
	// after. Same rationale as audit-log.php's phpcs.xml.dist scoped
	// exclude-pattern for this file (WordPress.Security.ValidatedSanitizedInput.InputNotSanitized).
	return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
}

/**
 * The application-password UUID that authenticated the CURRENT request, or ''
 * if none (cookie auth, or WP < 5.7). Deliberately duplicated from
 * inc/mcp/mcp-rw-guard.php's sn_mcp_rw_authenticated_app_password_uuid() —
 * see the file docblock for why this file doesn't depend on that one.
 *
 * @return string
 */
function sn_mcp_rw_audit_authenticated_app_password_uuid() {
	if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
		return '';
	}
	$uuid = rest_get_authenticated_app_password();
	return is_string( $uuid ) ? $uuid : '';
}

/**
 * Resolve an error_code for the audit row from whatever the call site had in
 * hand at its tail: a WP_Error (execute() failure — use its own code), the
 * boolean `false` (a permission_callback denying without a WP_Error — the
 * 'denied' outcome's most common shape), or null (the 'ok' outcome, no error
 * at all).
 *
 * @param mixed $error_source
 * @return string|null
 */
function sn_mcp_rw_audit_error_code_from( $error_source ) {
	if ( is_string( $error_source ) && '' !== $error_source ) {
		return $error_source;
	}
	if ( is_object( $error_source ) && method_exists( $error_source, 'get_error_code' ) ) {
		return (string) $error_source->get_error_code();
	}
	if ( false === $error_source ) {
		return 'permission_denied';
	}
	return null;
}

/**
 * PURE row builder: every piece of state (redacted args aside, which is
 * itself pure) is a parameter — no get_option()/time()/get_current_user_id()
 * call lives inside. Mirrors lane SEC-A's "pure predicate + live wrapper"
 * split so this is directly unit-testable without a WP bootstrap.
 *
 * @param string      $slug
 * @param array       $args         Raw (un-redacted) args — redaction happens here.
 * @param string      $outcome      'ok'|'error'|'denied'.
 * @param mixed       $error_source WP_Error|false|null — see sn_mcp_rw_audit_error_code_from().
 * @param int         $user_id
 * @param string      $app_pw_uuid
 * @param string      $ip_hash
 * @param int         $ts
 * @return array<string,mixed>
 */
function sn_mcp_rw_audit_build_row( $slug, $args, $outcome, $error_source, $user_id, $app_pw_uuid, $ip_hash, $ts ) {
	$row = array(
		'ts'            => (int) $ts,
		'slug'          => (string) $slug,
		'args_redacted' => sn_mcp_rw_audit_safe_args( $slug, $args ),
		'user_id'       => (int) $user_id,
		'app_pw_uuid'   => (string) $app_pw_uuid,
		'outcome'       => (string) $outcome,
		'ip_hash'       => (string) $ip_hash,
	);
	$error_code = sn_mcp_rw_audit_error_code_from( $error_source );
	if ( null !== $error_code ) {
		$row['error_code'] = $error_code;
	}
	return $row;
}

/**
 * Get the audit log blob, lazy-initializing if missing. Same shape idiom as
 * inc/audit-log.php's snt_audit_get_blob().
 *
 * @return array{schema_version:int,created_at:int,rows:array<int,array>}
 */
function sn_mcp_rw_audit_get_blob() {
	$blob = get_option( SN_MCP_RW_AUDIT_OPTION, null );
	if ( ! is_array( $blob ) || ! isset( $blob['schema_version'] ) || ! isset( $blob['rows'] ) || ! is_array( $blob['rows'] ) ) {
		$blob = array(
			'schema_version' => 1,
			'created_at'     => time(),
			'rows'           => array(),
		);
	}
	return $blob;
}

/**
 * Prune rows older than SN_MCP_RW_AUDIT_RETENTION_DAYS, then cap to the most
 * recent SN_MCP_RW_AUDIT_CAP rows. Age first, then count — matches
 * inc/cron-history.php's two-stage retention (age OR count, whichever is
 * shorter).
 *
 * @param array<int,array> $rows
 * @return array<int,array>
 */
function sn_mcp_rw_audit_prune_rows( $rows ) {
	$cutoff = time() - SN_MCP_RW_AUDIT_RETENTION_DAYS * DAY_IN_SECONDS;
	$rows   = array_values( array_filter( $rows, function( $row ) use ( $cutoff ) {
		return isset( $row['ts'] ) && (int) $row['ts'] >= $cutoff;
	} ) );
	if ( count( $rows ) > SN_MCP_RW_AUDIT_CAP ) {
		$rows = array_slice( $rows, -SN_MCP_RW_AUDIT_CAP );
	}
	return $rows;
}

/**
 * LIVE: record one rw-door tools/call outcome. This is the ONE function
 * inc/mcp/mcp-tools.php's sn_mcp_call_tool() calls, at three tail call sites,
 * ONLY when $door === SN_MCP_DOOR_RW. Gathers real WP state (current user,
 * authenticated app-password UUID, remote IP, current time), builds the pure
 * row, appends + prunes + persists, then fires the opt-in notification.
 *
 * Autoload is explicitly FALSE (unlike inc/audit-log.php's SN_AUDIT_OPTION,
 * which autoloads true because the security dashboard reads it on every admin
 * load): this option is read only from the future rw-door leaf / forensics,
 * never on a normal page load, so there is no reason to pay its autoload
 * weight as it grows toward SN_MCP_RW_AUDIT_CAP rows.
 *
 * The whole body is wrapped in a try/catch: this is an OBSERVABILITY
 * side-channel, not a security gate (lane SEC-A's permission_callback is the
 * gate, and it runs entirely before sn_mcp_call_tool() is ever reached) — a
 * future third-party filter on update_option()/get_option() throwing, or any
 * other unexpected failure in this path, must never turn a successful (or
 * already-decided) tool call into a fatal error for the whole request. This
 * extends R5's "never blocks/errors the tool call" principle (already
 * required of the notification path) to the audit WRITE itself.
 *
 * @param string $slug
 * @param array  $args
 * @param string $outcome      'ok'|'error'|'denied'.
 * @param mixed  $error_source WP_Error|false|null.
 * @return array<string,mixed>|null The row that was recorded, or null if
 *                                  recording itself failed (never thrown).
 */
function sn_mcp_rw_audit_record( $slug, $args, $outcome, $error_source = null ) {
	try {
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$app_pw_uuid = sn_mcp_rw_audit_authenticated_app_password_uuid();
		$ip_hash     = sn_mcp_rw_audit_hash_ip( sn_mcp_rw_audit_current_ip() );

		$row = sn_mcp_rw_audit_build_row( $slug, is_array( $args ) ? $args : array(), $outcome, $error_source, $user_id, $app_pw_uuid, $ip_hash, time() );

		$blob           = sn_mcp_rw_audit_get_blob();
		$blob['rows'][] = $row;
		$blob['rows']   = sn_mcp_rw_audit_prune_rows( $blob['rows'] );
		update_option( SN_MCP_RW_AUDIT_OPTION, $blob, false );

		if ( sn_mcp_rw_notify_enabled() ) {
			sn_mcp_rw_notify_maybe_send( $row );
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	return $row;
}

/* ════════════════════════════════════════════════════════════════════════
 * R5 — owner notification. Opt-in (default OFF), immediate (not weekly —
 * this is a low-volume single-owner door), coalesced against mailbombing.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Is the rw-door notification opt-in enabled? Default OFF — wp_mail can be
 * noisy, and an owner who wants it turns it on deliberately (SEC-C's leaf).
 *
 * @return bool
 */
function sn_mcp_rw_notify_enabled() {
	return (bool) get_option( SN_MCP_RW_NOTIFY_OPTION, false );
}

/**
 * PURE coalesce predicate: given when the last notification actually went
 * out and the current time, should THIS call be swallowed into the next
 * mail's overflow count instead of sending its own? True = coalesce (don't
 * send now).
 *
 * @param int $last_sent_ts
 * @param int $now
 * @return bool
 */
function sn_mcp_rw_notify_coalesce_decision( $last_sent_ts, $now ) {
	return ( (int) $now - (int) $last_sent_ts ) < SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS;
}

/**
 * A minimal email-shape check, guarded so this file has no hard is_email()
 * dependency for its own test harness.
 *
 * @param string $email
 * @return bool
 */
function sn_mcp_rw_notify_valid_email( $email ) {
	if ( function_exists( 'is_email' ) ) {
		return (bool) is_email( $email );
	}
	return false !== strpos( (string) $email, '@' );
}

/**
 * Compose the notification subject: names the tool + outcome, per R5.
 *
 * @param array $row
 * @return string
 */
function sn_mcp_rw_notify_subject( $row ) {
	$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
	return sprintf( '[%s] MCP write door: %s (%s)', $site, (string) ( $row['slug'] ?? '' ), (string) ( $row['outcome'] ?? '' ) );
}

/**
 * Compose the notification body: the redacted audit row + the "why you got
 * this" line (R5), plus an overflow line when calls were coalesced since the
 * last mail actually went out.
 *
 * @param array $row
 * @param int   $overflow_count Calls coalesced (not individually mailed) since the last send.
 * @return string
 */
function sn_mcp_rw_notify_body( $row, $overflow_count ) {
	$lines   = array();
	$lines[] = 'MCP write-door activity notification';
	$lines[] = str_repeat( '=', 40 );
	$lines[] = '';
	$lines[] = 'Tool: ' . (string) ( $row['slug'] ?? '' );
	$lines[] = 'Outcome: ' . (string) ( $row['outcome'] ?? '' );
	if ( ! empty( $row['error_code'] ) ) {
		$lines[] = 'Error code: ' . (string) $row['error_code'];
	}
	$lines[] = 'User ID: ' . (string) ( $row['user_id'] ?? '' );
	$lines[] = 'Application Password UUID: ' . ( '' !== ( $row['app_pw_uuid'] ?? '' ) ? (string) $row['app_pw_uuid'] : '(none)' );
	$lines[] = 'Redacted args: ' . (string) wp_json_encode( $row['args_redacted'] ?? array() );
	$lines[] = '';
	if ( $overflow_count > 0 ) {
		$lines[] = sprintf( 'Plus %d more write-door call(s) since the last email (coalesced within a %ds window).', (int) $overflow_count, SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS );
		$lines[] = '';
	}
	$lines[] = "You're getting this because the MCP write-door notification setting is on. Toggle it off in Tools -> MCP.";
	return implode( "\n", $lines ) . "\n";
}

/**
 * LIVE: maybe send the immediate rw-activity notification for one recorded
 * row. Never throws and never surfaces a failure to the tool-call client —
 * the caller (sn_mcp_rw_audit_record) doesn't even inspect this function's
 * return value. A failed wp_mail (missing/invalid admin_email, wp_mail()
 * returning false, or an exception from a broken mailer plugin) is recorded
 * to the durable last-error option and nothing more.
 *
 * Coalescing: if the last successful-or-attempted send was less than
 * SN_MCP_RW_NOTIFY_COALESCE_WINDOW_SECONDS ago, this call is swallowed —
 * its data doesn't disappear, it's folded into the next mail's overflow
 * count (R5: "stash overflow count into the next mail"). The window advances
 * on EVERY attempt (success or failure), not just successes: a persistently
 * broken mailer must not be hammered on every single rw call.
 *
 * @param array $row A row from sn_mcp_rw_audit_build_row()/sn_mcp_rw_audit_record().
 * @return bool True only when a mail was actually sent this call.
 */
function sn_mcp_rw_notify_maybe_send( $row ) {
	if ( ! sn_mcp_rw_notify_enabled() ) {
		return false;
	}

	$now       = time();
	$last_sent = (int) get_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, 0 );

	if ( sn_mcp_rw_notify_coalesce_decision( $last_sent, $now ) ) {
		$overflow = (int) get_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION, 0 ) + 1;
		update_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION, $overflow, false );
		return false;
	}

	$overflow_before_this = (int) get_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION, 0 );
	$admin_email          = (string) get_option( 'admin_email', '' );

	if ( '' === $admin_email || ! sn_mcp_rw_notify_valid_email( $admin_email ) ) {
		update_option( SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION, array( 'message' => 'admin_email missing or invalid', 'at' => $now ), false );
		return false;
	}

	$sent = false;
	try {
		$sent = (bool) wp_mail( $admin_email, sn_mcp_rw_notify_subject( $row ), sn_mcp_rw_notify_body( $row, $overflow_before_this ) );
	} catch ( \Throwable $e ) {
		$sent = false;
	}

	// The window advances regardless of outcome (see docblock: a broken
	// mailer must not be re-attempted on every single subsequent rw call).
	update_option( SN_MCP_RW_NOTIFY_LAST_SENT_OPTION, $now, false );

	if ( $sent ) {
		update_option( SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION, false, false );
		update_option( SN_MCP_RW_NOTIFY_OVERFLOW_OPTION, 0, false );
	} else {
		update_option( SN_MCP_RW_NOTIFY_LAST_ERROR_OPTION, array( 'message' => 'wp_mail returned false or threw', 'at' => $now ), false );
	}

	return $sent;
}
