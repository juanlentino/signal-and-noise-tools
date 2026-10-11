<?php
/**
 * S&N Dashboard: AI › Agent tools, the MCP call log.
 *
 * Read top-down like the rest of the dashboard: three tiles (door calls, tools
 * called, tools with none), one finding built from the log, the busiest tools
 * as a capped ledger, then the tools with no door calls as their own section,
 * split by WHY. 2026-10-11: until then the finding sat in a hint line over the
 * whole log (owner rule: no collapsible sections, and not a plain hint over a
 * bare table either). The words are the classic block's
 * (inc/admin-forms/mcp-usage-block.php), read through its helpers.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

// Every called tool, as the classic block lists them (review on #2005: a cap of
// 10 left 15 tools' calls painted nowhere). The allowlist is about 70 tools.
const AGENT_TOOLS_USAGE_CAP = 100;

/**
 * The call log: the Through MCP section's body, and the section after it
 * that lists the tools with no door calls (when there are any).
 *
 * @return array{body:string,zero:string}
 */
function agent_tools_usage_html() {
	$hint = static function ( $text ) { return array( 'body' => '<p class="snt-hint">' . \snt_kit_esc( $text ) . '</p>', 'zero' => '' ); };
	if ( ! function_exists( 'sn_mcp_telemetry_usage' ) ) {
		return $hint( __( 'Tool usage is not measured here: the MCP call log is not loaded on this site.', 'signal-and-noise-tools' ) );
	}
	$usage = sn_mcp_telemetry_usage();
	if ( null === $usage ) {
		return $hint(
			function_exists( 'sn_mcp_telemetry_table_exists' ) && sn_mcp_telemetry_table_exists()
				? __( 'The call log exists but could not be read: a database error, not an absence of calls. Nothing here should be treated as usage evidence.', 'signal-and-noise-tools' )
				: __( 'The call log table is not installed yet. It is created on the first MCP call; until then there is no usage evidence either way.', 'signal-and-noise-tools' )
		);
	}
	if ( null === ( $usage['measured_since'] ?? null ) ) {
		return $hint( __( 'The call log is installed and has recorded nothing. That is not the same as these tools going unused: no call has been made through either door yet.', 'signal-and-noise-tools' ) );
	}

	$by_tool = (array) ( $usage['by_tool'] ?? array() );
	$zero    = (array) ( $usage['zero_call'] ?? array() );
	// Door calls lead, since that is the column; the polls only break ties.
	uasort( $by_tool, static function ( $a, $b ) { return ( (int) ( $b['door_calls'] ?? 0 ) <=> (int) ( $a['door_calls'] ?? 0 ) ) ?: ( (int) ( $b['calls'] ?? 0 ) <=> (int) ( $a['calls'] ?? 0 ) ); } );
	$partial = empty( $usage['complete'] )
		? '<p class="snt-hint">' . sprintf( /* translators: 1: first recorded date, 2: window days. */ \snt_kit_esc( __( 'Partial window. Recording began %1$s, so this covers less than the full %2$d days asked for. A tool with no calls here may simply predate the sensor.', 'signal-and-noise-tools' ) ), \snt_kit_esc( (string) $usage['measured_since'] ), (int) ( $usage['window_days'] ?? 0 ) ) . '</p>'
		: '';
	$split   = function_exists( 'sn_admin_mcp_usage_door_split' ) ? '<p class="snt-hint">' . \snt_kit_esc( sn_admin_mcp_usage_door_split( $usage ) ) . '</p>' : '';
	$all     = empty( $zero ) ? '<p class="snt-hint">' . \snt_kit_esc( __( 'Every allowlisted tool was called at least once in this window.', 'signal-and-noise-tools' ) ) . '</p>' : '';
	return array(
		'body' => agent_tools_usage_tiles( $usage, $by_tool, $zero ) . $partial . agent_tools_usage_ledger( $usage, $by_tool ) . $split . $all,
		'zero' => agent_tools_zero_html( $zero ),
	);
}

/**
 * The reading: door calls over the measured window, tools called, tools with
 * none (toned: a tool that cannot be projected is a bug, an unused one is a
 * retirement candidate).
 *
 * @param array<string,mixed> $usage   From sn_mcp_telemetry_usage().
 * @param array<string,array> $by_tool Per tool.
 * @param array<int,array>    $zero    Tools with no door calls.
 * @return string
 */
function agent_tools_usage_tiles( array $usage, array $by_tool, array $zero ) {
	$calls  = array_sum( array_map( static function ( $r ) { return (int) ( $r['door_calls'] ?? 0 ); }, $by_tool ) );
	$called = count( array_filter( $by_tool, static function ( $r ) { return (int) ( $r['door_calls'] ?? 0 ) > 0; } ) );
	$days   = (int) ( $usage['measured_days'] ?? 0 );
	$counts = array_count_values( array_map( static function ( $e ) { return (string) ( $e['verdict'] ?? '' ); }, $zero ) );
	$bad    = (int) ( $counts['unreachable'] ?? 0 );
	$unused = (int) ( $counts['unused'] ?? 0 );
	/* translators: %s: tool count. */
	$note   = $bad > 0 ? sprintf( _n( '%s cannot be projected', '%s cannot be projected', $bad, 'signal-and-noise-tools' ), number_format_i18n( $bad ) ) : ( $unused > 0 ? sprintf( _n( '%s retirement candidate', '%s retirement candidates', $unused, 'signal-and-noise-tools' ), number_format_i18n( $unused ) ) : '' );
	return \snt_kit_grid(
		array(
			/* translators: 1: measured days, 2: window days. */
			\snt_kit_stat( number_format_i18n( $calls ), __( 'Calls through a door', 'signal-and-noise-tools' ), sprintf( _n( 'measured over %1$s day of a %2$s-day window', 'measured over %1$s days of a %2$s-day window', $days, 'signal-and-noise-tools' ), number_format_i18n( $days ), number_format_i18n( (int) ( $usage['window_days'] ?? 0 ) ) ) ),
			\snt_kit_stat( number_format_i18n( $called ), __( 'Tools called through a door', 'signal-and-noise-tools' ), __( 'at least once in the window', 'signal-and-noise-tools' ) ),
			\snt_kit_stat( number_format_i18n( count( $zero ) ), __( 'Tools with no door calls', 'signal-and-noise-tools' ), $note, $bad > 0 ? 'err' : ( $unused > 0 ? 'warn' : 'ok' ) ),
		),
		160,
		10
	);
}

/**
 * The finding and the busiest tools, capped.
 *
 * @param array<string,mixed> $usage   From sn_mcp_telemetry_usage().
 * @param array<string,array> $by_tool Per tool, busiest first.
 * @return string
 */
function agent_tools_usage_ledger( array $usage, array $by_tool ) {
	$calls = array_sum( array_map( static function ( $r ) { return (int) ( $r['door_calls'] ?? 0 ); }, $by_tool ) );
	$since = substr( (string) $usage['measured_since'], 0, 10 );
	$top   = (string) array_key_first( $by_tool );
	/* translators: 1: door calls, 2: first recorded date, 3: busiest tool, 4: its share in percent. */
	$finding = $calls > 0
		? sprintf( _n( '%1$s call came through a door since %2$s; %3$s took %4$s%% of them.', '%1$s calls came through a door since %2$s; %3$s took %4$s%% of them.', $calls, 'signal-and-noise-tools' ), number_format_i18n( $calls ), $since, (string) preg_replace( '#^signal-noise(__|/)#', '', $top ), number_format_i18n( round( 100 * (int) $by_tool[ $top ]['door_calls'] / $calls ) ) )
		/* translators: %s: first recorded date. */
		: sprintf( __( 'No call came through a door since %s.', 'signal-and-noise-tools' ), $since );
	$rows = array();
	foreach ( $by_tool as $name => $row ) {
		$rows[] = array(
			'tool'      => (string) $name,
			'calls'     => function_exists( 'sn_admin_mcp_usage_calls_cell' ) ? sn_admin_mcp_usage_calls_cell( $row ) : number_format_i18n( (int) ( $row['door_calls'] ?? 0 ) ),
			'last_seen' => (string) ( $row['last_seen'] ?? __( 'not recorded', 'signal-and-noise-tools' ) ),
			'doors'     => implode( ', ', (array) ( $row['doors'] ?? array() ) ),
		);
	}
	$columns = array(
		array( 'key' => 'tool', 'label' => __( 'Tool', 'signal-and-noise-tools' ) ),
		array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ),
		array( 'key' => 'doors', 'label' => __( 'Doors', 'signal-and-noise-tools' ) ),
	);
	/* translators: %s: tools not listed. */
	return \snt_kit_ledger( $finding, $columns, $rows, AGENT_TOOLS_USAGE_CAP, array( 'more' => __( '+%s more tools, busiest first; the tail is the quietest.', 'signal-and-noise-tools' ) ) );
}
