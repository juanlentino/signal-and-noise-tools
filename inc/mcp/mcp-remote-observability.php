<?php
/**
 * Signal & Noise — remote analytics door observability (R3 §3D Increment 4).
 *
 * Nothing recorded that the remote door was used, so every control in
 * docs/ops/remote-mcp-revoke-runbook.md assumed the owner already suspected
 * something. This module is the trigger those controls were missing.
 *
 * IT IS OBSERVATIONAL, AND THAT IS A HARD CONSTRAINT. The bridge calls into
 * this file only behind function_exists(), and a test pins that the door
 * behaves byte-identically with this module absent. A broken log must not be
 * able to shut the door — and must not be able to open it either.
 *
 * ISOLATION: this file calls nothing in mcp-remote-guard.php or audit-log.php,
 * and neither calls into it. The shape below MIRRORS inc/audit-log.php's proven
 * blob (per-day counters, a capped ring, retention) without sharing its
 * storage, exactly as the remote guard mirrors the read guard's predicate
 * rather than importing it.
 *
 * WHAT IT CANNOT DO: record WHO. Cloudflare Access issues and holds the
 * session; WordPress never sees it, so at the origin a bridge call is a valid
 * Bearer token and nothing more. The threat model's §8.4 "audit the caller"
 * requirement is NOT satisfied here and cannot be — that is Worker-side, where
 * src/guard.mjs already returns { sub, email }. Do not add a field that implies
 * otherwise.
 *
 * @package SignalNoiseTools
 * @since 11.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The record. NOT autoloaded — only the admin panel reads it. */
const SN_MCP_REMOTE_LOG_OPTION = 'sn_mcp_remote_log_v1';

/** Coalescing buffer for refusal counts. See sn_mcp_remote_record(). */
const SN_MCP_REMOTE_PENDING_TRANSIENT = 'sn_mcp_remote_pending';

/** Day-buckets older than this are dropped on write. Mirrors the audit log. */
const SN_MCP_REMOTE_LOG_RETENTION_DAYS = 90;

/**
 * Recent-call ring size. Small enough that the option stays trivial, large
 * enough to survive one ordinary phone session without rolling. It is a display
 * aid; the counters are the durable record.
 */
const SN_MCP_REMOTE_LOG_RING_CAP = 50;

/** How long pending refusals may sit before the next request flushes them. */
const SN_MCP_REMOTE_FLUSH_SECONDS = 60;

/**
 * Pending-buffer TTL. DELIBERATELY far longer than the flush window: nothing
 * schedules a flush, so if a probe stops, the last sub-minute of counts sits
 * here with no further request to trigger one. The admin read collects them. A
 * TTL near the flush window would silently discard the tail of an attack that
 * stopped — the counts most worth having.
 */
const SN_MCP_REMOTE_PENDING_TTL = HOUR_IN_SECONDS;

/**
 * The closed set of outcomes.
 *
 * refused_shut and refused_auth are BYTE-IDENTICAL TO THE CALLER — that is the
 * whole point of the 404 parity fix — and separable only here, in a record that
 * is admin-only and never echoed. "Calls arrived while I had it switched off"
 * is a different and more alarming fact than "someone guessed at the token".
 * Do not collapse them to match the wire, and do not leak the distinction to it.
 */
const SN_MCP_REMOTE_OUTCOMES = array(
	'dispatched',
	'refused_shut',
	'refused_auth',
	'refused_slug',
	'refused_request',
);

/**
 * Today's bucket key, in the SITE timezone.
 *
 * wp_date(), exactly as snt_audit_today_key() does. This log sits beside the
 * login audit log in the same admin area and is read by the same person; two
 * security readouts disagreeing about what "today" means would be a defect, and
 * a UTC bucket reads as wrong to anyone looking at the panel in the evening.
 *
 * DO NOT swap this to gmdate(). On a UTC server the two return the same string,
 * so the swap is invisible to any value-comparison test — which is why the pin
 * asserts wp_date was CALLED rather than what it returned.
 *
 * Known cost: changing the site timezone reinterprets stored values. Acceptable
 * for a diagnostic line, and the same trade inc/audit-log.php already makes.
 *
 * @return string
 */
function sn_mcp_remote_log_day_key() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date( 'Y-m-d' );
}

/**
 * A timestamp for the record, in the SITE timezone. Same reasoning as the day
 * key — one timezone throughout, so nothing needs converting for display.
 *
 * @return string
 */
function sn_mcp_remote_log_now() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
}

/**
 * The empty, valid blob.
 *
 * @return array
 */
function sn_mcp_remote_log_empty_blob() {
	return array(
		'schema'    => 1,
		'last_used' => null,
		'counters'  => array(),
		'recent'    => array(),
	);
}

/**
 * Read the blob, repairing anything missing.
 *
 * An option can be hand-edited, half-written, or restored from an older schema.
 * Every key is filled defensively so callers never index an undefined one.
 *
 * @return array
 */
function sn_mcp_remote_log_get_blob() {
	$stored = function_exists( 'get_option' ) ? get_option( SN_MCP_REMOTE_LOG_OPTION, array() ) : array();
	if ( ! is_array( $stored ) ) {
		return sn_mcp_remote_log_empty_blob();
	}
	$blob = array_merge( sn_mcp_remote_log_empty_blob(), $stored );

	$blob['schema']    = 1;
	$blob['counters']  = is_array( $blob['counters'] ) ? $blob['counters'] : array();
	$blob['recent']    = is_array( $blob['recent'] ) ? $blob['recent'] : array();
	$blob['last_used'] = is_string( $blob['last_used'] ) ? $blob['last_used'] : null;

	return $blob;
}

/**
 * Persist the blob. NEVER autoloaded — see the constant's docblock.
 *
 * @param array $blob
 * @return void
 */
function sn_mcp_remote_log_save_blob( $blob ) {
	if ( function_exists( 'update_option' ) ) {
		update_option( SN_MCP_REMOTE_LOG_OPTION, $blob, false );
	}
}
