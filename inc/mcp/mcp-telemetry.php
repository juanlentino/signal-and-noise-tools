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
const SN_MCP_TELEMETRY_DB_VERSION     = '1';
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
 * @return array{outcome:string,refusal_gate:string|null}
 */
function sn_mcp_telemetry_classify_wp_error( $error ) {
	$code = ( is_object( $error ) && method_exists( $error, 'get_error_code' ) )
		? (string) $error->get_error_code()
		: '';
	$data = ( is_object( $error ) && method_exists( $error, 'get_error_data' ) )
		? $error->get_error_data()
		: null;
	$status = ( is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) )
		? (int) $data['status']
		: null;

	if ( null !== $status ) {
		if ( 429 === $status ) {
			$gate = in_array( $code, sn_mcp_telemetry_throttle_codes(), true ) ? 'write_throttle' : 'rate_limit';
			return array( 'outcome' => 'refused', 'refusal_gate' => $gate );
		}
		if ( ( $status >= 400 && $status <= 428 ) || ( $status >= 431 && $status <= 499 ) ) {
			return array( 'outcome' => 'schema_error', 'refusal_gate' => null );
		}
		if ( $status >= 500 ) {
			return array( 'outcome' => 'server_error', 'refusal_gate' => null );
		}
	}

	if ( in_array( $code, sn_mcp_telemetry_status_less_schema_codes(), true ) ) {
		return array( 'outcome' => 'schema_error', 'refusal_gate' => null );
	}

	return array( 'outcome' => 'server_error', 'refusal_gate' => null );
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
 * @param string      $outcome      'ok'|'schema_error'|'not_found'|'refused'|'server_error'.
 * @param string|null $refusal_gate
 * @param int         $latency_ms
 * @param int|null    $result_count
 * @return array<string,mixed>
 */
function sn_mcp_telemetry_build_row( $ts, $door, $actor, $tool_name, $args_shape, $args_hash, $outcome, $refusal_gate, $latency_ms, $result_count ) {
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
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
	);
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
 * @return void
 */
function sn_mcp_telemetry_record( $tool_name, $arguments, $door, $outcome, $refusal_gate, $latency_ms, $result_count = null ) {
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
			$result_count
		);
		sn_mcp_telemetry_insert_row( $row );
		sn_mcp_telemetry_maybe_prune();
	} catch ( \Throwable $e ) {
		return;
	}
}
