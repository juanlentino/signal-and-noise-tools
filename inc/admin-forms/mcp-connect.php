<?php
/**
 * Signal & Noise — Tools → Connect an MCP client.
 *
 * A mostly-read-only reference leaf (same anatomy as inc/admin-forms/links.php
 * — no side effects outside the one exception below) documenting the MCP
 * servers this site can answer through and how to point an external client
 * (Claude Code, Claude Desktop, …) at one of them. All content is static +
 * escaped at the point of output (the inc/analytics-maturity-page.php idiom);
 * every tool list and count on this page is read LIVE from the allowlist
 * functions (sn_mcp_allowlist(), sn_mcp_rw_allowlist()) so it can never drift
 * from what tools/list actually advertises on either door.
 *
 * v9.50.0: the native server grows a second door — /mcp-rw, gated by the same
 * manage_options + Application Password floor, exposing a read-write tool set
 * (sn_mcp_rw_allowlist(), owned by inc/mcp/mcp-capabilities.php — this file
 * never defines it, only calls it through a function_exists() guard, exactly
 * as it has always done for sn_mcp_allowlist()). This leaf is the one place
 * that must document, in the owner's own voice, that the write door is NOT
 * read-only: it can mutate content and it spends the site's AI budget, and it
 * names the four abilities withheld from it so that gap is a stated choice,
 * not something the owner has to go find in an audit doc.
 *
 * v9.51.0 (R9, lane SEC-C): the ONE deliberate exception to "no form" above —
 * sn_admin_render_mcp_rw_binding() adds the credential-binding form the write
 * door needs to ever leave its deny-closed default (inc/mcp/mcp-rw-guard.php's
 * R1: an unbound sn_mcp_rw_app_password_uuid option denies every /mcp-rw call,
 * by design). This is still the one place every other write-door fact lives,
 * so it is also where the owner turns the door on. Submits to
 * sn_handle_bind_mcp_rw_credential() (inc/admin-post-actions/mcp.php), which is
 * the ONLY function in this whole file's dependency chain that mutates
 * anything — see that handler's own docblock for the ownership check that
 * keeps a POSTed UUID from binding a credential this user doesn't hold.
 *
 * @package SignalNoiseTools
 * @since 9.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Required from here — not only from the plugin loader — so every load site of this file (tests included) stays whole.
require_once __DIR__ . '/mcp-connect-status.php';
require_once __DIR__ . '/mcp-connect-read-binding.php'; // 2026-10-26 audit N0: the read door's optional binding.

/**
 * Tools → Connect an MCP client section body. Used as the
 * sn_admin_render_section() callback for the 'mcp-connect' sub-tab.
 */
function sn_admin_render_mcp_connect_section() {
	echo '<p>' . esc_html__( 'Four MCP doors can answer for this site: the read and write doors this plugin serves, the remote door through the Cloudflare Worker, and the core MCP adapter when it is installed. The read and write doors sign in as you with an Application Password, never a shared secret: the read door can only look, the write door can also change things, so use whichever scope you mean to grant.', 'signal-and-noise-tools' ) . '</p>';

	sn_admin_render_mcp_status_glance();

	// M1 (IA): bind + connect sit above both tool-list doors so the returning
	// owner's job and the first-run job are reachable without scrolling past
	// the live slug inventories. No disclosure wrapping here — that is M2.
	sn_admin_render_mcp_read_binding();
	sn_admin_render_mcp_rw_binding();
	sn_admin_render_mcp_owner_steps();
	// 15.6.0: the door inventories and the call log moved to AI › Agent tools
	// (inc/agent-tools-admin.php); this leaf keeps how to connect.

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Not the same as Connector Approvals', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Tools → Connector Approvals (if the AI plugin is active) gates OUTBOUND use of this site’s configured AI-provider connectors by server-side plugin and theme code: it decides which of your plugins may spend against your Anthropic, OpenAI, or Google key. It has nothing to do with an external MCP client connecting IN. That inbound grant is the Application Password each client signs in with.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';

	sn_admin_render_mcp_deep_links();
}

/**
 * Door 1 — this plugin's own native JSON-RPC 2.0 server (since v9.22.0).
 * Slugs are read LIVE from sn_mcp_allowlist() (inc/mcp/mcp-capabilities.php,
 * the single source of truth) — never hardcoded here, so the list can't
 * silently drift from what tools/list actually advertises.
 */
function sn_admin_render_mcp_door_native( $usage = null ) {
	$url   = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) )
		? (string) rest_url( sn_mcp_namespace() . '/mcp' )
		: '';
	$slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Read door: the native MCP server', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . sprintf(
		/* translators: %s: the WordPress capability slug, wrapped in <code>. */
		esc_html__( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and this door’s tool list is the whole surface: nothing outside it is reachable, even by request. Binding an Application Password to it in Tools -> MCP is optional and limits that one password to this door and its tools.', 'signal-and-noise-tools' ),
		'<code>manage_options</code>'
	) . '</p>';
	echo '<p><code>POST ' . esc_url( $url ) . '</code> <span class="sn-badge">' . esc_html__( 'read-only', 'signal-and-noise-tools' ) . '</span></p>';
	// M2 (IA): the inventory folds. Every slug stays in the HTML (the render
	// suite iterates the live allowlist against it), but the wall of rows sits
	// behind a closed disclosure whose summary carries the LIVE count — the
	// same count() call, so the summary can never drift from tools/list.
	echo '<details class="sn-mcp-tools sn-disclosure"><summary>';
	printf(
		/* translators: %d: the live count of allowlisted read-only tools. */
		esc_html__( '%d read-only tools exposed: show the list', 'signal-and-noise-tools' ),
		count( $slugs )
	);
	echo '</summary>';
	sn_admin_render_mcp_tool_list( $slugs, $usage );
	echo '</details>';
	echo '<p>' . esc_html__( 'This door never mutates anything, by construction: the write door carries the actions.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';
}

/**
 * Door 1b — the native write door (new in v9.50.0): the same JSON-RPC 2.0
 * server, the same manage_options + Application Password floor, at a second
 * route — /mcp-rw. Slugs are read LIVE from sn_mcp_rw_allowlist()
 * (inc/mcp/mcp-capabilities.php, owned by a different lane — this file only
 * calls it through a function_exists() guard, never defines or edits it,
 * exactly like the read door treats sn_mcp_allowlist() above). Its tools/list
 * intentionally does not repeat the read door's tools (documented explicitly
 * below), and the honesty requirement here is non-negotiable: this door can
 * change content and spend AI budget, using the SAME credential as the read
 * door — say so plainly rather than letting "MCP door" read as uniformly
 * safe.
 */
function sn_admin_render_mcp_door_native_write( $usage = null ) {
	$url        = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) )
		? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' )
		: '';
	$rw_slugs   = function_exists( 'sn_mcp_rw_allowlist' ) ? sn_mcp_rw_allowlist() : array();
	$read_slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Write door: the same server, read-write', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . sprintf(
		/* translators: %s: the WordPress capability slug, wrapped in <code>. */
		esc_html__( 'The exact same JSON-RPC 2.0 server and the same %s capability as the read door, with its own Application Password, the one bound to this door in Tools -> MCP. That password works only on this door and its tools, so the read door needs a different one. What changes is the surface: most tools here can modify your content (posts, postmeta, taxonomy terms, scheduled cron events, cached files); some spend the AI budget configured on this site, and the Jev passes spend TypeSafe credit.', 'signal-and-noise-tools' ),
		'<code>manage_options</code>'
	) . '</p>';
	echo '<p><code>POST ' . esc_url( $url ) . '</code> <span class="sn-badge">' . esc_html__( 'read-write', 'signal-and-noise-tools' ) . '</span></p>';
	// M2 (IA): same fold as the read door. The withheld slugs stay INSIDE this
	// disclosure — they explain a gap in exactly this list, and folding them
	// anywhere else would orphan the explanation. Their count in the summary is
	// counted from the same data the renderer prints, never hardcoded.
	echo '<details class="sn-mcp-tools sn-disclosure"><summary>';
	printf(
		/* translators: 1: the live count of allowlisted read-write tools, 2: the withheld-ability count. */
		esc_html__( '%1$d read-write tools exposed · %2$d withheld: show the list', 'signal-and-noise-tools' ),
		count( $rw_slugs ),
		count( sn_admin_mcp_withheld_slug_data() )
	);
	echo '</summary>';
	sn_admin_render_mcp_tool_list( $rw_slugs, $usage );
	echo '<p>' . sprintf(
		/* translators: %d: the live count of read-only tools on the read door. */
		esc_html__( 'This door’s tools/list does not repeat the read door’s %d read-only tools: if you only want to look, connect to the read door instead.', 'signal-and-noise-tools' ),
		count( $read_slugs )
	) . '</p>';

	sn_admin_render_mcp_withheld_slugs();
	echo '</details>';
	echo '</div>';
}

/**
 * The four abilities the owner has deliberately kept off the write door —
 * named here so the gap is a stated choice, not something the owner has to
 * go find in an audit doc. run-cron-event is a hard, permanent exclusion
 * (unbounded do_action() dispatch on any hook, not just this plugin's own);
 * the other three are otherwise-qualifying candidates the owner HELD pending
 * an explicit future opt-in because each carries an unusually wide or
 * irreversible blast radius. Static content — these slugs never come from a
 * live allowlist call, precisely because they are absent from it.
 */
function sn_admin_mcp_withheld_slug_data() {
	return array(
		'signal-noise/run-cron-event'          => array(
			'badge'  => __( 'never', 'signal-and-noise-tools' ),
			'reason' => __( 'synchronously fires do_action() on any non-sn_* hook you name: an unbounded blast radius across the whole site, not just this plugin', 'signal-and-noise-tools' ),
		),
		'signal-noise/ai-orphan-apply'         => array(
			'badge'  => __( 'held', 'signal-and-noise-tools' ),
			'reason' => __( 'force-deletes an orphaned attachment and skips the trash: no undo', 'signal-and-noise-tools' ),
		),
		'signal-noise/merge-tags'              => array(
			'badge'  => __( 'held', 'signal-and-noise-tools' ),
			'reason' => __( 'reassigns and deletes taxonomy terms sitewide: a wide blast radius for one call', 'signal-and-noise-tools' ),
		),
		'signal-noise/clear-template-overrides' => array(
			'badge'  => __( 'held', 'signal-and-noise-tools' ),
			'reason' => __( 'deletes the active theme Site Editor template and part overrides: can regress the whole site design', 'signal-and-noise-tools' ),
		),
	);
}

/**
 * Render the withheld slugs + reasons. Reads sn_admin_mcp_withheld_slug_data()
 * — the same array the write-door disclosure summary counts (M2), so the
 * "· N withheld" in the summary and the list it opens onto cannot disagree.
 */
function sn_admin_render_mcp_withheld_slugs() {
	echo '<p>' . esc_html__( 'Four abilities never reach this door: one permanently, three pending an explicit future opt-in:', 'signal-and-noise-tools' ) . '</p>';
	echo '<ul class="sn-mcp-tool-list">';
	foreach ( sn_admin_mcp_withheld_slug_data() as $slug => $info ) {
		echo '<li><code>' . esc_html( $slug ) . '</code> <span class="sn-badge">' . esc_html( $info['badge'] ) . '</span>. ' . esc_html( $info['reason'] ) . '</li>';
	}
	echo '</ul>';
}

/**
 * Resources & prompts (v9.50.0, lane PROTO — inc/mcp/mcp-resources.php +
 * inc/mcp/mcp-prompts.php): both native doors serve the same read-only set.
 * One sentence per category, per the leaf's scope — the detailed shapes live
 * in the protocol lane's own code and tests, not here.
 */
function sn_admin_render_mcp_resources_prompts() {
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Resources & prompts', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Both native doors also serve four read-only MCP resources (sn://abilities-catalog, sn://changelog-latest, sn://design-tokens, and sn://llms-txt): a client can fetch any of them directly without calling a tool.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Two ready-made prompts, weekly-report and content-audit, chain several read tools together into one owner-voiced synthesis your client can run in a single step.', 'signal-and-noise-tools' ) . '</p>';
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
	echo '<p class="sn-callout-h">' . esc_html__( 'Core MCP adapter: the Abilities-registry door', 'signal-and-noise-tools' ) . '</p>';
	// v9.48.1: attribution corrected. The adapter is NOT part of the wp.org
	// "AI" plugin (ground-truthed 2026-07-15: that plugin's MCP integration is
	// roadmap-only, "coming soon"); it is a separate, GitHub-only WordPress
	// plugin (WordPress/mcp-adapter, pre-1.0, on wordpress.org since 0.7.0). The old
	// copy here taught the owner the wrong fact — never re-attribute it.
	echo '<p>' . esc_html( sn_admin_mcp_adapter_sentence( $active ) ) . '</p>';
	echo '<p><code>' . esc_url( $url ) . '</code></p>';
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
		esc_html__( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ),
		'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</a>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Use the read door’s address for a client that only reads, and the write door’s for one you trust to change things.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . esc_html__( 'Paste one of the configs. Swap in your WordPress username and the Application Password you just created.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ol>';

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( '@automattic/mcp-wordpress-remote config', 'signal-and-noise-tools' ) . '</p>';
	echo '<pre>{
  "mcpServers": {
    "signal-and-noise": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@0.5.4"],
      "env": {
        "WP_API_URL": "' . esc_url( $native_url ) . '",
        "WP_API_USERNAME": "&lt;admin-username&gt;",
        "WP_API_PASSWORD": "&lt;application-password&gt;"
      }
    }
  }
}</pre>';
	// 2026-10-26 (docs/audits/2026-10-26-mcp-doors.md, F6): never @latest.
	echo '<p>' . esc_html__( 'Pinned to an exact version on purpose: this package runs with your admin Application Password in its environment, so a launch must never fetch code you have not reviewed. Upgrade it deliberately, after reading what changed.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Claude Code connects straight over HTTP, with no config file:', 'signal-and-noise-tools' ) . '</p>';
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
	echo '<details class="sn-disclosure">';
	// The callout heading directly above already says "Claude desktop app", so
	// the summary names what is INSIDE rather than repeating it.
	echo '<summary>' . esc_html__( 'Show the 4 setup steps', 'signal-and-noise-tools' ) . '</summary>';
	echo '<ol>';
	echo '<li>' . esc_html__( 'Install Node.js if the machine does not have it: the config runs through npx.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . sprintf(
		/* translators: 1: the config filename, 2: macOS path, 3: Windows path. */
		esc_html__( 'Open the app’s local MCP config file, %1$s: macOS: %2$s · Windows: %3$s (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ),
		'<code>claude_desktop_config.json</code>',
		'<code>~/Library/Application Support/Claude/</code>',
		'<code>%APPDATA%\\Claude\\</code>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Paste the config into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . esc_html__( 'Fully restart the Claude app (quit, not just close the window). The site’s tools appear in the tools menu of a new chat.', 'signal-and-noise-tools' ) . '</li>';
	echo '</ol>';
	echo '<p>' . esc_html__( 'Do not use Settings → Connectors → “Add custom connector” for this endpoint: that flow is for remote servers reached from Anthropic’s own infrastructure and only supports OAuth: an application password will not work there.', 'signal-and-noise-tools' ) . '</p>';
	echo '</details>';
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
	echo '<li><a href="' . esc_url( admin_url( 'tools.php' ) ) . '">' . esc_html__( 'Tools menu: Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ) . '</a></li>';
	echo '<li><a href="' . esc_url( 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ) . '">' . esc_html__( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ) . '</a></li>';
	echo '</ul>';
}
