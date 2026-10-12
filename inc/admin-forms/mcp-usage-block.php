<?php
/**
 * AI › Agent tools: the MCP call log, in the words both twins say.
 *
 * The readout half of inc/mcp/mcp-telemetry-read.php. The table it reads had
 * an install path, an insert path and a prune path, and no SELECT: evidence
 * accrued for the retirement gate and was deleted at 90 days unread. Shipping
 * the accessor without this block would have reproduced that exact shape.
 *
 * Every sentence the native leaf paints (ai-agent-tools-usage.php) comes from
 * a helper here, so the classic block and the leaf cannot drift. 2026-10-12
 * audit: the ledger counts only the tools on a door's list today (a retired
 * tool and the plugin's own polling are one sentence), names the doors as the
 * screen does, prints times in the site's format, and caps at ten rows; every
 * door tool's count is on its own entry in the door lists.
 *
 * @package Signal_And_Noise_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ledger rows painted before the "+N more" line, on both twins. */
const SN_MCP_USAGE_LEDGER_CAP = 10;

/**
 * A door key as the screen names it.
 *
 * @param string $door read, rw, remote, agent or direct.
 * @return string
 */
function sn_admin_mcp_door_name( $door ) {
	$names = array(
		'read'   => __( 'read door', 'signal-and-noise-tools' ),
		'rw'     => __( 'write door', 'signal-and-noise-tools' ),
		'remote' => __( 'remote door', 'signal-and-noise-tools' ),
		'agent'  => __( 'Desktop Mode agents', 'signal-and-noise-tools' ),
		'direct' => __( 'the plugin itself', 'signal-and-noise-tools' ),
	);
	return $names[ (string) $door ] ?? (string) $door;
}

/**
 * A logged UTC timestamp in the site's time zone and formats.
 *
 * @param string|null $sql_ts `Y-m-d H:i:s[.u]` in UTC, as the log stores it.
 * @param bool        $time   Include the time of day.
 * @return string
 */
function sn_admin_mcp_usage_when( $sql_ts, $time = true ) {
	$ts = ( is_string( $sql_ts ) && '' !== $sql_ts ) ? strtotime( $sql_ts . ' UTC' ) : false;
	if ( false === $ts ) {
		return __( 'not recorded', 'signal-and-noise-tools' );
	}
	$format = (string) get_option( 'date_format', 'F j, Y' ) . ( $time ? ' ' . (string) get_option( 'time_format', 'g:i a' ) : '' );
	return (string) wp_date( $format, $ts );
}

/**
 * A tool's name without the plugin's prefix.
 *
 * @param string $name Projected name or slug.
 * @return string
 */
function sn_admin_mcp_tool_short( $name ) {
	return (string) preg_replace( '#^signal-noise(__|/)#', '', (string) $name );
}

/**
 * The tools a door lists today that a call reached through a door, busiest
 * first (a retired tool keeps its calls in the log, never in the ledger).
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return array<string,array> Projected name => by_tool entry.
 */
function sn_admin_mcp_usage_listed( $usage ) {
	$rows = array_filter( (array) ( $usage['by_tool'] ?? array() ), static fn( $r ) => ! empty( $r['allowlisted'] ?? true ) && (int) ( $r['door_calls'] ?? 0 ) > 0 );
	uasort( $rows, static fn( $a, $b ) => ( (int) ( $b['door_calls'] ?? 0 ) <=> (int) ( $a['door_calls'] ?? 0 ) ) ?: ( (int) ( $b['calls'] ?? 0 ) <=> (int) ( $a['calls'] ?? 0 ) ) );
	return $rows;
}

/**
 * The finding over the ledger: door calls since the window's first day, and
 * the busiest tool's share.
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function sn_admin_mcp_usage_finding( $usage ) {
	$rows  = sn_admin_mcp_usage_listed( $usage );
	$calls = array_sum( array_map( static fn( $r ) => (int) ( $r['door_calls'] ?? 0 ), $rows ) );
	$since = sn_admin_mcp_usage_when( $usage['since'] ?? $usage['measured_since'] ?? null, false );
	if ( $calls <= 0 ) {
		/* translators: %s: the first day the window covers. */
		return sprintf( __( 'No call came through a door since %s.', 'signal-and-noise-tools' ), $since );
	}
	$top = (string) array_key_first( $rows );
	/* translators: 1: door calls, 2: the first day the window covers, 3: busiest tool, 4: its share in percent. */
	return sprintf( _n( '%1$s call came through a door since %2$s; %3$s took %4$s%% of them.', '%1$s calls came through a door since %2$s; %3$s took %4$s%% of them.', $calls, 'signal-and-noise-tools' ), number_format_i18n( $calls ), $since, sn_admin_mcp_tool_short( $top ), number_format_i18n( round( 100 * (int) $rows[ $top ]['door_calls'] / $calls ) ) );
}

/**
 * The partial-window sentence, '' when the window is complete.
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function sn_admin_mcp_usage_partial( $usage ) {
	if ( ! empty( $usage['complete'] ) ) {
		return '';
	}
	/* translators: 1: when recording began, 2: days measured, 3: window days. */
	return sprintf( __( 'Partial window. Recording began %1$s, so this covers %2$s of the %3$s days asked for. A tool with no calls here may simply predate the sensor.', 'signal-and-noise-tools' ), sn_admin_mcp_usage_when( $usage['recording_began'] ?? $usage['measured_since'] ?? null, false ), number_format_i18n( (int) ( $usage['measured_days'] ?? 0 ) ), number_format_i18n( (int) ( $usage['window_days'] ?? 0 ) ) );
}

/**
 * The door split, one paragraph: every door by its screen name, the
 * plugin's own polling, and the door calls to tools no door lists today
 * (retired, or reached only by Desktop Mode agents, which can call any
 * signal-noise/ ability).
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return string
 */
function sn_admin_mcp_usage_door_split( $usage ) {
	$by_door = (array) ( $usage['by_door'] ?? array() );
	$n       = static fn( $k ) => number_format_i18n( (int) ( $by_door[ $k ] ?? 0 ) );
	/* translators: 1: total calls, 2: read door, 3: write door, 4: remote door, 5: Desktop Mode agents, 6: the plugin's own surfaces. */
	$out     = sprintf( __( '%1$s calls in all: %2$s through the read door, %3$s the write door, %4$s the remote door, %5$s from Desktop Mode agents, and %6$s by the plugin\'s own surfaces. The total includes calls that never resolved to a tool.', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $usage['total_rows'] ?? 0 ) ), $n( 'read' ), $n( 'rw' ), $n( 'remote' ), $n( 'agent' ), $n( 'direct' ) );
	$retired = array_filter( (array) ( $usage['by_tool'] ?? array() ), static fn( $r ) => empty( $r['allowlisted'] ?? true ) && (int) ( $r['door_calls'] ?? 0 ) > 0 );
	if ( $retired ) {
		$calls = array_sum( array_map( static fn( $r ) => (int) $r['door_calls'], $retired ) );
		// Review on #2022: Desktop Mode agents reach any signal-noise/ ability,
		// listed on a door or not, so "retired" alone overclaimed.
		/* translators: 1: door calls, 2: tools. */
		$out  .= ' ' . sprintf( _n( '%1$s door call reached tools no door lists today (retired, or reached only by Desktop Mode agents: %2$s), so the ledger leaves it out.', '%1$s door calls reached tools no door lists today (retired, or reached only by Desktop Mode agents: %2$s), so the ledger leaves them out.', $calls, 'signal-and-noise-tools' ), number_format_i18n( $calls ), implode( ', ', array_map( 'sn_admin_mcp_tool_short', array_keys( $retired ) ) ) );
	}
	return $out;
}

/**
 * The Calls cell: door calls, with the direct count in parentheses when there
 * is one. Same words on the native leaf.
 *
 * @param array $row One by_tool entry.
 * @return string
 */
function sn_admin_mcp_usage_calls_cell( $row ) {
	$doors  = number_format_i18n( (int) ( $row['door_calls'] ?? 0 ) );
	$direct = (int) ( $row['direct_calls'] ?? 0 );
	if ( $direct <= 0 ) {
		return $doors;
	}
	/* translators: 1: calls through a door, 2: direct (first-party) calls. */
	return sprintf( __( '%1$s (%2$s direct)', 'signal-and-noise-tools' ), $doors, number_format_i18n( $direct ) );
}

/**
 * The ledger rows both twins paint: tool, calls, last seen, doors by name.
 *
 * @param array $usage From sn_mcp_telemetry_usage().
 * @return array<int,array<string,string>>
 */
function sn_admin_mcp_usage_rows( $usage ) {
	$out = array();
	foreach ( sn_admin_mcp_usage_listed( $usage ) as $name => $row ) {
		$out[] = array(
			'tool'      => sn_admin_mcp_tool_short( (string) $name ),
			'calls'     => sn_admin_mcp_usage_calls_cell( $row ),
			'last_seen' => sn_admin_mcp_usage_when( $row['last_seen'] ?? null ),
			'doors'     => implode( ', ', array_map( 'sn_admin_mcp_door_name', (array) ( $row['doors'] ?? array() ) ) ),
		);
	}
	return $out;
}

require_once __DIR__ . '/mcp-usage-block-parts.php'; // the zero-call words, the per-tool reading, the classic block.
