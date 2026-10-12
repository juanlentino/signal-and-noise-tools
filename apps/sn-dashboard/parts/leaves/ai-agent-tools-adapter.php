<?php
/**
 * S&N Dashboard: AI › Agent tools, the adapter door and the resources, and
 * the doors in leaf order.
 *
 * The three doors are folds at full width (their tool lists are tall,
 * ai-agent-tools-doors.php); the adapter and the resources are two short prose
 * boxes of matching height, so they share a row. The adapter's words are the
 * classic page's (sn_admin_mcp_adapter_sentence(), two sentences since the
 * 2026-10-12 audit).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Every door in order: the read, write and remote doors as earned folds, one
 * under the other (closed, each is one summary line; open, its chips want the
 * full width), then the adapter beside the resources and prompts both native
 * doors serve.
 *
 * @param array|null $usage From sn_mcp_telemetry_usage(), read once per paint.
 * @return string
 */
function agent_tools_doors_html( $usage = null ) {
	$slugs = mcp_connect_door_slugs();
	return agent_tools_door_native_html( $slugs['read'], $usage )
		. agent_tools_door_native_write_html( $slugs['rw'], $slugs['read'], $usage )
		. agent_tools_door_remote_html( $slugs['remote'], $usage )
		. \snt_kit_grid( array( agent_tools_door_adapter_html(), agent_tools_resources_prompts_html() ) );
}

/**
 * Resources & prompts, one sentence per category.
 *
 * @return string
 */
function agent_tools_resources_prompts_html() {
	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'Both native doors also serve four read-only MCP resources (sn://abilities-catalog, sn://changelog-latest, sn://design-tokens, and sn://llms-txt): a client can fetch any of them directly without calling a tool.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Two ready-made prompts, weekly-report and content-audit, chain several read tools together into one owner-voiced synthesis your client can run in a single step.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'Resources & prompts', 'signal-and-noise-tools' ), $body );
}

/**
 * Door 2, the wp.org MCP Adapter plugin, if installed. Detection is the
 * adapter's own documented seam (class_exists), never guessed.
 *
 * @return string
 */
function agent_tools_door_adapter_html() {
	$active = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	$url    = function_exists( 'rest_url' ) ? (string) rest_url( 'mcp/mcp-adapter-default-server' ) : '';

	$body = '<p class="snt-prose">' . \snt_kit_esc( function_exists( 'sn_admin_mcp_adapter_sentence' ) ? sn_admin_mcp_adapter_sentence( $active ) : '' ) . '</p>';
	$body .= '<p>' . \snt_kit_code( $url, false ) . '</p>';

	return \snt_kit_section( __( 'Core MCP adapter: the Abilities-registry door', 'signal-and-noise-tools' ), $body );
}
