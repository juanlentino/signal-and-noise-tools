<?php
/**
 * S&N Dashboard: AI › MCP Clients, the setup path: the three owner steps with
 * the copy-paste client configs, then the Claude desktop app steps as an open
 * section of their own. Moved from ai-mcp-connect-parts.php on 2026-10-11, when
 * the desktop-app steps came out of their fold (owner rule: no collapsible
 * sections).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The three owner steps + the copy-paste client config (proxy config, and
 * the Claude Code one-liner). The Claude desktop app steps follow as their
 * own section (mcp_connect_claude_app_html()).
 *
 * @return string
 */
function mcp_connect_owner_steps_html() {
	$native_url  = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$profile_url = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';

	$steps = \snt_kit_steps(
		array(
			sprintf( /* translators: %s: a door to the Application Passwords section. */ \snt_kit_esc( __( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ) ), \snt_kit_door( __( 'Application Password', 'signal-and-noise-tools' ), $profile_url ) ),
			\snt_kit_esc( __( 'Copy the endpoint URL for whichever door you’re using. Door 1 above for the read-only tool allowlist, Door 2 for abilities that opt in to the adapter (that is Door 1’s read allowlist).', 'signal-and-noise-tools' ) ),
			\snt_kit_esc( __( 'Paste the client config below, swapping in your WordPress username and the Application Password you just created.', 'signal-and-noise-tools' ) ),
		)
	);

	$config = '{
  "mcpServers": {
    "signal-and-noise": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "' . $native_url . '",
        "WP_API_USERNAME": "<admin-username>",
        "WP_API_PASSWORD": "<application-password>"
      }
    }
  }
}';
	$body = $steps
		. '<p class="snt-prose"><b>' . \snt_kit_esc( __( '@automattic/mcp-wordpress-remote config', 'signal-and-noise-tools' ) ) . '</b></p>'
		. \snt_kit_code( $config, true )
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Claude Code can skip the proxy and connect straight over HTTP:', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_code( 'claude mcp add --transport http signal-and-noise ' . $native_url . ' --header "Authorization: Basic <base64 admin-username:application-password>"', false );

	return \snt_kit_section( __( 'Connect a client', 'signal-and-noise-tools' ), $body );
}

/**
 * The Claude desktop app path, an open section of its own after the config it
 * pastes: the four local-config setup steps (the file and its two paths as
 * inline code, as the classic leaf paints them), then the explicit "do not use
 * the OAuth connector flow here" close. 2026-10-11: these were a fold inside
 * Connect a client (owner rule: no collapsible sections).
 *
 * @return string
 */
function mcp_connect_claude_app_html() {
	$steps = \snt_kit_steps(
		array(
			\snt_kit_esc( __( 'Install Node.js if the machine does not have it: the proxy config above runs through npx.', 'signal-and-noise-tools' ) ),
			sprintf(
				/* translators: 1: the config filename, 2: macOS path, 3: Windows path, each as inline code. */
				\snt_kit_esc( __( 'Open the app’s local MCP config file, %1$s: macOS: %2$s · Windows: %3$s (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ) ),
				\snt_kit_code( 'claude_desktop_config.json', false ),
				\snt_kit_code( '~/Library/Application Support/Claude/', false ),
				\snt_kit_code( '%APPDATA%\\Claude\\', false )
			),
			\snt_kit_esc( __( 'Paste the proxy config above into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) ),
			\snt_kit_esc( __( 'Fully restart the Claude app (quit, not just close the window). The site’s tools appear in the tools menu of a new chat.', 'signal-and-noise-tools' ) ),
		)
	);
	$close = \snt_kit_notice( 'info', \snt_kit_esc( __( 'Do not use Settings → Connectors → “Add custom connector” for this endpoint: that flow is for remote servers reached from Anthropic’s own infrastructure and only supports OAuth: an application password will not work there.', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Claude desktop app', 'signal-and-noise-tools' ), $steps . $close );
}
