<?php
/**
 * S&N Dashboard: AI › MCP Clients, how to connect a client.
 *
 * 2026-10-12 redesign: one section. The credential first (an Application
 * Password, the only grant a client gets), the two door addresses a client
 * points at, then Claude Code at full width, and the Claude desktop app's
 * steps beside the config they paste. It was two sections that each pointed at
 * the other ("the proxy config above", "Door 1 above") with names no card used.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Connect a client.
 *
 * @param array $state The status state (read_url, rw_url); [] when unreadable.
 * @return string
 */
function mcp_connect_owner_steps_html( array $state = array() ) {
	$native_url  = (string) ( $state['read_url'] ?? ( ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? rest_url( sn_mcp_namespace() . '/mcp' ) : '' ) );
	$rw_url      = (string) ( $state['rw_url'] ?? '' );
	$profile_url = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';

	$lead = '<p class="snt-prose">' . sprintf( /* translators: %s: a door to the Application Passwords section. */ \snt_kit_esc( __( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ) ), \snt_kit_door( __( 'Application Password', 'signal-and-noise-tools' ), $profile_url ) )
		. ' ' . \snt_kit_esc( __( 'Use the read door’s address for a client that only reads, and the write door’s for one you trust to change things.', 'signal-and-noise-tools' ) ) . '</p>';
	$addresses = \snt_kit_kv( array_filter( array(
		array( 'label' => __( 'Read door', 'signal-and-noise-tools' ), 'value' => \snt_kit_code( $native_url, false ), 'html' => true ),
		'' !== $rw_url ? array( 'label' => __( 'Write door', 'signal-and-noise-tools' ), 'value' => \snt_kit_code( $rw_url, false ), 'html' => true ) : null,
	) ) );

	// Claude Code takes the full width (its one command fits on one line); the
	// desktop app's steps sit beside the config they paste. No step says
	// "above" or "below": on a phone the pair stacks and either word would lie.
	// Each client is a sub-heading, so a screen reader can move between them.
	$h    = static fn( $t ) => '<h4 class="snt-h">' . \snt_kit_esc( $t ) . '</h4>';
	$code = $h( __( 'Claude Code', 'signal-and-noise-tools' ) ) . '<p class="snt-prose">' . \snt_kit_esc( __( 'Claude Code connects straight over HTTP, with no config file:', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_code( 'claude mcp add --transport http signal-and-noise ' . $native_url . ' --header "Authorization: Basic <base64 admin-username:application-password>"', true );
	$app  = $h( __( 'Claude desktop app', 'signal-and-noise-tools' ) ) . mcp_connect_claude_app_steps();

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
	$paste = $h( __( '@automattic/mcp-wordpress-remote config', 'signal-and-noise-tools' ) ) . '<p class="snt-prose">' . \snt_kit_esc( __( 'Swap in your WordPress username and the Application Password you just created.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_code( $config, true );
	// Each half is one grid item: loose siblings would each take a track.
	$body = $lead . $addresses . $code . \snt_kit_grid( array( \snt_kit_stack( $app, 12 ), \snt_kit_stack( $paste, 12 ) ), 320, 24 );

	// An earned fold (inc/openstation-kit-fold.php): setup, done once per
	// client. Closed, it says what is inside; it opens itself while this user
	// has no Application Password, because then nothing can have connected.
	$summary = __( 'Claude Code, the Claude desktop app, or any client through @automattic/mcp-wordpress-remote, with both door addresses', 'signal-and-noise-tools' );
	$none    = class_exists( 'WP_Application_Passwords' ) && function_exists( 'get_current_user_id' ) && array() === (array) \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
	return \snt_kit_fold( __( 'Connect a client', 'signal-and-noise-tools' ), $summary, $body, $none );
}

/**
 * The Claude desktop app's steps (the file and its two paths as inline code),
 * then the explicit "do not use the OAuth connector flow here" close.
 *
 * @return string
 */
function mcp_connect_claude_app_steps() {
	$steps = \snt_kit_steps(
		array(
			\snt_kit_esc( __( 'Install Node.js if the machine does not have it: the config runs through npx.', 'signal-and-noise-tools' ) ),
			sprintf(
				/* translators: 1: the config filename, 2: macOS path, 3: Windows path, each as inline code. */
				\snt_kit_esc( __( 'Open the app’s local MCP config file, %1$s: macOS: %2$s · Windows: %3$s (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ) ),
				\snt_kit_code( 'claude_desktop_config.json', false ),
				\snt_kit_code( '~/Library/Application Support/Claude/', false ),
				\snt_kit_code( '%APPDATA%\\Claude\\', false )
			),
			\snt_kit_esc( __( 'Paste the config into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) ),
			\snt_kit_esc( __( 'Fully restart the Claude app (quit, not just close the window). The site’s tools appear in the tools menu of a new chat.', 'signal-and-noise-tools' ) ),
		)
	);
	return $steps . '<p class="snt-hint">' . \snt_kit_esc( __( 'Do not use Settings → Connectors → “Add custom connector” for this endpoint: that flow is for remote servers reached from Anthropic’s own infrastructure and only supports OAuth: an application password will not work there.', 'signal-and-noise-tools' ) ) . '</p>';
}
