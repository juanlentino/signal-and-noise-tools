<?php
/**
 * S&N Dashboard: AI › Agent tools, the adapter door and the resources, and
 * the doors in leaf order.
 *
 * The two native doors stand alone at full width (their tool lists are tall,
 * ai-agent-tools-doors.php); the adapter and the resources are two short prose
 * boxes of matching height, so they share a row. Moved from
 * ai-mcp-connect-parts.php on 2026-10-11, the words unchanged.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Every door, open, in order: read, write, then the adapter beside the
 * resources and prompts both native doors serve.
 *
 * @return string
 */
function agent_tools_doors_html() {
	$slugs = mcp_connect_door_slugs();
	return agent_tools_door_native_html( $slugs['read'] )
		. agent_tools_door_native_write_html( $slugs['rw'], $slugs['read'] )
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
	return \snt_kit_section( __( 'Resources & prompts', 'signal-and-noise-tools' ), $body, __( 'since v9.50.0', 'signal-and-noise-tools' ) );
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

	$body = $active
		? '<p class="snt-prose">' . \snt_kit_esc( __( 'The WordPress MCP Adapter plugin is active on this site. Since adapter 0.6.0 its default server carries only abilities that opt in through meta.mcp.public (falling back to meta.public); the abilities on Door 1’s read allowlist opt in and every other registration states meta.mcp.public false, so this door carries the same read set Door 1 does. Each exposed ability is still gated by its own capability check. The adapter is its own plugin: the wp.org “AI” plugin does not bundle it.', 'signal-and-noise-tools' ) ) . '</p>'
		: '<p class="snt-prose">' . \snt_kit_esc( __( 'No MCP Adapter is installed on this site (Door 1 above is the only live MCP endpoint here. The adapter is a separate WordPress plugin (wordpress.org/plugins/mcp-adapter, listed there since 0.7.0 and still pre-1.0), and the wp.org “AI” plugin does not bundle it: that plugin lists MCP as coming soon. If the adapter is ever installed, its default server would answer at the address below, and since adapter 0.6.0 it would carry only abilities that opt in through meta.mcp.public; the abilities on Door 1’s read allowlist opt in and every other registration states false, so it would carry the same read set Door 1 does.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= '<p>' . \snt_kit_code( $url, false ) . '</p>';

	return \snt_kit_section( __( 'Door 2: the Abilities-registry adapter', 'signal-and-noise-tools' ), $body );
}
