<?php
/**
 * Signal & Noise: MCP telemetry READ, the helpers split from
 * mcp-telemetry-read.php (house line cap): whether the table exists, the
 * one name every door's spelling folds onto, the table's first row, which
 * dates the window, and the closed-door gate, which keeps a zero nobody
 * could have produced from reading as a retirement candidate.
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does the telemetry table physically exist?
 *
 * Absent table and empty table are different answers and must stay that way:
 * "the sensor is not installed" is not "the sensor recorded nothing", and
 * neither is "these tools went unused".
 *
 * @return bool
 */
function sn_mcp_telemetry_table_exists() {
	global $wpdb;
	$table = $wpdb->prefix . SN_MCP_TELEMETRY_TABLE;
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return (string) $found === $table;
}

/**
 * One allowlisted ability, one name, at READ time (the log is append-only
 * evidence, never rewritten): the MCP doors' `signal-noise__x`, the direct
 * door's slug `signal-noise/x` and a bare `x` fold onto the projected name.
 * Only EXPECTED tools fold; a theme tool or a blank stays as recorded.
 *
 * @param string               $name     tool_name as recorded.
 * @param array<string,string> $expected slug => projected name.
 * @return string
 */
function sn_mcp_telemetry_canonical_tool( $name, $expected ) {
	foreach ( $expected as $slug => $projected ) {
		if ( in_array( (string) $name, array( $projected, (string) $slug, substr( (string) strrchr( '/' . $slug, '/' ), 1 ) ), true ) ) {
			return $projected;
		}
	}
	return (string) $name;
}

/**
 * The table's first row (`MIN(ts)` with no window), or `$fallback` when that
 * read fails or answers nothing a date can be parsed from.
 *
 * @param string $table    Prefixed table name.
 * @param string $fallback The earliest row inside the window.
 * @return string
 */
function sn_mcp_telemetry_first_ts( $table, $fallback ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- $table is $wpdb->prefix + a plugin constant; no value is bound.
	$first = $wpdb->get_var( "SELECT MIN(ts) FROM {$table}" );
	return ( is_string( $first ) && false !== strtotime( $first . ' UTC' ) && $first < $fallback ) ? $first : $fallback;
}

/**
 * Why no caller could reach a door NOW, in plain words, or '' when the door
 * is open or its state cannot be read here (2026-10-12 audit ruling: unknown
 * is never painted as a verdict). The read door is closed while it is off;
 * the remote door while it is switched off or has no bridge secret; the write
 * door while it is off or unbound. Zero calls behind a closed door is "not
 * measured", never a retirement candidate.
 *
 * The state is read as it is now, not over the window: a door switched off
 * yesterday may have been open for most of it, so the sentence says "now"
 * and claims no more (review on #2022). Which door a slug is on is decided
 * by the caller (sn_mcp_telemetry_slug_door()), the one file that reads the
 * door lists.
 *
 * @param string $door read, rw or remote.
 * @return string
 */
function sn_mcp_telemetry_door_closed( $door ) {
	switch ( (string) $door ) {
		case 'read':
			return function_exists( 'sn_mcp_read_kill_switch_engaged' ) && sn_mcp_read_kill_switch_engaged()
				? __( 'the read door is off now', 'signal-and-noise-tools' )
				: '';
		case 'remote':
			if ( function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && sn_mcp_remote_kill_switch_engaged() ) {
				return __( 'the remote door is off now', 'signal-and-noise-tools' );
			}
			return function_exists( 'sn_bridge_secret' ) && '' === sn_bridge_secret()
				? __( 'the remote door has no bridge secret now', 'signal-and-noise-tools' )
				: '';
		case 'rw':
			if ( function_exists( 'sn_mcp_rw_kill_switch_engaged' ) && sn_mcp_rw_kill_switch_engaged() ) {
				return __( 'the write door is off now', 'signal-and-noise-tools' );
			}
			return function_exists( 'sn_mcp_rw_bound_uuid' ) && '' === sn_mcp_rw_bound_uuid()
				? __( 'the write door is unbound now', 'signal-and-noise-tools' )
				: '';
	}
	return '';
}
