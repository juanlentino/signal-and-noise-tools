<?php
/**
 * Signal & Noise Tools: the read door's optional credential binding.
 *
 * Binding an Application Password to the read door scopes THAT password to the
 * read door: mcp-rw-credential-scope.php then refuses it everywhere except
 * /signal-noise/v1/mcp and the run routes of read-door tools (2026-10-26 MCP
 * doors audit, N0's read half). It does not make the read door exclusive: any
 * administrator credential still reaches it, and an unbound read door behaves
 * exactly as before. Optional by design, so nothing changes until the owner
 * picks a password in Tools > MCP.
 *
 * Its own file, not the read guard: the door guards must not import each other,
 * and the cross-door check below reads the write door's option by name.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_READ_BOUND_UUID_OPTION = 'sn_mcp_read_app_password_uuid';

/**
 * The read-bound Application Password UUID, or '' when none is bound.
 *
 * @return string
 */
function sn_mcp_read_bound_uuid() {
	$uuid = get_option( SN_MCP_READ_BOUND_UUID_OPTION, '' );
	return is_string( $uuid ) ? $uuid : '';
}

/**
 * Set (or clear, with '') the read-bound UUID. Refuses a malformed UUID, and
 * one already bound to the write door: each door's scope refuses the other
 * door's routes, so one password bound to both would be refused on both.
 *
 * @param string $uuid
 * @return bool False when refused (option untouched).
 */
function sn_mcp_set_read_bound_uuid( $uuid ) {
	$uuid = strtolower( trim( (string) $uuid ) );
	if ( '' === $uuid ) {
		return update_option( SN_MCP_READ_BOUND_UUID_OPTION, '' );
	}
	if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) ) {
		return false;
	}
	if ( hash_equals( strtolower( (string) get_option( 'sn_mcp_rw_app_password_uuid', '' ) ), $uuid ) ) {
		return false;
	}
	return update_option( SN_MCP_READ_BOUND_UUID_OPTION, $uuid );
}
