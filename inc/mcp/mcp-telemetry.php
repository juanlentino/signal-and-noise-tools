<?php
/**
 * Signal & Noise — MCP Layer B telemetry: one row per tools/call, both doors,
 * every outcome (success AND every flavor of refusal/error). This is the
 * "server middleware" layer from sn-telemetry-spec.md — a SEPARATE, BROADER
 * layer than inc/mcp/mcp-rw-audit.php's rw-only forensics log: that file logs
 * write-door outcomes with redacted arg VALUES for 30 days; this file logs
 * BOTH doors' call shape (never a value) for 90 days, feeding the six
 * telemetry metrics (zero-call set, misroute rate, schema-error rate,
 * candidate acceptance, gate refusals). Neither file depends on the other.
 *
 * Interception point: inc/mcp/mcp-tools.php's sn_mcp_call_tool() calls
 * sn_mcp_telemetry_record() at every one of its return points (rate-limit
 * denial, invalid/unknown tool, permission denied, execute() WP_Error,
 * success) — NOT gated by door, unlike the rw-audit call sites. This differs
 * from the recon note's original guess (mcp-server.php's tools/call case):
 * by the time a call result reaches mcp-server.php, an ability's execute()
 * failure has already been flattened to sn_mcp_error_result( $out->
 * get_error_message() ) — the WP_Error's MESSAGE only, its CODE is gone. This
 * file's schema_error-vs-server_error split (see
 * sn_mcp_telemetry_classify_wp_error()) needs the real code, so the call
 * sites live inside sn_mcp_call_tool() itself, at the same tail positions
 * inc/mcp/mcp-rw-audit.php already uses — see FINDINGS.md for the deviation
 * note.
 *
 * FAIL-OPEN, always: sn_mcp_telemetry_record()'s entire body is inside a
 * try/catch, and a failed/missing table is simply a $wpdb->insert() that
 * returns false (wpdb->insert() on a missing table or a bad column never
 * throws — it sets $wpdb->last_error and returns false; project memory: a
 * FAILED wpdb query returns false/[], not null, and a stub must model that
 * shape, not just the success shape). Either way the tool's own response is
 * never touched — this file has no return value any caller inspects.
 *
 * Kill switch: `sn_mcp_telemetry_enabled` filter, default true.
 *
 * Table creation is NOT on the hot path: sn_mcp_telemetry_maybe_install()
 * hooks 'init' with the same lazy version-option-compare guard
 * inc/analytics-buckets.php uses for its own dormant table — one
 * get_option() compare per request, dbDelta only runs on a version delta.
 *
 * Retention: 90 days, opportunistic (no cron, per the standing guardrail) —
 * roughly 1-in-50 inserts also runs a capped DELETE of rows older than the
 * window, using wp_rand() (never mt_rand() for the gate itself; a fallback
 * exists only for a non-WP unit-test context that never loads wp_rand()).
 *
 * @package SignalNoiseTools
 * @since 10.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_TELEMETRY_TABLE          = 'sn_tool_call';
// Bump on every schema change so dbDelta runs for existing installs. Without
// the delta, inserts silently drop columns that are present only in code.
const SN_MCP_TELEMETRY_DB_VERSION     = '3';
const SN_MCP_TELEMETRY_DB_VERSION_OPT = 'sn_mcp_telemetry_db_version';
const SN_MCP_TELEMETRY_RETENTION_DAYS = 90;
const SN_MCP_TELEMETRY_PRUNE_LIMIT    = 500;
const SN_MCP_TELEMETRY_PRUNE_CHANCE   = 50; // ~1-in-50 inserts also prunes.

/**
 * Kill switch. Default true (telemetry on); a site/owner can disable it with
 * `add_filter( 'sn_mcp_telemetry_enabled', '__return_false' )` — no code
 * change, no option to migrate.
 *
 * @return bool
 */
function sn_mcp_telemetry_enabled() {
	if ( ! function_exists( 'apply_filters' ) ) {
		return true;
	}
	return (bool) apply_filters( 'sn_mcp_telemetry_enabled', true );
}

/* ════════════════════════════════════════════════════════════════════════
 * Schema + lazy install — mirrors inc/analytics-buckets.php's dbDelta idiom.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * dbDelta CREATE TABLE. `layer`/`door`/`outcome` are VARCHAR, not ENUM — this
 * repo's dormant-table idiom (analytics-buckets, analytics-dims) uses VARCHAR
 * throughout, and ENUM columns are absent from every other custom table in
 * inc/, so VARCHAR matches house style over the spec's literal ENUM.
 *
 * @return string CREATE TABLE statement.
 */
function sn_mcp_telemetry_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts DATETIME(3) NOT NULL,
		layer VARCHAR(8) NOT NULL DEFAULT 'server',
		door VARCHAR(8) NOT NULL,
		actor VARCHAR(64) NOT NULL DEFAULT 'human',
		tool_name VARCHAR(128) NOT NULL,
		args_shape VARCHAR(255) NOT NULL DEFAULT '',
		args_hash CHAR(64) NOT NULL DEFAULT '',
		outcome VARCHAR(16) NOT NULL,
		refusal_gate VARCHAR(32) NULL,
		latency_ms INT NOT NULL DEFAULT 0,
		result_count INT NULL,
		candidate_id VARCHAR(64) NULL,
		change_type VARCHAR(32) NULL,
		error_code VARCHAR(64) NULL,
		PRIMARY KEY  (id),
		KEY ts_idx (ts)
	) {$charset};";
}

/** Create the table via dbDelta. Brand-new dormant table — no migration path. */
function sn_mcp_telemetry_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( sn_mcp_telemetry_schema_sql() );
	update_option( SN_MCP_TELEMETRY_DB_VERSION_OPT, SN_MCP_TELEMETRY_DB_VERSION );
}

/** One autoloaded-option compare per request; install runs only on the delta. */
function sn_mcp_telemetry_maybe_install() {
	if ( get_option( SN_MCP_TELEMETRY_DB_VERSION_OPT ) !== SN_MCP_TELEMETRY_DB_VERSION ) {
		sn_mcp_telemetry_install();
	}
}
add_action( 'init', 'sn_mcp_telemetry_maybe_install' );

/* ════════════════════════════════════════════════════════════════════════
 * Pure helpers — no get_option()/time()/global $wpdb inside any of these.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Elapsed milliseconds since a microtime(true) start mark. Tiny pure helper
 * so every sn_mcp_call_tool() return point can compute latency identically.
 *
 * @param float $t0
 * @return int
 */
function sn_mcp_telemetry_elapsed_ms( $t0 ) {
	return (int) round( ( microtime( true ) - (float) $t0 ) * 1000 );
}

/**
 * Sorted, comma-joined, truncated top-level argument keys. Never a value.
 *
 * @param mixed $args
 * @return string
 */
function sn_mcp_telemetry_args_shape( $args ) {
	if ( ! is_array( $args ) || empty( $args ) ) {
		return '';
	}
	$keys = array_map( 'strval', array_keys( $args ) );
	sort( $keys );
	return substr( implode( ',', $keys ), 0, 255 );
}

/**
 * The ONE nested value this module records, and the only exception to the
 * "top-level keys, never a value" rule above (v11.8.0).
 *
 * WHY THE EXCEPTION EXISTS: args_shape captures top-level keys only, so every
 * sn-apply call recorded the identical shape `change,dry_run,idempotency_key,
 * mode,target` regardless of what it actually did. link_reshape, unlink,
 * create_draft, og_card and the rest were indistinguishable in telemetry, which
 * meant the consolidation programme's aggregate sn-apply count could never
 * justify retiring or keeping any individual change type — and the most
 * destructive surface in the system was the least observable.
 *
 * WHY IT IS SAFE: this is an ALLOWLIST, never a passthrough. The value is
 * returned only when it is a member of SNT_SN_APPLY_CHANGE_TYPES — a closed,
 * schema-fixed enum of identifiers that carry no user content and have bounded
 * cardinality. An arbitrary caller string (or a secret pasted into the field)
 * resolves to NULL and is never stored. Sourcing the allowlist from the
 * registration constant rather than a local copy means a type added there is
 * picked up automatically and cannot drift.
 *
 * Guarded with defined(): telemetry is fail-open and must never depend on
 * another module's load order.
 *
 * @param mixed $args The raw tool arguments.
 * @return string|null An allowlisted change type, or null.
 */
function sn_mcp_telemetry_change_type( $args, $tool_name = '' ) {
	if ( is_array( $args ) && isset( $args['change'] ) && is_array( $args['change'] ) ) {
		$type = $args['change']['type'] ?? null;
		if ( is_string( $type ) && '' !== $type ) {
			$allowed = defined( 'SNT_SN_APPLY_CHANGE_TYPES' ) ? (array) constant( 'SNT_SN_APPLY_CHANGE_TYPES' ) : array();
			return in_array( $type, $allowed, true ) ? $type : null;
		}
		return null;
	}

	// v13.3.0 — the same per-dimension observability for the batch READ
	// tools (sn-status / sn-metrics / sn-site-facts), the v11.8.0 rationale
	// verbatim: their aggregate rows could never justify retiring or keeping
	// an INDIVIDUAL section or fact (the wave-4 retirement sheet's exact
	// need). When a call requests EXACTLY ONE section/fact, record it —
	// allowlisted against the tool's own registration map, sourced live
	// (never a local copy) so a section added there is picked up
	// automatically. Multi-entry calls record NULL: the aggregate row is
	// honest; a fabricated "first of three" dimension is not. Every value in
	// those maps is a schema-fixed identifier with bounded cardinality and
	// fits the column's VARCHAR(32).
	// Keyed by BOTH name formats a recorder passes (review MEDIUM): the MCP
	// door records the projected tool name (slug with '/'→'__'), while the
	// lifecycle guard's 'direct' door (WP's native Abilities REST surface)
	// records the raw ability slug. Missing the second format would silently
	// drop every direct-door single-fact read from the dimension — recorded
	// NULL, indistinguishable from a multi-entry call. The agent door builds
	// its rows without this extractor and stays dimension-less (pre-existing,
	// sn-apply included).
	$sources = array(
		'signal-noise__sn-status'     => array( 'key' => 'sections', 'map' => 'snt_sn_status_map' ),
		'signal-noise/sn-status'      => array( 'key' => 'sections', 'map' => 'snt_sn_status_map' ),
		'signal-noise__sn-metrics'    => array( 'key' => 'sections', 'map' => 'snt_sn_metrics_map' ),
		'signal-noise/sn-metrics'     => array( 'key' => 'sections', 'map' => 'snt_sn_metrics_map' ),
		'signal-noise__sn-site-facts' => array( 'key' => 'facts', 'map' => 'snt_sn_site_facts_map' ),
		'signal-noise/sn-site-facts'  => array( 'key' => 'facts', 'map' => 'snt_sn_site_facts_map' ),
	);
	$source = $sources[ (string) $tool_name ] ?? null;
	if ( null === $source || ! is_array( $args ) || ! function_exists( $source['map'] ) ) {
		return null;
	}
	$requested = $args[ $source['key'] ] ?? null;
	if ( ! is_array( $requested ) ) {
		return null;
	}
	// is_string filter FIRST (review LOW): extraction runs on raw,
	// pre-validation args, and strval over a nested-array entry would warn.
	$requested = array_values( array_unique( array_filter( $requested, 'is_string' ) ) );
	if ( 1 !== count( $requested ) ) {
		return null;
	}
	$allowed = array_keys( (array) call_user_func( $source['map'] ) );
	return in_array( $requested[0], $allowed, true ) ? $requested[0] : null;
}

/**
 * Error-code identifier captured from the real WP_Error, at classification.
 *
 * Like change_type, this is an ALLOWLIST, never an arbitrary passthrough. The
 * error-code surface has no central enum equivalent to
 * SNT_SN_APPLY_CHANGE_TYPES, so its allowlist is the WordPress identifier
 * grammar plus the column's 64-byte bound. The input is the WP_Error object,
 * not caller arguments or a later message-only result; values containing
 * spaces, punctuation, or other content-bearing syntax resolve to NULL.
 *
 * @param mixed $error A WP_Error (or test stand-in) exposing get_error_code().
 * @return string|null An allowlisted error-code identifier, or null.
 */
function sn_mcp_telemetry_error_code( $error ) {
	if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_code' ) ) {
		return null;
	}
	return sn_mcp_telemetry_error_code_allowed( $error->get_error_code() );
}

/**
 * The identifier grammar itself, callable on a bare string. build_row()
 * re-applies it so the allowlist holds at the persist choke point too, not
 * only at the classify site — a future caller passing an unfiltered string
 * positionally must not be able to put user content in the column.
 *
 * @param mixed $code Candidate error-code value.
 * @return string|null The code if it passes the grammar, else null.
 */
function sn_mcp_telemetry_error_code_allowed( $code ) {
	if ( ! is_string( $code ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $code ) ) {
		return null;
	}
	return $code;
}

/**
 * sha256 of the JSON-encoded arguments. Never reversible into the source
 * values by design (session 1's corrected deviation from the spec's original
 * base64 prefix — see docs/mcp-consolidation/FINDINGS.md #0).
 *
 * @param mixed $args
 * @return string
 */
function sn_mcp_telemetry_args_hash( $args ) {
	$clean = is_array( $args ) ? $args : array();
	$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $clean ) : json_encode( $clean );
	return hash( 'sha256', (string) $json );
}

/**
 * Trivial top-level result count: only when the ability's raw output is a
 * plain (list-shaped) PHP array — never introspects nested shapes. Anything
 * else (assoc array, scalar, object, null) is NULL, not guessed.
 *
 * @param mixed $out The ability's raw execute() output (pre-wrap).
 * @return int|null
 */
function sn_mcp_telemetry_result_count( $out ) {
	if ( ! is_array( $out ) ) {
		return null;
	}
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $out ) ? count( $out ) : null;
	}
	// Defensive fallback only — composer.json requires php>=8.3, so
	// array_is_list() (8.1+) is always available in practice.
	return array_keys( $out ) === range( 0, count( $out ) - 1 ) ? count( $out ) : null;
}

/**
 * Status-less WP_Error codes that a multi-line-aware sweep of every
 * `new WP_Error(` construction under inc/ (196 total, run via a
 * paren-balanced perl scan — a plain single-line grep silently drops any
 * construction whose args span multiple lines, which is most of them) proved
 * are (a) missing an array('status'=>N) data payload, (b) reachable as an
 * ability's execute() return value, AND (c) a CALLER-argument problem rather
 * than a server-side failure. Status-less constructions in inc/webhooks.php,
 * inc/muso-api.php, inc/uptime-status.php, and inc/wp-update-integration.php
 * are unreachable via any ability (no execute_callback calls into them and
 * propagates the raw WP_Error). The insights/narration AI-response family IS
 * reachable raw — signal-noise/run-insights-scan and run-narration propagate
 * snt_insights_encode_failed, snt_insights_invalid_json,
 * snt_narration_encode_failed, snt_narration_invalid_json,
 * snt_narration_no_headline, and snt_narration_no_body straight through
 * (inc/insights.php:758-765 and the narration equivalent) — but every one of
 * those is a genuine server/AI-side failure (JSON-encode failure, unparseable
 * or incomplete AI output), so the classifier's status-less default of
 * server_error is CORRECT for them and they are deliberately absent from
 * this list. The only status-less caller-argument code found:
 * 'sn_tag_not_unused' (inc/tag-consolidation.php:376, inside
 * sn_tag_delete_unused()), propagated raw by signal-noise/prune-unused-tags
 * (inc/abilities-content.php's snt_ability_prune_unused_tags(), the
 * `if ( is_wp_error( $res ) ) { return $res; }` tail) — a caller-supplied
 * tag id that turned out non-empty since the scan. If you add a status-less
 * WP_Error that an ability propagates and it is the caller's fault, it
 * belongs HERE; if it is the server's fault, the default already handles it.
 *
 * @return string[]
 */
function sn_mcp_telemetry_status_less_schema_codes() {
	return array( 'sn_tag_not_unused' );
}

/**
 * Status-429 codes that are NOT the rw door's own rate-limit gate (that
 * case is classified directly in sn_mcp_call_tool(), before execute() is
 * even reached) but still represent a throttle/cap an ability itself
 * enforces. The sweep found exactly one: 'snt_surfaces_throttled'
 * (inc/abilities-update-post-surfaces.php:162, a per-post write-cap, status
 * 429 — the ONLY status-429 construction under inc/ today).
 *
 * @return string[]
 */
function sn_mcp_telemetry_throttle_codes() {
	return array( 'snt_surfaces_throttled' );
}

/**
 * Classify an execute() WP_Error, status-first — NOT string/substring
 * matching on the error code (a prior version of this function used a
 * code-name regex grounded on a single-line `grep`, which silently missed
 * every multi-line `new WP_Error(` construction — e.g.
 * snt_cron_args_signature_requires_hook, snt_sn_hook_refused,
 * snt_surfaces_nothing_to_write, snt_surfaces_too_long,
 * snt_surfaces_throttled all span multiple lines and were invisible to it;
 * and 'missing' as a substring wrongly caught snt_impl_missing, a genuine
 * HTTP-500 server condition, as a schema error).
 *
 * Nearly every ability-reachable WP_Error in this codebase carries
 * array('status'=>N) as its error_data (paren-balanced sweep of all 196
 * `new WP_Error(` constructions under inc/: 168 carry a status, 28 don't;
 * of the status-less ones, the reachable set is sn_tag_not_unused — the one
 * caller-argument case, mapped explicitly below — plus the insights/
 * narration AI-response failures, which the server_error default classifies
 * correctly; see sn_mcp_telemetry_status_less_schema_codes()'s docblock for
 * the full accounting). get_error_data() with no argument returns the
 * FIRST registered code's data, which is exactly what WP_Error's own
 * add()/get_error_data() implementation does and matches how every ability
 * here constructs its error (one code per WP_Error).
 *
 *   - status 429                        -> refused (refusal_gate 'rate_limit',
 *                                          or 'write_throttle' for the codes
 *                                          in sn_mcp_telemetry_throttle_codes()).
 *   - status 400-428 or 431-499         -> schema_error (a 4xx band is, by
 *                                          construction, the ability
 *                                          rejecting what the CALLER supplied).
 *   - status >= 500                     -> server_error.
 *   - no status, code in the status-less
 *     schema list (see above)           -> schema_error.
 *   - no status, any other code         -> server_error (honest default: an
 *                                          unexplained failure is not proven
 *                                          to be the caller's fault).
 *
 * @param mixed $error A WP_Error (or test stand-in) exposing get_error_code()
 *                      and get_error_data().
 * @return array{outcome:string,refusal_gate:string|null,error_code:string|null}
 */
function sn_mcp_telemetry_classify_wp_error( $error ) {
	$code = sn_mcp_telemetry_error_code( $error );
	$data = ( is_object( $error ) && method_exists( $error, 'get_error_data' ) )
		? $error->get_error_data()
		: null;
	$status = ( is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) )
		? (int) $data['status']
		: null;

	if ( null !== $status ) {
		if ( 429 === $status ) {
			$gate = in_array( $code, sn_mcp_telemetry_throttle_codes(), true ) ? 'write_throttle' : 'rate_limit';
			return array( 'outcome' => 'refused', 'refusal_gate' => $gate, 'error_code' => $code );
		}
		// v11.8.0: 409 is OPTIMISTIC-CONCURRENCY contention, not malformed
		// input, and must be split out BEFORE the 4xx band that used to
		// swallow it. A paren-balanced (perl -0777) sweep of every
		// `new WP_Error(` under inc/ found 24 constructions carrying
		// array('status'=>409) — the whole apply family's stale-state surface
		// (fingerprint mismatch, anchor moved, phrase moved, revision belongs
		// to another post, idempotency key reused against another target) plus
		// ai-orphan-suggest's TOCTOU re-check. Filed as schema_error they were
		// indistinguishable from a caller typo, which made the contention rate
		// — the primary signal for whether a fingerprint's granularity is
		// right — unreadable. No refusal_gate: a conflict is not a gate
		// refusal, it is a lost race.
		if ( 409 === $status ) {
			return array( 'outcome' => 'conflict', 'refusal_gate' => null, 'error_code' => $code );
		}
		if ( ( $status >= 400 && $status <= 428 ) || ( $status >= 431 && $status <= 499 ) ) {
			return array( 'outcome' => 'schema_error', 'refusal_gate' => null, 'error_code' => $code );
		}
		if ( $status >= 500 ) {
			return array( 'outcome' => 'server_error', 'refusal_gate' => null, 'error_code' => $code );
		}
	}

	if ( in_array( $code, sn_mcp_telemetry_status_less_schema_codes(), true ) ) {
		return array( 'outcome' => 'schema_error', 'refusal_gate' => null, 'error_code' => $code );
	}

	return array( 'outcome' => 'server_error', 'refusal_gate' => null, 'error_code' => $code );
}

/**
 * PURE row builder — every piece of state is a parameter, mirroring
 * inc/mcp/mcp-rw-audit.php's sn_mcp_rw_audit_build_row() split so this is
 * directly unit-testable without a WP bootstrap.
 *
 * @param string      $ts           'Y-m-d H:i:s.vvv' UTC.
 * @param string      $door         'read' | 'rw'.
 * @param string      $actor        'human' | 'app-pw:<8 chars>' today. 'routine:<name>'
 *                                  is RESERVED for a later phase (no code path
 *                                  produces it yet — see sn_mcp_telemetry_actor()).
 * @param string      $tool_name
 * @param string      $args_shape
 * @param string      $args_hash
 * @param string      $outcome      'ok'|'schema_error'|'conflict'|'not_found'|'refused'|'server_error'.
 * @param string|null $refusal_gate
 * @param int         $latency_ms
 * @param int|null    $result_count
 * @param string|null $change_type  Allowlisted sn-apply change.type, or null.
 *                                  Appended last so existing positional callers
 *                                  keep working. @since v11.8.0.
 * @param string|null $error_code   Allowlisted WP_Error code captured by the
 *                                  classifier, or null.
 * @return array<string,mixed>
 */
function sn_mcp_telemetry_build_row( $ts, $door, $actor, $tool_name, $args_shape, $args_hash, $outcome, $refusal_gate, $latency_ms, $result_count, $change_type = null, $error_code = null ) {
	return array(
		'ts'           => (string) $ts,
		'layer'        => 'server',
		'door'         => (string) $door,
		'actor'        => (string) $actor,
		'tool_name'    => (string) $tool_name,
		'args_shape'   => (string) $args_shape,
		'args_hash'    => (string) $args_hash,
		'outcome'      => (string) $outcome,
		'refusal_gate' => null === $refusal_gate ? null : (string) $refusal_gate,
		'latency_ms'   => (int) $latency_ms,
		'result_count' => null === $result_count ? null : (int) $result_count,
		'candidate_id' => null, // Reserved; always NULL this session.
		// Persist-choke bound (review LOW): the column is VARCHAR(32); a
		// longer identifier must resolve to NULL here, never silent SQL
		// truncation — the same belt the error_code grammar re-applies.
		'change_type'  => ( null === $change_type || strlen( (string) $change_type ) > 32 ) ? null : (string) $change_type,
		'error_code'   => 'ok' === $outcome ? null : sn_mcp_telemetry_error_code_allowed( $error_code ),
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * Live wrappers.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Live: resolve the 'actor' column. The rw door's bound app-password UUID
 * resolver is door-agnostic in practice (it only reads
 * rest_get_authenticated_app_password() for the CURRENT request) so it is
 * reused for both doors here — a deviation from the letter of the task brief
 * ("the rw door's... resolver"), noted in FINDINGS.md: app-password
 * attribution is equally meaningful on the read door, and there is no
 * separate read-door resolver to prefer instead.
 *
 * @return string
 */
function sn_mcp_telemetry_actor() {
	if ( function_exists( 'sn_mcp_rw_audit_authenticated_app_password_uuid' ) ) {
		$uuid = sn_mcp_rw_audit_authenticated_app_password_uuid();
		if ( is_string( $uuid ) && '' !== $uuid ) {
			return 'app-pw:' . substr( $uuid, 0, 8 );
		}
	}
	return 'human';
}

/**
 * Live: millisecond-precision UTC timestamp string for the `ts DATETIME(3)`
 * column. gmdate()'s own 'v' format char always reads as 000 against a
 * time()-based integer (no sub-second component to format), so the
 * milliseconds are computed from microtime(true) directly instead —
 * gmdate() supplies the UTC date/second part only, per the project's
 * standing "never let session/local time leak into a stored timestamp" rule.
 *
 * @return string
 */
function sn_mcp_telemetry_now_ts() {
	$now = microtime( true );
	$ms  = (int) round( ( $now - floor( $now ) ) * 1000 );
	if ( 1000 === $ms ) { // Rounding at the second boundary.
		$now += 1;
		$ms   = 0;
	}
	return gmdate( 'Y-m-d H:i:s', (int) $now ) . '.' . sprintf( '%03d', $ms );
}

/**
 * Live: the single INSERT. Never called directly by anything except
 * sn_mcp_telemetry_record(), which already wraps this in a try/catch — this
 * function does not catch on its own so a genuine wpdb exception still
 * propagates to that outer boundary rather than being swallowed twice.
 *
 * @param array<string,mixed> $row
 * @return void
 */
function sn_mcp_telemetry_insert_row( $row ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
		return;
	}
	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$wpdb->insert(
		$table,
		array(
			'ts'           => $row['ts'],
			'layer'        => $row['layer'],
			'door'         => $row['door'],
			'actor'        => $row['actor'],
			'tool_name'    => $row['tool_name'],
			'args_shape'   => $row['args_shape'],
			'args_hash'    => $row['args_hash'],
			'outcome'      => $row['outcome'],
			'refusal_gate' => $row['refusal_gate'],
			'latency_ms'   => $row['latency_ms'],
			'result_count' => $row['result_count'],
			'candidate_id' => $row['candidate_id'],
			'change_type'  => $row['change_type'] ?? null,
			'error_code'   => $row['error_code'] ?? null,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
	);
}

/**
 * Read rollup of sn_tool_call, for sn_site_facts' "tool_telemetry" fact
 * (v11.9.0). Grouped SELECTs only, no per-row reads, never argument values.
 *
 * WHY THIS EXISTS: v11.8.0 added the `change_type` dimension so individual
 * sn-apply change types could be retired or kept on evidence, and the
 * `conflict` outcome so fingerprint contention was distinguishable from
 * malformed input — then shipped both into a table with no read path. The data
 * was collected and unreachable from the place the decisions get made. This is
 * that path.
 *
 * `table_present` carries the same meaning it does in
 * snt_scan_telemetry_summary(): an empty rollup with table_present:true is an
 * honest empty window; table_present:false means the table is missing or the
 * query failed, the fail-open insert path has been eating rows, and NO number
 * here is a measurement. Zero and null are different answers.
 *
 * `by_change_type` is the half that services the consolidation programme —
 * rows where change_type IS NOT NULL, which today is sn-apply only.
 * `by_error_code` keeps each plugin-authored failure identifier attributable
 * to its tool and outcome instead of collapsing distinct causes into a bucket.
 *
 * @param int $days Window, in days.
 * @return array{window_days:int,generated_at:string,table_present:bool,total_calls:int,by_tool:array,by_change_type:array,by_error_code:array}
 */
function sn_mcp_telemetry_summary( $days = 30 ) {
	global $wpdb;

	$days = max( 1, (int) $days );
	$out  = array(
		'window_days'    => $days,
		'generated_at'   => gmdate( 'c' ),
		'table_present'  => false,
		'total_calls'    => 0,
		'by_tool'        => array(),
		'by_change_type' => array(),
		'by_error_code'  => array(),
	);

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return $out;
	}

	$table  = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql  = $wpdb->prepare( "SELECT tool_name, door, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s GROUP BY tool_name, door, outcome ORDER BY calls DESC, tool_name ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	// A FAILED wpdb query returns []/null with last_error SET — it does not
	// throw, and a missing table yields an EMPTY ARRAY, not false. Checking
	// is_array() alone would report a missing table as an honest zero, which is
	// the exact lie table_present exists to prevent. Mirrors
	// snt_scan_telemetry_summary()'s check, including resetting last_error
	// first so a pre-existing error from an unrelated query cannot leak in.
	// A FAILED wpdb query returns []/null with last_error SET — it does not
	// throw, and a missing table yields an EMPTY ARRAY, not false. Checking
	// is_array() alone would report a missing table as an honest zero, which is
	// the exact lie table_present exists to prevent. Mirrors
	// snt_scan_telemetry_summary()'s check, including resetting last_error
	// first so a pre-existing error from an unrelated query cannot leak in.
	if ( '' !== (string) $wpdb->last_error || null === $rows ) {
		return $out;
	}

	foreach ( (array) $rows as $r ) {
		$calls               = (int) ( $r['calls'] ?? 0 );
		$out['total_calls'] += $calls;
		$out['by_tool'][]    = array(
			'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
			'door'           => (string) ( $r['door'] ?? '' ),
			'outcome'        => (string) ( $r['outcome'] ?? '' ),
			'calls'          => $calls,
			'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
			'last_call'      => (string) ( $r['last_call'] ?? '' ),
		);
	}

	// v13.3.0: tool_name joined the grouping — the change_type column now
	// carries a per-tool dimension (sn-apply's change.type, and the single
	// requested section/fact for the batch read tools), so rows must name
	// which tool's dimension they are. Additive key; sn-apply rows read as
	// before with tool_name alongside.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql2  = $wpdb->prepare( "SELECT tool_name, change_type, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s AND change_type IS NOT NULL GROUP BY tool_name, change_type, outcome ORDER BY calls DESC, change_type ASC, tool_name ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql2 is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows2 = $wpdb->get_results( $sql2, ARRAY_A );

	// The by_tool half already succeeded, so the table is present either way;
	// a failure HERE degrades only this section rather than discarding a good
	// rollup, and by_change_type stays empty rather than partly filled.
	if ( '' === (string) $wpdb->last_error && null !== $rows2 ) {
		foreach ( (array) $rows2 as $r ) {
			$out['by_change_type'][] = array(
				'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
				'change_type'    => (string) ( $r['change_type'] ?? '' ),
				'outcome'        => (string) ( $r['outcome'] ?? '' ),
				'calls'          => (int) ( $r['calls'] ?? 0 ),
				'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
				'last_call'      => (string) ( $r['last_call'] ?? '' ),
			);
		}
	}

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff is bound via prepare() below.
	$sql3  = $wpdb->prepare( "SELECT tool_name, error_code, outcome, COUNT(*) AS calls, AVG(latency_ms) AS avg_latency_ms, MAX(ts) AS last_call FROM {$table} WHERE ts >= %s AND error_code IS NOT NULL GROUP BY tool_name, error_code, outcome ORDER BY calls DESC, tool_name ASC, error_code ASC", $cutoff );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql3 is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$rows3 = $wpdb->get_results( $sql3, ARRAY_A );

	if ( '' === (string) $wpdb->last_error && null !== $rows3 ) {
		foreach ( (array) $rows3 as $r ) {
			$out['by_error_code'][] = array(
				'tool_name'      => (string) ( $r['tool_name'] ?? '' ),
				'error_code'     => (string) ( $r['error_code'] ?? '' ),
				'outcome'        => (string) ( $r['outcome'] ?? '' ),
				'calls'          => (int) ( $r['calls'] ?? 0 ),
				'avg_latency_ms' => isset( $r['avg_latency_ms'] ) && null !== $r['avg_latency_ms'] ? (int) round( (float) $r['avg_latency_ms'] ) : null,
				'last_call'      => (string) ( $r['last_call'] ?? '' ),
			);
		}
	}

	$out['table_present'] = true;
	return $out;
}

/**
 * Live: opportunistic retention prune. ~1-in-50 chance per insert (never a
 * cron, per the standing "schedule nothing" guardrail this week), capped at
 * SN_MCP_TELEMETRY_PRUNE_LIMIT rows per fire so a single request can never be
 * saddled with an unbounded DELETE.
 *
 * @return void
 */
function sn_mcp_telemetry_maybe_prune() {
	$roll = function_exists( 'wp_rand' ) ? wp_rand( 1, SN_MCP_TELEMETRY_PRUNE_CHANCE ) : mt_rand( 1, SN_MCP_TELEMETRY_PRUNE_CHANCE );
	if ( 1 !== $roll ) {
		return;
	}

	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
		return;
	}
	$table  = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - SN_MCP_TELEMETRY_RETENTION_DAYS * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + a plugin constant, never user input; $cutoff/limit are bound via prepare() below.
	$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s LIMIT %d", $cutoff, SN_MCP_TELEMETRY_PRUNE_LIMIT );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the STRING RETURNED BY $wpdb->prepare() one line above, already safely bound.
	$wpdb->query( $sql );
}

/**
 * LIVE: record one tools/call outcome, both doors, every outcome. This is the
 * ONE function inc/mcp/mcp-tools.php's sn_mcp_call_tool() calls, at every
 * return point. Entirely fail-open: table missing, wpdb error, or any
 * unexpected exception all degrade to a silent no-op — the tool response the
 * caller already built is never touched, because this function's return
 * value is never inspected by its call sites.
 *
 * @param string      $tool_name
 * @param mixed       $arguments
 * @param string      $door         SN_MCP_DOOR_READ | SN_MCP_DOOR_RW.
 * @param string      $outcome      'ok'|'schema_error'|'not_found'|'refused'|'server_error'.
 * @param string|null $refusal_gate
 * @param int         $latency_ms
 * @param int|null    $result_count
 * @param string|null $error_code   Captured by the WP_Error classifier before
 *                                  the result is flattened to message-only.
 * @return void
 */
function sn_mcp_telemetry_record( $tool_name, $arguments, $door, $outcome, $refusal_gate, $latency_ms, $result_count = null, $error_code = null ) {
	if ( ! sn_mcp_telemetry_enabled() ) {
		return;
	}
	try {
		$args = is_array( $arguments ) ? $arguments : array();
		$row  = sn_mcp_telemetry_build_row(
			sn_mcp_telemetry_now_ts(),
			(string) $door,
			sn_mcp_telemetry_actor(),
			(string) $tool_name,
			sn_mcp_telemetry_args_shape( $args ),
			sn_mcp_telemetry_args_hash( $args ),
			(string) $outcome,
			$refusal_gate,
			(int) $latency_ms,
			$result_count,
			sn_mcp_telemetry_change_type( $args, (string) $tool_name ),
			$error_code
		);
		sn_mcp_telemetry_insert_row( $row );
		sn_mcp_telemetry_maybe_prune();
	} catch ( \Throwable $e ) {
		return;
	}
}
