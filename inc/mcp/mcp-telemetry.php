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
const SN_MCP_TELEMETRY_DB_VERSION     = '5';
const SN_MCP_TELEMETRY_DB_VERSION_OPT = 'sn_mcp_telemetry_db_version';
const SN_MCP_TELEMETRY_RETENTION_DAYS = 90;
const SN_MCP_TELEMETRY_PRUNE_LIMIT    = 500;
const SN_MCP_TELEMETRY_PRUNE_CHANCE   = 50; // ~1-in-50 inserts also prunes.
// v13.48.0. Mirrors the error_detail column's VARCHAR(255) bound, in BYTES.
const SN_MCP_TELEMETRY_ERROR_DETAIL_MAX = 255;
// Above this, an envelope message is not worth decoding — see
// sn_mcp_telemetry_unwrap_envelope_message(). Generous next to sn-apply's
// ~500-byte refusal envelope, small enough to stay bounded work.
const SN_MCP_TELEMETRY_ENVELOPE_DECODE_MAX = 20000;

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
 * TWO CLASSES OF ERROR COLUMN, and the distinction is load-bearing:
 * `error_code` is the AGGREGATION DIMENSION — bounded grammar, allowlisted,
 * safe to GROUP BY. `error_detail`/`error_status` (v13.48.0) are DIAGNOSTIC
 * ONLY: they carry the WP_Error's own message and HTTP status so a failure is
 * readable after the fact instead of being reduced to an identifier. They are
 * never grouped, filtered, or surfaced in the tool_telemetry rollup — see
 * sn_mcp_telemetry_error_detail() for why that separation is not optional.
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
		dimensions VARCHAR(512) NULL,
		error_code VARCHAR(64) NULL,
		error_detail VARCHAR(255) NULL,
		error_status SMALLINT UNSIGNED NULL,
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

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/mcp-telemetry-shape-parts.php';
require_once __DIR__ . '/mcp-telemetry-classify-parts.php';
require_once __DIR__ . '/mcp-telemetry-live-parts.php';
