<?php
/**
 * S&N Dashboard — AI › Agent tools (15.6.0): every tool an agent can call on
 * this site, by door, and whether it does. On the page, the WebMCP bridge
 * (its calls reported by browsers); through MCP, the doors' abilities with
 * their call log; through Copilot, Desktop Mode's Ask AI. The door
 * inventories and the two usage tables moved here from MCP Clients and the
 * retired Copilot Usage leaf; MCP Clients keeps how to connect.
 *
 * @package SignalNoiseTools
 * @since 15.6.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * On the page: the bridge's tools and outcomes, and what they read.
 *
 * @param array<string,mixed> $m sn_agent_tools_bridge_model().
 * @return string
 */
function agent_tools_bridge_html( array $m ) {
	$rows = array();
	foreach ( $m['rows'] as $r ) {
		$rows[] = array(
			'tool'   => $r['tool'] . ( $r['registered'] ? '' : ' *' ),
			'what'   => $r['what'],
			'calls'  => number_format_i18n( $r['calls'] ),
			'ok'     => number_format_i18n( $r['ok'] ),
			'absent' => number_format_i18n( $r['absent'] ),
			'error'  => number_format_i18n( $r['error'] ),
		);
	}
	$table = \snt_kit_table(
		array(
			array( 'key' => 'tool', 'label' => __( 'Tool', 'signal-and-noise-tools' ) ),
			array( 'key' => 'what', 'label' => __( 'Answers', 'signal-and-noise-tools' ) ),
			array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'ok', 'label' => __( 'ok', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'absent', 'label' => __( 'absent', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'error', 'label' => __( 'error', 'signal-and-noise-tools' ), 'align' => 'end' ),
		),
		$rows
	);
	$left = ( $m['ok'] ? '' : \snt_kit_notice( 'warning', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'The sensor could not be read (%s); the counts below are from no data.', 'signal-and-noise-tools' ), $m['error'] ) ) ) )
		. $table
		. '<p class="snt-hint">' . \snt_kit_esc( $m['caption'] ) . '</p>';
	$docs = array();
	foreach ( $m['documents'] as $d ) {
		$docs[] = array( 'label' => $d['label'], 'value' => $d['value'], 'dot' => $d['ok'] ? 'ok' : 'err', 'tone' => $d['ok'] ? '' : 'warn' );
	}
	$right = \snt_kit_list( $docs );
	return \snt_kit_grid(
		array(
			\snt_kit_section( sprintf( /* translators: %s: days. */ __( 'On the page, %s days', 'signal-and-noise-tools' ), number_format_i18n( $m['days'] ) ), $left, __( 'The WebMCP bridge every HTML page carries; the reader\'s own agent calls these.', 'signal-and-noise-tools' ) ),
			\snt_kit_section( __( 'What the tools read', 'signal-and-noise-tools' ), $right, __( 'Public documents, built here; a tool answers "absent" site-wide when one is missing.', 'signal-and-noise-tools' ) ),
		),
		290,
		24
	);
}

/**
 * Through MCP: the doors' abilities and the call log, the parts MCP Clients
 * painted until 15.6.0 (ai-mcp-connect-parts.php).
 *
 * @return string
 */
function agent_tools_mcp_html() {
	// The call log open, the point of the leaf; the four doors folded under it,
	// paired as before (the 2026-09-10 measurement), because what each door
	// exposes is reference a reader opens once.
	$usage = function_exists( __NAMESPACE__ . '\\mcp_connect_usage_html' ) ? mcp_connect_usage_html( true ) : '';
	$doors = ( function_exists( __NAMESPACE__ . '\\mcp_connect_door_native_html' ) ? \snt_kit_grid( array( mcp_connect_door_native_html(), mcp_connect_door_native_write_html() ) ) : '' )
		. ( function_exists( __NAMESPACE__ . '\\mcp_connect_door_adapter_html' ) ? \snt_kit_grid( array( mcp_connect_door_adapter_html(), mcp_connect_resources_prompts_html() ) ) : '' );
	$fold  = '' !== $doors ? \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'The doors, and what each exposes', 'signal-and-noise-tools' ) ), $doors ) : '';
	return \snt_kit_section( __( 'Through MCP', 'signal-and-noise-tools' ), $usage . $fold, __( 'The native read and write doors and the adapter, each behind an Application Password; the call log records every call through them. Set up under MCP Clients.', 'signal-and-noise-tools' ) );
}

/**
 * Through Copilot: Desktop Mode's Ask AI, the retired Copilot Usage leaf's
 * whole content (ai-copilot-usage.php parts).
 *
 * @return string
 */
function agent_tools_copilot_html() {
	$parts  = function_exists( __NAMESPACE__ . '\\copilot_usage_data' ) && function_exists( __NAMESPACE__ . '\\copilot_usage_summary_html' ) && function_exists( __NAMESPACE__ . '\\copilot_usage_list_html' );
	$ranked = $parts ? \call_user_func( __NAMESPACE__ . '\\copilot_usage_data' ) : array( 'tools' => array(), 'calls' => 0, 'distinct' => 0 );
	if ( ! $parts || 0 === (int) $ranked['distinct'] ) {
		$inner = '<p class="snt-hint">' . \snt_kit_esc( __( 'No Ask AI tool calls recorded yet. Counts appear here once Desktop Mode’s Copilot has run (logging started in v9.60.0).', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$inner = \call_user_func( __NAMESPACE__ . '\\copilot_usage_summary_html', $ranked ) . \call_user_func( __NAMESPACE__ . '\\copilot_usage_list_html', $ranked['tools'] );
	}
	return \snt_kit_section( __( 'Through Copilot', 'signal-and-noise-tools' ), $inner, __( 'Desktop Mode’s Ask AI, the admin’s own agent, and the tools it reached for.', 'signal-and-noise-tools' ) );
}

/**
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_ai_agent_tools( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( 'sn_agent_tools_bridge_model' ) ) {
		return \snt_kit_empty( __( 'The Agent tools module is not loaded.', 'signal-and-noise-tools' ) );
	}
	return agent_tools_bridge_html( sn_agent_tools_bridge_model() ) . agent_tools_mcp_html() . agent_tools_copilot_html();
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['ai/agent-tools'] = __NAMESPACE__ . '\\paint_ai_agent_tools';
		return $painters;
	}
);
