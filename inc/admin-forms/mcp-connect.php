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
 * sn_handle_bind_mcp_rw_credential() (inc/admin-post-actions.php), which is
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

/**
 * Tools → Connect an MCP client section body. Used as the
 * sn_admin_render_section() callback for the 'mcp-connect' sub-tab.
 */
function sn_admin_render_mcp_connect_section() {
	echo '<p>' . esc_html__( 'Three MCP doors can answer for this site (two native, one third-party) and every one of them sits behind your own WordPress login, an Application Password, never a shared secret. The native doors split by capability: the read door below can only look, the write door under it can also change things, so use whichever credential scope you actually mean to grant.', 'signal-and-noise-tools' ) . '</p>';

	sn_admin_render_mcp_status_glance();

	// M1 (IA): bind + connect sit above both tool-list doors so the returning
	// owner's job and the first-run job are reachable without scrolling past
	// the live slug inventories. No disclosure wrapping here — that is M2.
	sn_admin_render_mcp_rw_binding();
	sn_admin_render_mcp_owner_steps();
	sn_admin_render_mcp_door_native();
	sn_admin_render_mcp_door_native_write();
	sn_admin_render_mcp_resources_prompts();
	sn_admin_render_mcp_door_adapter();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Not the same as Connector Approvals', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'Tools → Connector Approvals (if the AI plugin is active) gates OUTBOUND use of this site’s configured AI-provider connectors by server-side plugin and theme code: it decides which of your plugins may spend against your Anthropic, OpenAI, or Google key. It has nothing to do with an external MCP client connecting IN. That inbound grant is the Application Password below.', 'signal-and-noise-tools' ) . '</p>';
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
	echo '<p class="sn-callout-h">' . esc_html__( 'Door 1: the native MCP server', 'signal-and-noise-tools' ) . ' <span class="sn-badge">' . esc_html__( 'since v9.22.0', 'signal-and-noise-tools' ) . '</span></p>';
	echo '<p>' . sprintf(
		/* translators: %s: the WordPress capability slug, wrapped in <code>. */
		esc_html__( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and the tool list below is the whole surface: nothing outside it is reachable, even by request.', 'signal-and-noise-tools' ),
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
		esc_html__( '%d read-only tools exposed — show the list', 'signal-and-noise-tools' ),
		count( $slugs )
	);
	echo '</summary>';
	echo '<ul class="sn-mcp-tool-list">';
	foreach ( $slugs as $slug ) {
		echo '<li><code>' . esc_html( $slug ) . '</code></li>';
	}
	echo '</ul>';
	echo '</details>';
	echo '<p>' . esc_html__( 'This door never mutates anything, by construction: see the write door below for actions.', 'signal-and-noise-tools' ) . '</p>';
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
function sn_admin_render_mcp_door_native_write() {
	$url        = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) )
		? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' )
		: '';
	$rw_slugs   = function_exists( 'sn_mcp_rw_allowlist' ) ? sn_mcp_rw_allowlist() : array();
	$read_slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();

	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Door 1b: the native write door', 'signal-and-noise-tools' ) . ' <span class="sn-badge">' . esc_html__( 'since v9.50.0', 'signal-and-noise-tools' ) . '</span></p>';
	echo '<p>' . sprintf(
		/* translators: %s: the WordPress capability slug, wrapped in <code>. */
		esc_html__( 'The exact same JSON-RPC 2.0 server, the same %s capability, and the same Application Password as the read door above: there is no separate write-only credential. What changes is the surface: most tools here can modify your content (posts, postmeta, taxonomy terms, scheduled cron events, cached files) or spend the AI budget configured on this site; a couple are read-only login-audit exports carrying plaintext usernames, gated behind this higher bar instead of the read door.', 'signal-and-noise-tools' ),
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
		esc_html__( '%1$d read-write tools exposed · %2$d withheld — show the list', 'signal-and-noise-tools' ),
		count( $rw_slugs ),
		count( sn_admin_mcp_withheld_slug_data() )
	);
	echo '</summary>';
	echo '<ul class="sn-mcp-tool-list">';
	foreach ( $rw_slugs as $slug ) {
		echo '<li><code>' . esc_html( $slug ) . '</code></li>';
	}
	echo '</ul>';
	echo '<p>' . sprintf(
		/* translators: %d: the live count of read-only tools on the read door. */
		esc_html__( 'This door’s tools/list does not repeat the %d read-only tools above: if you only want to look, connect to the read door instead.', 'signal-and-noise-tools' ),
		count( $read_slugs )
	) . '</p>';

	sn_admin_render_mcp_withheld_slugs();
	echo '</details>';
	echo '</div>';
}

/**
 * R9 (v9.51.0, lane SEC-C): the write-door credential-binding form — the ONE
 * form on this otherwise read-only leaf (see the file docblock). Reads the
 * bound state through sn_mcp_rw_bound_uuid() (inc/mcp/mcp-rw-guard.php, lane
 * SEC-A — called via a function_exists() guard, never required directly,
 * exactly like every other cross-lane call in this file) and cross-references
 * it against the CURRENT user's own Application Passwords
 * (WP_Application_Passwords::get_user_application_passwords()) so the owner
 * sees WHICH credential — by name and last-used — is actually bound, not a
 * bare UUID. Three bound states are distinguished on purpose: unbound (door
 * inactive), bound-and-resolvable (name + last-used shown), and bound-but-
 * unresolvable (the UUID no longer matches any of this user's own Application
 * Passwords — revoked, or another user's — re-bind is the only path out).
 *
 * Submits to sn_handle_bind_mcp_rw_credential() (inc/admin-post-actions.php)
 * via the plugin's standard sn_theme_options_nonce + sn_action POST contract
 * (see inc/admin-forms/login.php for the same minimal shape — no hidden
 * tab/sub fields needed, since a same-URL POST already carries them via the
 * query string).
 */
function sn_admin_render_mcp_rw_binding() {
	$bound_uuid = function_exists( 'sn_mcp_rw_bound_uuid' ) ? sn_mcp_rw_bound_uuid() : '';
	$passwords  = class_exists( 'WP_Application_Passwords' )
		? (array) WP_Application_Passwords::get_user_application_passwords( get_current_user_id() )
		: array();

	$bound_password = null;
	if ( '' !== $bound_uuid ) {
		foreach ( $passwords as $pw ) {
			if ( is_array( $pw ) && ! empty( $pw['uuid'] ) && hash_equals( (string) $pw['uuid'], $bound_uuid ) ) {
				$bound_password = $pw;
				break;
			}
		}
	}

	echo '<p class="sn-callout-h">' . esc_html__( 'Bind the write-door credential', 'signal-and-noise-tools' ) . ' <span class="sn-badge">' . esc_html__( 'since v9.51.0', 'signal-and-noise-tools' ) . '</span></p>';

	if ( '' === $bound_uuid ) {
		echo '<p><strong>' . esc_html__( 'The write door is INACTIVE.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'No Application Password is bound to it yet, so every call to /mcp-rw is denied. Bind one below to turn it on.', 'signal-and-noise-tools' ) . '</p>';
	} elseif ( null !== $bound_password ) {
		echo '<p>' . sprintf(
			/* translators: %s: the bound Application Password's name, wrapped in <strong>. */
			esc_html__( 'The write door is bound to %s.', 'signal-and-noise-tools' ),
			'<strong>' . esc_html( (string) ( $bound_password['name'] ?? '' ) ) . '</strong>'
		) . '</p>';
		$last_used = ! empty( $bound_password['last_used'] ) ? (int) $bound_password['last_used'] : 0;
		if ( $last_used > 0 && function_exists( 'human_time_diff' ) ) {
			echo '<p>' . sprintf(
				/* translators: %s: a human-readable relative time, e.g. "3 days". */
				esc_html__( 'Last used %s ago.', 'signal-and-noise-tools' ),
				esc_html( human_time_diff( $last_used, time() ) )
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Never used yet.', 'signal-and-noise-tools' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Rotate this Application Password periodically: anyone who holds it can call the write door.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'A write-door credential is bound, but it no longer matches any of your own Application Passwords: it may have been revoked, or belongs to a different user. Re-bind below.', 'signal-and-noise-tools' ) . '</p>';
	}

	if ( empty( $passwords ) ) {
		echo '<p>' . sprintf(
			/* translators: %s: a link to the current user's Application Passwords section. */
			esc_html__( 'You have no Application Passwords yet: create one under %s first.', 'signal-and-noise-tools' ),
			'<a href="' . esc_url( get_edit_profile_url() . '#application-passwords-section' ) . '">' . esc_html__( 'your profile', 'signal-and-noise-tools' ) . '</a>'
		) . '</p>';
		return;
	}

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="bind_mcp_rw_credential">';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_mcp_rw_uuid">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</label>';
	echo '<select id="sn_mcp_rw_uuid" name="sn_mcp_rw_uuid">';
	echo '<option value="">' . esc_html__( 'Unbind (deny every write-door call)', 'signal-and-noise-tools' ) . '</option>';
	foreach ( $passwords as $pw ) {
		if ( ! is_array( $pw ) || empty( $pw['uuid'] ) ) {
			continue;
		}
		$uuid  = (string) $pw['uuid'];
		$label = isset( $pw['name'] ) ? (string) $pw['name'] : '';
		if ( ! empty( $pw['created'] ) && function_exists( 'human_time_diff' ) ) {
			$label .= ' — ' . sprintf(
				/* translators: %s: a human-readable relative time, e.g. "3 days". */
				__( 'created %s ago', 'signal-and-noise-tools' ),
				human_time_diff( (int) $pw['created'], time() )
			);
		}
		echo '<option value="' . esc_attr( $uuid ) . '"' . selected( $bound_uuid, $uuid, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">' . esc_html__( 'The same list as your profile’s Application Passwords: this only scopes one of them to the write door.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save write-door credential', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';
	echo '</form>';
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
			'reason' => __( 'deletes Site Editor template, part, and nav overrides: can regress the whole site design', 'signal-and-noise-tools' ),
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
	echo '<p class="sn-callout-h">' . esc_html__( 'Resources & prompts', 'signal-and-noise-tools' ) . ' <span class="sn-badge">' . esc_html__( 'since v9.50.0', 'signal-and-noise-tools' ) . '</span></p>';
	echo '<p>' . esc_html__( 'Both native doors also serve four read-only MCP resources (sn://abilities-catalog, sn://changelog-latest, sn://design-tokens, and sn://llms-txt) a client can fetch any of them directly without calling a tool.', 'signal-and-noise-tools' ) . '</p>';
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
	echo '<p class="sn-callout-h">' . esc_html__( 'Door 2: the Abilities-registry adapter', 'signal-and-noise-tools' ) . '</p>';
	// v9.48.1: attribution corrected. The adapter is NOT part of the wp.org
	// "AI" plugin (ground-truthed 2026-07-15: that plugin's MCP integration is
	// roadmap-only, "coming soon"); it is a separate, GitHub-only WordPress
	// plugin (WordPress/mcp-adapter, pre-1.0, not on wordpress.org). The old
	// copy here taught the owner the wrong fact — never re-attribute it.
	if ( $active ) {
		echo '<p>' . esc_html__( 'The WordPress MCP Adapter plugin is active on this site and answers for the entire Abilities registry (44+ abilities across the theme and this plugin), each still gated by its own capability check. The adapter is its own plugin: the wp.org “AI” plugin does not bundle it.', 'signal-and-noise-tools' ) . '</p>';
		echo '<p><code>' . esc_url( $url ) . '</code></p>';
	} else {
		echo '<p>' . esc_html__( 'No MCP Adapter is installed on this site (Door 1 above is the only live MCP endpoint here. The adapter is a separate WordPress plugin (github.com/WordPress/mcp-adapter) pre-1.0, not on wordpress.org), and the wp.org “AI” plugin does not bundle it: that plugin lists MCP as coming soon. If the adapter is ever installed, its default server would answer at the address below.', 'signal-and-noise-tools' ) . '</p>';
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
		esc_html__( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ),
		'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</a>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Copy the endpoint URL for whichever door you’re using. Door 1 above for the read-only tool allowlist, Door 2 for the full Abilities registry.', 'signal-and-noise-tools' ) . '</li>';
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
	echo '<details class="sn-disclosure">';
	// The callout heading directly above already says "Claude desktop app", so
	// the summary names what is INSIDE rather than repeating it.
	echo '<summary>' . esc_html__( 'Show the 4 setup steps', 'signal-and-noise-tools' ) . '</summary>';
	echo '<ol>';
	echo '<li>' . esc_html__( 'Install Node.js if the machine does not have it: the config below runs the proxy via npx.', 'signal-and-noise-tools' ) . '</li>';
	echo '<li>' . sprintf(
		/* translators: 1: the config filename, 2: macOS path, 3: Windows path. */
		esc_html__( 'Open the app’s local MCP config file, %1$s: macOS: %2$s · Windows: %3$s (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ),
		'<code>claude_desktop_config.json</code>',
		'<code>~/Library/Application Support/Claude/</code>',
		'<code>%APPDATA%\\Claude\\</code>'
	) . '</li>';
	echo '<li>' . esc_html__( 'Paste the proxy config above into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) . '</li>';
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
	echo '<li><a href="' . esc_url( admin_url( 'tools.php' ) ) . '">' . esc_html__( 'Tools menu. Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ) . '</a></li>';
	echo '<li><a href="' . esc_url( 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ) . '">' . esc_html__( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ) . '</a></li>';
	echo '</ul>';
}
