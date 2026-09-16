<?php
/**
 * Signal & Noise Tools — AI › Agent tools, classic (15.6.0). The same model
 * the native leaf paints (inc/agent-tools.php): on the page, through MCP,
 * through Copilot.
 *
 * @package SignalNoiseTools
 * @since 15.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** AI → Agent tools, classic. */
function sn_admin_render_agent_tools_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$m = sn_agent_tools_bridge_model();
	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">' . esc_html( sprintf( 'On the page, %s days', number_format_i18n( $m['days'] ) ) ) . '</h2>';
	echo '<p class="sn-field-helper">The WebMCP bridge every HTML page carries; the reader\'s own agent calls these.</p>';
	if ( ! $m['ok'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'The sensor could not be read (' . $m['error'] . '); the counts below are from no data.' ) . '</p></div>';
	}
	echo '<table class="widefat striped"><thead><tr><th>Tool</th><th>Answers</th><th>Calls</th><th>ok</th><th>absent</th><th>error</th></tr></thead><tbody>';
	foreach ( $m['rows'] as $r ) {
		echo '<tr><td class="sn-mono">' . esc_html( $r['tool'] . ( $r['registered'] ? '' : ' *' ) ) . '</td><td>' . esc_html( $r['what'] ) . '</td><td>' . esc_html( number_format_i18n( $r['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $r['ok'] ) ) . '</td><td>' . esc_html( number_format_i18n( $r['absent'] ) ) . '</td><td>' . esc_html( number_format_i18n( $r['error'] ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="sn-field-helper">' . esc_html( $m['caption'] ) . '</p>';
	echo '<h3 class="sn-fieldset-h">What the tools read</h3><table class="widefat striped"><tbody>';
	foreach ( $m['documents'] as $d ) {
		echo '<tr><td>' . esc_html( $d['label'] ) . '</td><td>' . esc_html( $d['value'] ) . '</td><td>' . ( $d['ok'] ? 'ok' : '<strong>missing</strong>' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';

	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Through MCP</h2>';
	echo '<p class="sn-field-helper">The native read and write doors and the adapter, each behind an Application Password; the call log records every call through them.</p>';
	if ( function_exists( 'sn_admin_render_mcp_usage' ) ) {
		sn_admin_render_mcp_usage();
	}
	echo '<details class="sn-disclosure"><summary>The doors, and what each exposes</summary>';
	sn_admin_render_mcp_door_native();
	sn_admin_render_mcp_door_native_write();
	sn_admin_render_mcp_resources_prompts();
	sn_admin_render_mcp_door_adapter();
	echo '</details></div>';

	echo '<div class="sn-fieldset sn-fieldset--wide"><h2 class="sn-fieldset-h">Through Copilot</h2>';
	echo '<p class="sn-field-helper">Desktop Mode’s Ask AI, the admin’s own agent, and the tools it reached for.</p>';
	if ( function_exists( 'snt_ai_tool_invocations_render' ) ) {
		snt_ai_tool_invocations_render();
	}
	echo '</div>';
}
