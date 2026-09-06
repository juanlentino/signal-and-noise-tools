<?php
/**
 * S&N Dashboard — AI → MCP Clients, helpers for ai-mcp-connect.php.
 *
 * Split out to keep the leaf file under the house line cap. Every reader
 * here is the SAME one inc/admin-forms/mcp-connect.php and
 * inc/admin-forms/mcp-connect-status.php call, behind the same
 * function_exists()/class_exists() guards those files already use.
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The write-door credential-binding form — the one form on this otherwise
 * read-only leaf. Same three bound states (unbound / bound / unresolvable),
 * same "no Application Passwords yet" fallback (a door to the profile
 * screen, no form at all), same field (`sn_mcp_rw_uuid`), same action
 * (`bind_mcp_rw_credential`).
 *
 * @return string
 */
function mcp_connect_rw_binding_html() {
	$bound_uuid = function_exists( 'sn_mcp_rw_bound_uuid' ) ? sn_mcp_rw_bound_uuid() : '';
	$passwords  = class_exists( 'WP_Application_Passwords' )
		? (array) \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() )
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

	$status = '';
	if ( '' === $bound_uuid ) {
		$status = \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The write door is INACTIVE.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( __( 'No Application Password is bound to it yet, so every call to /mcp-rw is denied. Bind one below to turn it on.', 'signal-and-noise-tools' ) ) );
	} elseif ( null !== $bound_password ) {
		$last_used = ! empty( $bound_password['last_used'] ) ? (int) $bound_password['last_used'] : 0;
		$when      = ( $last_used > 0 && function_exists( 'human_time_diff' ) )
			? sprintf( /* translators: %s: relative time. */ __( 'Last used %s ago.', 'signal-and-noise-tools' ), \snt_kit_esc( human_time_diff( $last_used, time() ) ) )
			: \snt_kit_esc( __( 'Never used yet.', 'signal-and-noise-tools' ) );
		$status = \snt_kit_notice(
			'ok',
			'<b>' . sprintf( /* translators: %s: bound Application Password name. */ \snt_kit_esc( __( 'The write door is bound to %s.', 'signal-and-noise-tools' ) ), \snt_kit_esc( (string) ( $bound_password['name'] ?? '' ) ) ) . '</b><br>'
			. $when . '<br>' . \snt_kit_esc( __( 'Rotate this Application Password periodically: anyone who holds it can call the write door.', 'signal-and-noise-tools' ) )
		);
	} else {
		$status = \snt_kit_notice( 'warn', \snt_kit_esc( __( 'A write-door credential is bound, but it no longer matches any of your own Application Passwords: it may have been revoked, or belongs to a different user. Re-bind below.', 'signal-and-noise-tools' ) ) );
	}

	if ( empty( $passwords ) ) {
		$profile = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';
		return \snt_kit_section(
			__( 'Bind the write-door credential', 'signal-and-noise-tools' ),
			$status . '<p class="snt-prose">' . \snt_kit_esc( __( 'You have no Application Passwords yet: create one under your profile first.', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_door( __( 'Your profile', 'signal-and-noise-tools' ), $profile ) . '</p>',
			__( 'since v9.51.0', 'signal-and-noise-tools' )
		);
	}

	$options = array( '' => __( 'Unbind (deny every write-door call)', 'signal-and-noise-tools' ) );
	foreach ( $passwords as $pw ) {
		if ( ! is_array( $pw ) || empty( $pw['uuid'] ) ) {
			continue;
		}
		$label = isset( $pw['name'] ) ? (string) $pw['name'] : '';
		if ( ! empty( $pw['created'] ) && function_exists( 'human_time_diff' ) ) {
			$label .= ' — ' . sprintf( /* translators: %s: relative time. */ __( 'created %s ago', 'signal-and-noise-tools' ), human_time_diff( (int) $pw['created'], time() ) );
		}
		$options[ (string) $pw['uuid'] ] = $label;
	}
	$field = \snt_kit_field(
		'select',
		'sn_mcp_rw_uuid',
		__( 'Application Password', 'signal-and-noise-tools' ),
		$bound_uuid,
		array( 'options' => $options, 'hint' => __( 'The same list as your profile’s Application Passwords: this only scopes one of them to the write door.', 'signal-and-noise-tools' ) )
	);
	$form = \snt_kit_form( 'bind_mcp_rw_credential', $field, array( 'submit' => __( 'Save write-door credential', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Bind the write-door credential', 'signal-and-noise-tools' ), $status . $form, __( 'since v9.51.0', 'signal-and-noise-tools' ) );
}

/**
 * The three owner steps + the copy-paste client config (proxy config, and
 * the Claude Code one-liner), then the Claude desktop app sub-steps.
 *
 * @return string
 */
function mcp_connect_owner_steps_html() {
	$native_url  = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$profile_url = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';

	$steps = '<ol class="snt-plain">'
		. '<li>' . sprintf( /* translators: %s: a door to the Application Passwords section. */ \snt_kit_esc( __( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.', 'signal-and-noise-tools' ) ), \snt_kit_door( __( 'Application Password', 'signal-and-noise-tools' ), $profile_url ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Copy the endpoint URL for whichever door you’re using. Door 1 above for the read-only tool allowlist, Door 2 for abilities that opt in to the adapter (none of ours do).', 'signal-and-noise-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Paste the client config below, swapping in your WordPress username and the Application Password you just created.', 'signal-and-noise-tools' ) ) . '</li>'
		. '</ol>';

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
		. \snt_kit_code( 'claude mcp add --transport http signal-and-noise ' . $native_url . ' --header "Authorization: Basic <base64 admin-username:application-password>"', false )
		. mcp_connect_claude_app_html();

	return \snt_kit_section( __( 'Connect a client', 'signal-and-noise-tools' ), $body );
}

/**
 * The Claude desktop app path: the 4 local-config setup steps, folded, and
 * the explicit "don't use the OAuth connector flow here" close.
 *
 * @return string
 */
function mcp_connect_claude_app_html() {
	$steps = '<ol class="snt-plain">'
		. '<li>' . \snt_kit_esc( __( 'Install Node.js if the machine does not have it: the config below runs the proxy via npx.', 'signal-and-noise-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Open the app’s local MCP config file, claude_desktop_config.json: macOS ~/Library/Application Support/Claude/ · Windows %APPDATA%\\Claude\\ (Claude → Settings → Developer → Edit Config opens it for you).', 'signal-and-noise-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Paste the proxy config above into that file (merge into an existing "mcpServers" object if one is there), with your real username and Application Password swapped in.', 'signal-and-noise-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_esc( __( 'Fully restart the Claude app (quit, not just close the window). The site’s tools appear in the tools menu of a new chat.', 'signal-and-noise-tools' ) ) . '</li>'
		. '</ol>'
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Do not use Settings → Connectors → “Add custom connector” for this endpoint: that flow is for remote servers reached from Anthropic’s own infrastructure and only supports OAuth: an application password will not work there.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Claude desktop app', 'signal-and-noise-tools' ), 'hint' => __( 'Show the 4 setup steps', 'signal-and-noise-tools' ) ), $steps );
}

/**
 * Door 1 — the native read-only MCP server. Slugs read LIVE from
 * sn_mcp_allowlist(), never hardcoded.
 *
 * @return string
 */
function mcp_connect_door_native_html() {
	$url   = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();

	$body = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'A dependency-free JSON-RPC 2.0 endpoint this plugin hand-rolls. Every call needs a WordPress login with the %s capability, and the tool list below is the whole surface: nothing outside it is reachable, even by request.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'ok', __( 'read-only', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_tag(
			'os-disclosure',
			array( 'heading' => sprintf( /* translators: %d: live tool count. */ __( '%d read-only tools exposed — show the list', 'signal-and-noise-tools' ), count( $slugs ) ) ),
			'<ul class="snt-plain">' . implode( '', array_map( static function ( $slug ) { return '<li>' . \snt_kit_code( (string) $slug, false ) . '</li>'; }, $slugs ) ) . '</ul>'
		)
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'This door never mutates anything, by construction: see the write door below for actions.', 'signal-and-noise-tools' ) ) . '</p>';

	return \snt_kit_section( __( 'Door 1: the native MCP server', 'signal-and-noise-tools' ), $body, __( 'since v9.22.0', 'signal-and-noise-tools' ) );
}

/**
 * The 4 abilities withheld from the write door, one permanently, three
 * pending an explicit opt-in — the SAME data the write door's summary
 * counts, read from the classic leaf's own function so the two can never
 * disagree.
 *
 * @return string
 */
function mcp_connect_withheld_html() {
	if ( ! function_exists( 'sn_admin_mcp_withheld_slug_data' ) ) {
		return '';
	}
	$rows = '';
	foreach ( sn_admin_mcp_withheld_slug_data() as $slug => $info ) {
		$rows .= '<li>' . \snt_kit_code( (string) $slug, false ) . ' ' . \snt_kit_badge( 'signal-noise/run-cron-event' === (string) $slug ? 'err' : 'warn', (string) ( $info['badge'] ?? '' ) ) . ' ' . \snt_kit_esc( (string) ( $info['reason'] ?? '' ) ) . '</li>';
	}
	return '<p class="snt-prose">' . \snt_kit_esc( __( 'Four abilities never reach this door: one permanently, three pending an explicit future opt-in:', 'signal-and-noise-tools' ) ) . '</p><ul class="snt-plain">' . $rows . '</ul>';
}

/**
 * Door 1b — the native write door. Its tools/list does not repeat the read
 * door's tools; the withheld-abilities list stays inside the same fold,
 * exactly as the classic leaf nests it.
 *
 * @return string
 */
function mcp_connect_door_native_write_html() {
	$url        = ( function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' ) ) ? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' ) : '';
	$rw_slugs   = function_exists( 'sn_mcp_rw_allowlist' ) ? sn_mcp_rw_allowlist() : array();
	$read_slugs = function_exists( 'sn_mcp_allowlist' ) ? sn_mcp_allowlist() : array();
	$withheld   = function_exists( 'sn_admin_mcp_withheld_slug_data' ) ? sn_admin_mcp_withheld_slug_data() : array();

	$body = '<p class="snt-prose">' . sprintf( /* translators: %s: manage_options, as <os-code>. */ \snt_kit_esc( __( 'The exact same JSON-RPC 2.0 server, the same %s capability, and the same Application Password as the read door above: there is no separate write-only credential. What changes is the surface: most tools here can modify your content (posts, postmeta, taxonomy terms, scheduled cron events, cached files) or spend the AI budget configured on this site; a couple are read-only login-audit exports carrying plaintext usernames, gated behind this higher bar instead of the read door.', 'signal-and-noise-tools' ) ), \snt_kit_code( 'manage_options', false ) ) . '</p>'
		. '<p>' . \snt_kit_code( 'POST ' . $url, false ) . ' ' . \snt_kit_badge( 'warn', __( 'read-write', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_tag(
			'os-disclosure',
			array(
				'heading' => sprintf( /* translators: 1: live rw tool count, 2: withheld count. */ __( '%1$d read-write tools exposed · %2$d withheld — show the list', 'signal-and-noise-tools' ), count( $rw_slugs ), count( $withheld ) ),
			),
			'<ul class="snt-plain">' . implode( '', array_map( static function ( $slug ) { return '<li>' . \snt_kit_code( (string) $slug, false ) . '</li>'; }, $rw_slugs ) ) . '</ul>'
			. '<p class="snt-hint">' . sprintf( /* translators: %d: live read-door tool count. */ \snt_kit_esc( __( 'This door’s tools/list does not repeat the %d read-only tools above: if you only want to look, connect to the read door instead.', 'signal-and-noise-tools' ) ), count( $read_slugs ) ) . '</p>'
			. mcp_connect_withheld_html()
		);

	return \snt_kit_section( __( 'Door 1b: the native write door', 'signal-and-noise-tools' ), $body, __( 'since v9.50.0', 'signal-and-noise-tools' ) );
}

/**
 * Resources & prompts — one sentence per category.
 *
 * @return string
 */
function mcp_connect_resources_prompts_html() {
	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'Both native doors also serve four read-only MCP resources (sn://abilities-catalog, sn://changelog-latest, sn://design-tokens, and sn://llms-txt): a client can fetch any of them directly without calling a tool.', 'signal-and-noise-tools' ) ) . '</p>'
		. '<p class="snt-prose">' . \snt_kit_esc( __( 'Two ready-made prompts, weekly-report and content-audit, chain several read tools together into one owner-voiced synthesis your client can run in a single step.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'Resources & prompts', 'signal-and-noise-tools' ), $body, __( 'since v9.50.0', 'signal-and-noise-tools' ) );
}

/**
 * Door 2 — the wp.org "AI" plugin's MCP Adapter, if installed. Detection is
 * the adapter's own documented seam (class_exists), never guessed.
 *
 * @return string
 */
function mcp_connect_door_adapter_html() {
	$active = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	$url    = function_exists( 'rest_url' ) ? (string) rest_url( 'mcp/mcp-adapter-default-server' ) : '';

	$body = $active
		? '<p class="snt-prose">' . \snt_kit_esc( __( 'The WordPress MCP Adapter plugin is active on this site. Since adapter 0.6.0 its default server carries only abilities that opt in through meta.mcp.public (falling back to meta.public); none of the abilities registered by this plugin or the theme opt in, so this door exposes none of them. Each exposed ability is still gated by its own capability check. The adapter is its own plugin: the wp.org “AI” plugin does not bundle it.', 'signal-and-noise-tools' ) ) . '</p>'
		: '<p class="snt-prose">' . \snt_kit_esc( __( 'No MCP Adapter is installed on this site (Door 1 above is the only live MCP endpoint here. The adapter is a separate WordPress plugin (github.com/WordPress/mcp-adapter) pre-1.0, not on wordpress.org), and the wp.org “AI” plugin does not bundle it: that plugin lists MCP as coming soon. If the adapter is ever installed, its default server would answer at the address below, and since adapter 0.6.0 it would carry only abilities that opt in through meta.mcp.public; none of ours do.', 'signal-and-noise-tools' ) ) . '</p>';
	$body .= '<p>' . \snt_kit_code( $url, false ) . '</p>';

	return \snt_kit_section( __( 'Door 2: the Abilities-registry adapter', 'signal-and-noise-tools' ), $body );
}

/**
 * The optional tool-usage readout, folded. Absent when the telemetry lane
 * is not loaded — same guard as the classic leaf.
 *
 * @return string
 */
function mcp_connect_usage_html() {
	if ( ! function_exists( 'sn_mcp_telemetry_usage' ) ) {
		return '';
	}
	$usage = sn_mcp_telemetry_usage();
	if ( null === $usage ) {
		$installed = function_exists( 'sn_mcp_telemetry_table_exists' ) && sn_mcp_telemetry_table_exists();
		return \snt_kit_section(
			__( 'Tool usage', 'signal-and-noise-tools' ),
			'<p class="snt-hint">' . \snt_kit_esc(
				$installed
					? __( 'The call log exists but could not be read — a database error, not an absence of calls. Nothing here should be treated as usage evidence.', 'signal-and-noise-tools' )
					: __( 'The call log table is not installed yet. It is created on the first MCP call; until then there is no usage evidence either way.', 'signal-and-noise-tools' )
			) . '</p>'
		);
	}

	$since = $usage['measured_since'] ?? null;
	if ( null === $since ) {
		return \snt_kit_section( __( 'Tool usage', 'signal-and-noise-tools' ), '<p class="snt-hint">' . \snt_kit_esc( __( 'The call log is installed and has recorded nothing. That is not the same as these tools going unused — no call has been made through either door yet.', 'signal-and-noise-tools' ) ) . '</p>' );
	}

	$zero = (array) ( $usage['zero_call'] ?? array() );
	$by_tool = (array) ( $usage['by_tool'] ?? array() );
	uasort( $by_tool, static function ( $a, $b ) { return (int) $b['calls'] <=> (int) $a['calls']; } );

	$rows = array();
	foreach ( $by_tool as $name => $row ) {
		$rows[] = array(
			'tool'      => (string) $name,
			'calls'     => number_format_i18n( (int) ( $row['calls'] ?? 0 ) ),
			'last_seen' => (string) ( $row['last_seen'] ?? '—' ),
			'doors'     => implode( ', ', (array) ( $row['doors'] ?? array() ) ),
		);
	}
	$table = \snt_kit_table(
		array( array( 'key' => 'tool', 'label' => __( 'Tool', 'signal-and-noise-tools' ) ), array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ), array( 'key' => 'last_seen', 'label' => __( 'Last seen', 'signal-and-noise-tools' ) ), array( 'key' => 'doors', 'label' => __( 'Doors', 'signal-and-noise-tools' ) ) ),
		$rows
	);

	$labels = array(
		'unused'       => __( 'no calls — retirement candidate', 'signal-and-noise-tools' ),
		'unreachable'  => __( 'cannot be projected — this is a BUG, not a retirement candidate', 'signal-and-noise-tools' ),
		'undetermined' => __( 'reachability unknown — no judgement possible', 'signal-and-noise-tools' ),
	);
	$zero_html = empty( $zero )
		? '<p class="snt-hint">' . \snt_kit_esc( __( 'Every allowlisted tool was called at least once in this window.', 'signal-and-noise-tools' ) ) . '</p>'
		: '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'No calls in this window', 'signal-and-noise-tools' ) ) . '</b></p>'
			. '<ul class="snt-plain">' . implode( '', array_map( static function ( $entry ) use ( $labels ) {
				$verdict = (string) ( $entry['verdict'] ?? '' );
				return '<li>' . \snt_kit_code( (string) ( $entry['slug'] ?? '' ), false ) . ' — ' . \snt_kit_esc( $labels[ $verdict ] ?? $verdict ) . '</li>';
			}, $zero ) ) . '</ul>'
			. '<p class="snt-hint">' . \snt_kit_esc( __( 'Only “retirement candidate” entries are evidence for removal. A tool that cannot be projected has no calls because it cannot be called — retiring it would delete the evidence of the defect. Reachability is checked from inside the plugin, so it cannot see a client proxy rejecting a schema; treat it as necessary, not sufficient.', 'signal-and-noise-tools' ) ) . '</p>';

	$summary = sprintf(
		/* translators: 1: measured days, 2: window days, 3: zero-call tool count. */
		__( 'Measured over %1$d days of a %2$d-day window · %3$d tools with no calls', 'signal-and-noise-tools' ),
		(int) ( $usage['measured_days'] ?? 0 ),
		(int) ( $usage['window_days'] ?? 0 ),
		count( $zero )
	);

	$partial = empty( $usage['complete'] )
		? '<p class="snt-hint">' . sprintf( /* translators: 1: first recorded date, 2: window days. */ \snt_kit_esc( __( 'Partial window. Recording began %1$s, so this covers less than the full %2$d days asked for. A tool with no calls here may simply predate the sensor.', 'signal-and-noise-tools' ) ), \snt_kit_esc( (string) $since ), (int) ( $usage['window_days'] ?? 0 ) ) . '</p>'
		: '';

	$body = $partial . $table . $zero_html . '<p class="snt-hint">' . sprintf( /* translators: %d: total recorded calls. */ \snt_kit_esc( __( '%d calls recorded in this window, including calls that never resolved to a tool.', 'signal-and-noise-tools' ) ), (int) ( $usage['total_rows'] ?? 0 ) ) . '</p>';

	return \snt_kit_tag( 'os-disclosure', array( 'heading' => __( 'Tool usage', 'signal-and-noise-tools' ), 'hint' => $summary ), $body );
}

/**
 * Deep links: the Tools menu (door — another admin screen) and the
 * Abilities catalog doc (external link).
 *
 * @return string
 */
function mcp_connect_deep_links_html() {
	$links = '<ul class="snt-plain"><li>' . \snt_kit_door( __( 'Tools menu. Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ), function_exists( 'admin_url' ) ? admin_url( 'tools.php' ) : '' ) . '</li>'
		. '<li>' . \snt_kit_link( __( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ) . '</li></ul>';
	return \snt_kit_section( __( 'More', 'signal-and-noise-tools' ), $links );
}
