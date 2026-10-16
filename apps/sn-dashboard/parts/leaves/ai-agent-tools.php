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

// The call log, the doors (earned folds since 2026-10-12), and the allowlist
// reader they share with MCP Clients.
require_once __DIR__ . '/ai-mcp-connect-parts.php';
require_once __DIR__ . '/ai-agent-tools-usage.php';
require_once __DIR__ . '/ai-agent-tools-doors.php';
require_once __DIR__ . '/ai-agent-tools-adapter.php';
require_once __DIR__ . '/ai-agent-tools-refusals.php';

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
			'calls'  => \sn_agent_tools_count( $r['calls'] ),
			'ok'     => \sn_agent_tools_count( $r['ok'] ),
			'absent' => \sn_agent_tools_count( $r['absent'] ),
			'error'  => \sn_agent_tools_count( $r['error'] ),
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
	$left = ( $m['ok'] ? '' : \snt_kit_notice( 'warning', \snt_kit_esc( sprintf( /* translators: %s: reason. */ __( 'The sensor could not be read (%s), so no call counts are shown.', 'signal-and-noise-tools' ), $m['error'] ) ) ) )
		. $table
		. '<p class="snt-hint">' . \snt_kit_esc( $m['caption'] ) . '</p>';
	$docs = array();
	foreach ( $m['documents'] as $d ) {
		$docs[] = array( 'label' => $d['label'], 'value' => $d['value'], 'dot' => null === $d['ok'] ? 'unknown' : ( $d['ok'] ? 'ok' : 'warn' ), 'tone' => false === $d['ok'] ? 'warn' : '' );
	}
	// Live check on #2022: the bridge's reading (and when it was checked) was cut off with an ellipsis; these values wrap.
	$right = \snt_kit_list( $docs, array( 'class' => 'snt-list--wrap' ) );
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
 * Through MCP: the call log, then every door: the read, write and remote
 * doors as earned folds whose chips carry each tool's calls, the adapter
 * beside the resources. The log is read once here and handed to both.
 * Setting a client up stays on MCP Clients, one visible link away.
 *
 * @return string
 */
function agent_tools_mcp_html() {
	$usage = function_exists( 'sn_mcp_telemetry_usage' ) ? sn_mcp_telemetry_usage() : null;
	$setup = '<p>' . \snt_kit_go( __( 'Connect a client: MCP Clients', 'signal-and-noise-tools' ), array( 'sub' => 'mcp-connect' ) ) . '</p>';
	return \snt_kit_section( __( 'Through MCP', 'signal-and-noise-tools' ), agent_tools_usage_html( $usage ) . $setup, __( 'The read and write doors behind an Application Password, the remote door behind the bridge secret; the call log records every call through them.', 'signal-and-noise-tools' ) )
		. agent_tools_refusals_html()
		. agent_tools_doors_html( $usage );
}

/**
 * Through Copilot: Desktop Mode's Ask AI. Its log keeps a count per tool
 * since logging began, so the summary says since when, and the ten busiest
 * are chips (2026-10-12 audit: an uncapped, undated list beside a 30-day and
 * a 90-day reading).
 *
 * @return string
 */
function agent_tools_copilot_html() {
	$ranked = function_exists( 'snt_ai_tool_invocations_ranked' ) ? \snt_ai_tool_invocations_ranked() : array( 'tools' => array(), 'calls' => 0, 'distinct' => 0 );
	if ( 0 === (int) $ranked['distinct'] ) {
		$inner = '<p class="snt-hint">' . \snt_kit_esc( __( 'No Ask AI tool calls recorded yet. Counts appear here once Desktop Mode’s Copilot has run.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$chips = '';
		foreach ( array_slice( $ranked['tools'], 0, SN_AI_TOOL_INVOCATIONS_SHOWN ) as $tool ) {
			$chips .= \snt_kit_chip( (string) $tool['name'] . ' · ' . number_format_i18n( (int) $tool['n'] ) );
		}
		$inner = '<p class="snt-prose">' . sprintf( \snt_kit_esc( \snt_ai_tool_invocations_summary_format() ), '<strong>' . \snt_kit_esc( number_format_i18n( $ranked['calls'] ) ) . '</strong>', '<strong>' . \snt_kit_esc( number_format_i18n( $ranked['distinct'] ) ) . '</strong>', \snt_kit_esc( \snt_ai_tool_invocations_since( $ranked ) ) ) . '</p>'
			. \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $chips )
			/* translators: %s: tools not shown. */
			. \snt_kit_more( (int) $ranked['distinct'] - SN_AI_TOOL_INVOCATIONS_SHOWN, __( '+%s more tools, busiest first.', 'signal-and-noise-tools' ) );
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
