<?php
/**
 * S&N Dashboard: AI › Agent tools, the MCP call log.
 *
 * Read top-down like the rest of the dashboard: three tiles (door calls, tools
 * called, tools with none), the window, one finding built from the log, the
 * ten busiest door tools as a capped ledger, the door split, then the tools no
 * door call reached, counted by verdict. 2026-10-12 audit: the ledger and the
 * tiles read only the tools a door lists today (a retired tool and the
 * plugin's own polling are one sentence), the doors carry their screen names,
 * times read in the site's format, and the uncalled tools are marked on their
 * own entries in the door lists instead of a second section repeating them.
 * The words are the classic block's (inc/admin-forms/mcp-usage-block.php).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Through MCP section's body: the call log, or the sentence that says
 * why there is none.
 *
 * @param array|null $usage From sn_mcp_telemetry_usage(), read once per paint by the caller.
 * @return string
 */
function agent_tools_usage_html( $usage ) {
	$hint = static fn( $text ) => '<p class="snt-hint">' . \snt_kit_esc( $text ) . '</p>';
	if ( ! function_exists( 'sn_mcp_telemetry_usage' ) || ! function_exists( 'sn_admin_mcp_usage_rows' ) ) {
		return $hint( __( 'Tool usage is not measured here: the MCP call log is not loaded on this site.', 'signal-and-noise-tools' ) );
	}
	if ( null === $usage ) {
		return $hint(
			function_exists( 'sn_mcp_telemetry_table_exists' ) && sn_mcp_telemetry_table_exists()
				? __( 'The call log exists but could not be read: a database error, not an absence of calls. Nothing here should be treated as usage evidence.', 'signal-and-noise-tools' )
				: __( 'The call log table is not installed yet. It is created on the first MCP call; until then there is no usage evidence either way.', 'signal-and-noise-tools' )
		);
	}
	if ( null === ( $usage['measured_since'] ?? null ) ) {
		return $hint( __( 'The call log is installed and has recorded nothing. That is not the same as these tools going unused: no call has been made through any door yet.', 'signal-and-noise-tools' ) );
	}
	$rows    = sn_admin_mcp_usage_rows( $usage );
	$zero    = (array) ( $usage['zero_call'] ?? array() );
	$partial = sn_admin_mcp_usage_partial( $usage );
	$columns = array(
		array( 'key' => 'tool', 'label' => __( 'Tool', 'signal-and-noise-tools' ) ),
		array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
		array( 'key' => 'doors', 'label' => __( 'Doors', 'signal-and-noise-tools' ) ),
	);
	$first = '';
	foreach ( $zero as $entry ) {
		if ( 'first_party_only' === (string) ( $entry['verdict'] ?? '' ) ) {
			$first .= '<p class="snt-hint">' . \snt_kit_code( (string) ( $entry['slug'] ?? '' ), false ) . ': ' . \snt_kit_esc( sn_admin_mcp_usage_first_party_label( $entry ) ) . '.</p>';
		}
	}
	return agent_tools_usage_tiles( $usage, $rows, $zero )
		. ( '' !== $partial ? $hint( $partial ) : '' )
		/* translators: %s: tools not listed. */
		. \snt_kit_ledger( sn_admin_mcp_usage_finding( $usage ), $columns, $rows, SN_MCP_USAGE_LEDGER_CAP, array( 'more' => __( '+%s more tools, busiest first; each door\'s list carries every tool\'s count.', 'signal-and-noise-tools' ) ) )
		. $hint( sn_admin_mcp_usage_door_split( $usage ) )
		. '<p class="snt-prose">' . \snt_kit_esc( sn_admin_mcp_usage_zero_finding( $zero ) ) . '</p>'
		. $first
		. ( $zero ? $hint( sn_admin_mcp_usage_rule() ) : '' );
}

/**
 * The reading: door calls over the measured window, door tools called, door
 * tools with none (toned: a tool that cannot be projected is a bug, an unused
 * one is a retirement candidate).
 *
 * @param array<string,mixed> $usage From sn_mcp_telemetry_usage().
 * @param array<int,array>    $rows  The ledger rows (door tools with door calls).
 * @param array<int,array>    $zero  Door tools with no door calls.
 * @return string
 */
function agent_tools_usage_tiles( array $usage, array $rows, array $zero ) {
	$calls = array_sum( array_map( static fn( $r ) => (int) ( $r['door_calls'] ?? 0 ), sn_admin_mcp_usage_listed( $usage ) ) );
	$days  = (int) ( $usage['measured_days'] ?? 0 );
	$note  = sn_admin_mcp_usage_zero_note( $zero );
	return \snt_kit_grid(
		array(
			/* translators: 1: measured days, 2: window days. */
			\snt_kit_stat( number_format_i18n( $calls ), __( 'Calls through a door', 'signal-and-noise-tools' ), sprintf( _n( 'measured over %1$s day of a %2$s-day window', 'measured over %1$s days of a %2$s-day window', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ), number_format_i18n( (int) ( $usage['window_days'] ?? 0 ) ) ) ),
			\snt_kit_stat( number_format_i18n( count( $rows ) ), __( 'Tools called through a door', 'signal-and-noise-tools' ), __( 'at least once in the window', 'signal-and-noise-tools' ) ),
			\snt_kit_stat( number_format_i18n( count( $zero ) ), __( 'Tools with no door calls', 'signal-and-noise-tools' ), $note['text'], $note['kind'] ),
		),
		160,
		10
	);
}
