<?php
/**
 * Signal & Noise — MCP read-door guard: the read kill switch (v10.9.0).
 *
 * The rw door has had a kill switch since v9.51.0 (R2, mcp-rw-guard.php);
 * the read door deliberately had none — its permission path was BYTE-FROZEN
 * so the rw hardening could never destabilize reads. This file is the
 * owner-requested (2026-07-30) read-side lever: instantly darken corpus
 * visibility (scheduled/draft bodies, operational reads) without deauthing
 * the application password or touching the rw door.
 *
 * The freeze's real invariant survives intact:
 *   - sn_mcp_permission() (the manage_options floor) is byte-identical.
 *   - This file NEVER calls into mcp-rw-guard.php, and vice versa — the two
 *     doors' guards stay isolated, per the read/write split.
 * What changed is the read route's permission_callback: now the layered
 * sn_mcp_read_permission() (kill switch first, then the frozen floor) —
 * the pinned contract in tests/mcp-endpoint.php was amended the same day.
 *
 * Same decision semantics as the rw switch, same reasoning:
 *   - wp-config constant SN_MCP_READ_DISABLED is bulletproof (an attacker
 *     holding only a leaked app password can never flip it) and wins over
 *     the option unconditionally.
 *   - Option sn_mcp_read_enabled is the owner's UI-reachable kill;
 *     absent = enabled (FAIL-OPEN-ON-ABSENCE: an untouched switch means
 *     "the owner never turned it off", exactly like the rw door's).
 *
 * @package SignalNoiseTools
 * @since 10.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_READ_ENABLED_OPTION = 'sn_mcp_read_enabled';

/**
 * Read kill-switch PURE predicate. Mirrors sn_mcp_rw_kill_switch_decision()
 * exactly; duplicated rather than shared because the door guards must not
 * import each other (the isolation IS the design).
 *
 * @param bool $constant_disabled Whether defined('SN_MCP_READ_DISABLED') && SN_MCP_READ_DISABLED.
 * @param bool $option_enabled    The sn_mcp_read_enabled option's value (default true).
 * @return bool True when the read door must be treated as disabled.
 */
function sn_mcp_read_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the read door disabled right now?
 *
 * @return bool
 */
function sn_mcp_read_kill_switch_engaged() {
	$constant_disabled = defined( 'SN_MCP_READ_DISABLED' ) && SN_MCP_READ_DISABLED;
	$option_enabled    = function_exists( 'get_option' )
		? (bool) get_option( SN_MCP_READ_ENABLED_OPTION, true )
		: true;
	return sn_mcp_read_kill_switch_decision( $constant_disabled, $option_enabled );
}

/**
 * Read-door permission callback (v10.9.0): kill switch FIRST — a 403 here
 * means tools/list can never leak the read tool set while the door is dark —
 * then the unchanged manage_options floor.
 *
 * @return true|false|WP_Error
 */
function sn_mcp_read_permission() {
	if ( sn_mcp_read_kill_switch_engaged() ) {
		return new WP_Error(
			'sn_mcp_read_disabled',
			__( 'The MCP read door is currently disabled.', 'signal-and-noise-tools' ),
			array( 'status' => 403 )
		);
	}
	return sn_mcp_permission();
}
