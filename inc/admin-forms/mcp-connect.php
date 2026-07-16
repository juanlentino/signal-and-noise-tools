<?php
/**
 * Signal & Noise — Tools → Connect an MCP client.
 *
 * A read-only reference leaf (same anatomy as inc/admin-forms/links.php — no
 * form, no side effects, no save button) documenting the two MCP servers this
 * site can answer through and how to point an external client (Claude Code,
 * Claude Desktop, …) at one of them. All content is static + escaped at the
 * point of output (the inc/analytics-maturity-page.php idiom); the native
 * server's tool list is read LIVE from sn_mcp_allowlist() so this page can
 * never drift from what tools/list actually advertises.
 *
 * @package SignalNoiseTools
 * @since 9.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Connect an MCP client section body. Used as the
 * sn_admin_render_section() callback for the 'mcp-connect' sub-tab.
 */
function sn_admin_render_mcp_connect_section() {
	echo '<p>' . esc_html__( 'Two MCP servers can answer for this site. Both are read-only from an external client’s point of view — they can look, not write — and both sit behind your own WordPress login, never a shared secret.', 'signal-and-noise-tools' ) . '</p>';

	sn_admin_render_mcp_door_native();
	sn_admin_render_mcp_door_adapter();
	sn_admin_render_mcp_owner_steps();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Not the same as Connector Approvals', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Tools → Connector Approvals (if the AI plugin is active) gates OUTBOUND use of this site’s configured AI-provider connectors by server-side plugin and theme code — it decides which of your plugins may spend against your Anthropic, OpenAI, or Google key. It has nothing to do with an external MCP client connecting IN. That inbound grant is the Application Password below.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';

	sn_admin_render_mcp_deep_links();
}

/**
 * Door 1 — this plugin's own native JSON-RPC 2.0 server (since v9.22.0).
 * Slugs are read LIVE from sn_mcp_allowlist() (inc/mcp/mcp-capabilities.php,
 * the single source of truth) — never hardcoded here, so the list can't
 * silently drift from what tools/list actually advertises.
 */
function sn_admin_render_mcp_door_native() {
	$url   = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) )
		? (string) rest_url( sn_mcp_namespace() . '/mcp' )
		: '';
	$slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Door 1 — the native MCP server', 'signal-and-noise-tools' ) . ' <span class="sn-badge">' . esc_html__( 'since v9.22.0', 'signal-and-noise-tools' ) . '</span></p>';
	echo '<p>' . sprintf(
		/* translators: %s: the WordPress capability slug, wrapped in <code>. */
		esc_html__( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and the tool list below is the whole surface — nothing outside it is reachable, even by request.', 'signal-and-noise-tools' ),
		'<code>manage_options</code>'
	) . '</p>';
	echo '<p><code>POST ' . esc_url( $url ) . '</code> <span class="sn-badge">' . esc_html__( 'read-only', 'signal-and-noise-tools' ) . '</span></p>';
	echo '<p>' . sprintf(
		/* translators: %d: the live count of allowlisted read-only tools. */
		esc_html__( '%d read-only tools exposed:', 'signal-and-noise-tools' ),
		count( $slugs )
	) . '</p>';
	echo '<ul class="sn-mcp-tool-list">';
	foreach ( $slugs as $slug ) {
		echo '<li><code>' . esc_html( $slug ) . '</code></li>';
	}
	echo '</ul>';
	echo '</div>';
}

/**
 * Door 2 — the default server the wp.org "AI" plugin's MCP Adapter registers
 * over the whole Abilities registry (44+ abilities as of this writing), each
 * still gated by its own capability check. This plugin does not bundle the
 * adapter, so the block is conditionally honest: class_exists() is the
 * adapter's own documented detection seam (ground-truthed against
 * WordPress/mcp-adapter, not guessed) — live wording when it is detected,
 * hedged "if active" wording when it is not (the common case).
 */
function sn_admin_render_mcp_door_adapter() {
	$active = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	$url    = function_exists( 'rest_url' ) ? (string) rest_url( 'mcp/mcp-adapter-default-server' ) : '';

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Door 2 — the Abilities-registry adapter', 'signal-and-noise-tools' ) . '</p>';
	// v9.48.1: attribution corrected. The adapter is NOT part of the wp.org
	// "AI" plugin (ground-truthed 2026-07-15: that plugin's MCP integration is
	// roadmap-only, "coming soon"); it is a separate, GitHub-only WordPress
	// plugin (WordPress/mcp-adapter, pre-1.0, not on wordpress.org). The old
	// copy here taught the owner the wrong fact — never re-attribute it.
	if ( $active ) {
		echo '<p>' . esc_html__( 'The WordPress MCP Adapter plugin is active on this site and answers for the entire Abilities registry (44+ abilities across the theme and this plugin), each still gated by its own capability check. The adapter is its own plugin — the wp.org “AI” plugin does not bundle it.', 'signal-and-noise-tools' ) . '</p>';
		echo '<p><code>' . esc_url( $url ) . '</code></p>';
	} else {
		echo '<p>' . esc_html__( 'No MCP Adapter is installed on this site — Door 1 above is the only live MCP endpoint here. The adapter is a separate WordPress plugin (github.com/WordPress/mcp-adapter — pre-1.0, not on wordpress.org), and the wp.org “AI” plugin does not bundle it: that plugin lists MCP as coming soon. If the adapter is ever installed, its default server would answer at the address below.', 'signal-and-noise-tools' ) . '</p>';
		echo '<p><code>' . esc_url( $url ) . '</code></p>';
	}
	echo '</div>';
}

/**
 * The three owner steps + the one copy-paste client config. The proxy shape
 * is @automattic/mcp-wordpress-remote (most MCP clients still need a local
 * stdio bridge in front of a Basic-auth HTTP endpoint); the Claude Code
 * one-liner skips the proxy via `claude mcp add --transport http` (the exact
 * form already shipped in CHANGELOG.md v9.22.0). Placeholders only —
 * &lt;admin-username&gt; / &lt;application-password&gt; — never a real value.
 */
function sn_admin_render_mcp_owner_steps() {
	$native_url  = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) )
		? (string) rest_url( sn_mcp_namespace() . '/mcp' )
		: '';
	$profile_url = function_exists( 'get_edit_profile_url' )
		? get_edit_profile_url() . '#application-passwords-section'
		: '';

	echo '<h3>' . esc_html__( 'Connect a client', 'signal-and-noise-tools' ) . '</h3>';
	echo '<ol>';
	echo '<li>' . sprintf(
		/* translators: %s: a link to the current user’s Application Passwords section. */
		esc_html__( 'Create an %s under your own WordPress user — MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ),
		'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</a>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Copy the endpoint URL for whichever door you’re using — Door 1 above for the read-only tool allowlist, Door 2 for the full Abilities registry.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . esc_html__( 'Paste the client config below, swapping in your WordPress username and the Application Password you just created.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ol>';

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( '@automattic/mcp-wordpress-remote config', 'signal-and-noise-tools' ) . '</p>';
	echo '<pre>{
  "mcpServers": {
    "signal-and-noise": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "' . esc_url( $native_url ) . '",
        "WP_API_USERNAME": "&lt;admin-username&gt;",
        "WP_API_PASSWORD": "&lt;application-password&gt;"
      }
    }
  }
}</pre>';
	echo '<p>' . esc_html__( 'Claude Code can skip the proxy and connect straight over HTTP:', 'signal-and-noise-tools' ) . '</p>';
	echo '<p><code>claude mcp add --transport http signal-and-noise ' . esc_url( $native_url ) . ' --header "Authorization: Basic &lt;base64 admin-username:application-password&gt;"</code></p>';
	echo '</div>';

	sn_admin_render_mcp_claude_app();
}

/**
 * The Claude desktop app path (v9.49.0 — the owner connects from the app,
 * not the CLI). For a Basic-auth (application-password) endpoint the
 * officially documented app mechanism is the LOCAL config file
 * (claude_desktop_config.json) running the stdio proxy above; the app's
 * Settings → Connectors "Add custom connector" flow is REMOTE-only and
 * OAuth-only (the connection originates from Anthropic's servers), so an
 * application password can never work there — the section closes that
 * wrong door explicitly rather than letting the owner fight it.
 */
function sn_admin_render_mcp_claude_app() {
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Claude desktop app', 'signal-and-noise-tools' ) . '</p>';
	echo '<ol>';
	echo '<li>' . esc_html__( 'Install Node.js if the machine does not have it — the config below runs the proxy via npx.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . sprintf(
		/* translators: 1: the config filename, 2: macOS path, 3: Windows path. */
		esc_html__( 'Open the app’s local MCP config file, %1$s — macOS: %2$s · Windows: %3$s (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ),
		'<code>claude_desktop_config.json</code>',
		'<code>~/Library/Application Support/Claude/</code>',
		'<code>%APPDATA%\\Claude\\</code>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Paste the proxy config above into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . esc_html__( 'Fully restart the Claude app (quit, not just close the window). The site’s tools appear in the tools menu of a new chat.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ol>';
	echo '<p>' . esc_html__( 'Do not use Settings → Connectors → “Add custom connector” for this endpoint: that flow is for remote servers reached from Anthropic’s own infrastructure and only supports OAuth — an application password will not work there.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';
}

/**
 * Deep links: the Abilities Explorer (if the AI plugin registers one — its
 * page slug is not greppable anywhere in this repo, so this falls back to the
 * generic Tools menu rather than guessing a slug that could 404) and this
 * plugin's own Abilities catalog doc.
 */
function sn_admin_render_mcp_deep_links() {
	echo '<h3>' . esc_html__( 'More', 'signal-and-noise-tools' ) . '</h3>';
	echo '<ul>';
	echo '<li><a href="' . esc_url( admin_url( 'tools.php' ) ) . '">' . esc_html__( 'Tools menu — Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ) . '</a></li>';
	echo '<li><a href="' . esc_url( 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ) . '">' . esc_html__( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ) . '</a></li>';
	echo '</ul>';
}
