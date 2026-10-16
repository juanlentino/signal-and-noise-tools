<?php
/**
 * Signal & Noise — MCP server: tool projection + call dispatch. Projects an
 * allowlisted WP_Ability into an MCP Tool and executes tools/call with the
 * allowlist gate + per-ability permission check. Sub-project B. Door-aware
 * since v9.50.0: every entry point takes a $door (SN_MCP_DOOR_READ by
 * default) and resolves its allowlist through sn_mcp_allowlist_for_door — the
 * two-doors security property (the allowlist gates the CALL per door, not
 * just the advertised list) lives here.
 *
 * v9.51.0 (lane SEC-B, R4): sn_mcp_call_tool()'s tail now calls
 * sn_mcp_rw_audit_record() (inc/mcp/mcp-rw-audit.php) at its three outcome
 * points (permission denied, execute() WP_Error, success) — but ONLY when
 * $door === SN_MCP_DOOR_RW. The read door's dispatch is otherwise
 * byte-identical to pre-v9.51.0; nothing above the tail (projection, schema
 * wrapping) changed.
 *
 * v9.51.0 (lane SEC-C, R6+R7): two more rw-only additions, both gated on
 * $door === SN_MCP_DOOR_RW exactly like SEC-B's audit call:
 *   - R6: the rw door's projected tools now carry a fully-populated,
 *     curated `annotations` object (readOnlyHint/destructiveHint/
 *     idempotentHint) instead of v9.50.0's "no annotations key at all" —
 *     see sn_mcp_ability_annotations() below. The read door's single
 *     `{readOnlyHint:true}` shape is untouched.
 *   - R7: sn_mcp_call_tool()'s very first act, before even validating the
 *     tool name, is a rate-limit check (inc/mcp/mcp-rw-guard.php's
 *     sn_mcp_rw_rate_limit_gate()) — ONLY on the rw door. A denial is a
 *     JSON-RPC protocol error (never reaches an ability, never audited —
 *     same "protocol rejection, not audited" precedent SEC-B's own tests
 *     already pin for an unknown-tool call), carrying a retry hint in the
 *     error's `data` (JSON-RPC has no HTTP header seam mid-batch to carry a
 *     Retry-After in). JUDGMENT CALL, flagged: the spec placed R7 alongside
 *     R1/R2 in mcp-rw-guard.php (a file this lane does not own the call site
 *     of — inc/mcp/mcp-endpoint.php's permission_callback layer belongs to
 *     lane SEC-A), but R7's own text describes a JSON-RPC-error-shaped
 *     denial, which is THIS file's layer, not a REST WP_Error. This file
 *     hosts the gate; inc/mcp/mcp-rw-guard.php hosts the predicate.
 *
 * @package SignalNoiseTools
 * @since 9.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order the code always ran.
require_once __DIR__ . '/mcp-tools-schema-parts.php';
require_once __DIR__ . '/mcp-tools-project-parts.php';
require_once __DIR__ . '/mcp-tools-call-parts.php';
