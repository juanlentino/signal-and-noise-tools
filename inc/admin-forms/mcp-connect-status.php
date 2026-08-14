<?php
/**
 * Signal & Noise — IA increment M3: MCP Clients status glance.
 *
 * Display-only three-card readout at the top of AI → MCP Clients. Showing
 * SN_MCP_RW_DISABLED is honesty; flipping it from this page would be a new
 * capability and is out of scope. Card shape is sn_admin_glance_grid()'s
 * (label / value / meta_html / pill); layout CSS stays in assets/admin.css.
 *
 * Pure/live split matches the house idiom in inc/mcp/mcp-rw-guard.php:
 * sn_admin_mcp_status_cards() takes injected state, sn_admin_mcp_status_state()
 * gathers it. Tests pin the pure builder directly.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: three glance cards from injected state. Unknown counts stay unknown
 * (null → "allowlist unavailable", never "0 tools"). No WP reads.
 *
 * @param array $state {
 *     @type int|null $read_count     Live read-door allowlist size, or null if unknown.
 *     @type string   $read_url       Read-door REST URL.
 *     @type int|null $rw_count       Live write-door allowlist size, or null if unknown.
 *     @type string   $rw_url         Write-door REST URL.
 *     @type string   $rw_state       constant_killed|option_off|inactive|bound|unresolvable.
 *     @type string   $rw_name        Bound Application Password name (bound state).
 *     @type int      $rw_last_used   Unix timestamp; 0 = never used.
 *     @type bool     $adapter_active Whether WP\MCP\Core\McpAdapter is loaded.
 *     @type string   $adapter_url    Adapter default-server REST URL.
 *     @type string   $remote_state   constant_killed|option_off|secret_missing|bridge_ready.
 * }
 * @return array<int,array<string,mixed>> Exactly four cards for sn_admin_glance_grid().
 */
function sn_admin_mcp_status_cards( array $state ) {
	$read_count     = array_key_exists( 'read_count', $state ) ? $state['read_count'] : null;
	$read_url       = isset( $state['read_url'] ) ? (string) $state['read_url'] : '';
	$rw_count       = array_key_exists( 'rw_count', $state ) ? $state['rw_count'] : null;
	$rw_url         = isset( $state['rw_url'] ) ? (string) $state['rw_url'] : '';
	$rw_state       = isset( $state['rw_state'] ) ? (string) $state['rw_state'] : 'inactive';
	$rw_name        = isset( $state['rw_name'] ) ? (string) $state['rw_name'] : '';
	$rw_last_used   = isset( $state['rw_last_used'] ) ? (int) $state['rw_last_used'] : 0;
	$adapter_active = ! empty( $state['adapter_active'] );
	$adapter_url    = isset( $state['adapter_url'] ) ? (string) $state['adapter_url'] : '';
	$remote_state   = isset( $state['remote_state'] ) ? (string) $state['remote_state'] : 'option_off';

	$read_card = array(
		'label'     => __( 'Read door', 'signal-and-noise-tools' ),
		'meta_html' => '<code>POST ' . esc_url( $read_url ) . '</code>',
	);
	if ( is_int( $read_count ) ) {
		$read_card['value'] = sprintf(
			/* translators: %d: live count of read-door tools. */
			__( '%d tools', 'signal-and-noise-tools' ),
			$read_count
		);
		$read_card['pill'] = array(
			'kind' => 'ok',
			'text' => __( 'read-only', 'signal-and-noise-tools' ),
		);
	} else {
		$read_card['value'] = __( 'allowlist unavailable', 'signal-and-noise-tools' );
	}

	switch ( $rw_state ) {
		case 'constant_killed':
			$rw_value = __( 'Killed in wp-config', 'signal-and-noise-tools' );
			$rw_meta  = sprintf(
				/* translators: %s: SN_MCP_RW_DISABLED wrapped in <code>. */
				__( 'The %s constant is set in wp-config. Display-only: this page cannot flip it.', 'signal-and-noise-tools' ),
				'<code>SN_MCP_RW_DISABLED</code>'
			);
			$rw_pill = array( 'kind' => 'err', 'text' => __( 'killed', 'signal-and-noise-tools' ) );
			break;
		case 'option_off':
			$rw_value = __( 'Switched off', 'signal-and-noise-tools' );
			$rw_meta  = sprintf(
				/* translators: %s: sn_mcp_rw_enabled wrapped in <code>. */
				__( 'The %s option is off.', 'signal-and-noise-tools' ),
				'<code>sn_mcp_rw_enabled</code>'
			);
			$rw_pill = array( 'kind' => 'warn', 'text' => __( 'off', 'signal-and-noise-tools' ) );
			break;
		case 'bound':
			// $rw_name stays RAW here: sn_admin_glance_grid() esc_html()s every
			// card value itself, so pre-escaping would double-escape any name
			// carrying quotes or angle brackets (the render suite's own
			// escaping-sensitive fixture shape).
			$rw_value = sprintf(
				/* translators: %s: bound Application Password name. */
				__( 'Bound to %s', 'signal-and-noise-tools' ),
				$rw_name
			);
			if ( $rw_last_used > 0 && function_exists( 'human_time_diff' ) ) {
				$rw_meta = sprintf(
					/* translators: %s: relative time such as "3 days". */
					__( 'Last used %s ago', 'signal-and-noise-tools' ),
					esc_html( human_time_diff( $rw_last_used, time() ) )
				);
			} else {
				$rw_meta = __( 'Never used yet', 'signal-and-noise-tools' );
			}
			$rw_pill = array( 'kind' => 'ok', 'text' => __( 'bound', 'signal-and-noise-tools' ) );
			break;
		case 'unresolvable':
			$rw_value = __( 'Bound credential unresolvable', 'signal-and-noise-tools' );
			$rw_meta  = __( 'The bound UUID no longer matches an owned Application Password.', 'signal-and-noise-tools' );
			$rw_pill  = array( 'kind' => 'err', 'text' => __( 'unresolvable', 'signal-and-noise-tools' ) );
			break;
		case 'inactive':
		default:
			$rw_value = __( 'INACTIVE', 'signal-and-noise-tools' );
			$rw_meta  = __( 'No credential bound, so every /mcp-rw call is denied.', 'signal-and-noise-tools' );
			$rw_pill  = array( 'kind' => 'warn', 'text' => __( 'unbound', 'signal-and-noise-tools' ) );
			break;
	}

	$rw_parts = array( $rw_meta );
	if ( is_int( $rw_count ) ) {
		$rw_parts[] = esc_html(
			sprintf(
				/* translators: %d: live count of write-door tools. */
				__( '%d tools', 'signal-and-noise-tools' ),
				$rw_count
			)
		);
	}
	$rw_parts[] = '<code>POST ' . esc_url( $rw_url ) . '</code>';

	$rw_card = array(
		'label'     => __( 'Write door', 'signal-and-noise-tools' ),
		'value'     => $rw_value,
		'meta_html' => implode( ' · ', $rw_parts ),
		'pill'      => $rw_pill,
	);

	$adapter_card = array(
		'label'     => __( 'Adapter', 'signal-and-noise-tools' ),
		'value'     => $adapter_active
			? __( 'Present', 'signal-and-noise-tools' )
			: __( 'Not installed', 'signal-and-noise-tools' ),
		'meta_html' => '<code>' . esc_url( $adapter_url ) . '</code>',
	);

	// The bridge endpoint answers 404 for a closed switch, an undefined
	// SN_BRIDGE_TOKEN and an unknown slug alike — deliberately, so an
	// unauthenticated caller cannot tell a dark switch from a broken deploy.
	// The cost is that the OWNER cannot tell either, and this card is where
	// that cost is paid: on an authenticated surface, the states are named.
	switch ( $remote_state ) {
		case 'constant_killed':
			$remote_value = __( 'Killed in wp-config', 'signal-and-noise-tools' );
			$remote_meta  = sprintf(
				/* translators: %s: SN_MCP_REMOTE_DISABLED wrapped in <code>. */
				__( 'The %s constant is set in wp-config, so the toggle below cannot reopen this door.', 'signal-and-noise-tools' ),
				'<code>SN_MCP_REMOTE_DISABLED</code>'
			);
			$remote_pill = array( 'kind' => 'err', 'text' => __( 'killed', 'signal-and-noise-tools' ) );
			break;
		case 'secret_missing':
			$remote_value = __( 'On, but unusable', 'signal-and-noise-tools' );
			$remote_meta  = sprintf(
				/* translators: %s: SN_BRIDGE_TOKEN wrapped in <code>. */
				__( 'The door is on but %s is not defined in wp-config.php, so the bridge route is not registered. The endpoint answers 404 — the same as a closed door — on purpose.', 'signal-and-noise-tools' ),
				'<code>SN_BRIDGE_TOKEN</code>'
			);
			$remote_pill = array( 'kind' => 'err', 'text' => __( 'secret missing', 'signal-and-noise-tools' ) );
			break;
		case 'bridge_ready':
			$remote_value = __( 'Bridge ready', 'signal-and-noise-tools' );
			$remote_meta  = __( 'The switch is on and the bridge secret is defined, so the route is registered and answers the Worker.', 'signal-and-noise-tools' );
			if ( function_exists( 'sn_mcp_remote_log_summary_text' ) ) {
				$remote_meta .= '<br>' . esc_html( sn_mcp_remote_log_summary_text() );
			}
			$remote_pill  = array( 'kind' => 'ok', 'text' => __( 'ready', 'signal-and-noise-tools' ) );
			break;
		case 'option_off':
		default:
			$remote_value = __( 'Switched off', 'signal-and-noise-tools' );
			$remote_meta  = sprintf(
				/* translators: %s: sn_mcp_remote_enabled wrapped in <code>. */
				__( 'The %s option is off, so the endpoint answers 404.', 'signal-and-noise-tools' ),
				'<code>sn_mcp_remote_enabled</code>'
			);
			$remote_pill = array( 'kind' => 'warn', 'text' => __( 'off', 'signal-and-noise-tools' ) );
			break;
	}

	$remote_card = array(
		'label'     => __( 'Remote door', 'signal-and-noise-tools' ),
		'value'     => $remote_value,
		'meta_html' => $remote_meta,
		'pill'      => $remote_pill,
	);

	return array( $read_card, $rw_card, $adapter_card, $remote_card );
}

/**
 * LIVE gatherer for sn_admin_mcp_status_cards(). Every cross-lane call is
 * behind function_exists() / class_exists(). Missing allowlists stay null
 * (unknown ≠ zero). Missing guard layer falls back to inactive.
 *
 * @return array State keys documented on sn_admin_mcp_status_cards().
 */
function sn_admin_mcp_status_state() {
	$read_count = function_exists( 'sn_mcp_allowlist' ) ? count( sn_mcp_allowlist() ) : null;
	$rw_count   = function_exists( 'sn_mcp_rw_allowlist' ) ? count( sn_mcp_rw_allowlist() ) : null;

	$can_url   = function_exists( 'rest_url' ) && function_exists( 'sn_mcp_namespace' );
	$read_url  = $can_url ? (string) rest_url( sn_mcp_namespace() . '/mcp' ) : '';
	$rw_url    = $can_url ? (string) rest_url( sn_mcp_namespace() . '/mcp-rw' ) : '';

	$adapter_active = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	$adapter_url    = function_exists( 'rest_url' )
		? (string) rest_url( 'mcp/mcp-adapter-default-server' )
		: '';

	$rw_name      = '';
	$rw_last_used = 0;

	if ( function_exists( 'sn_mcp_rw_kill_switch_constant_disabled' ) && sn_mcp_rw_kill_switch_constant_disabled() ) {
		$rw_state = 'constant_killed';
	} elseif ( function_exists( 'sn_mcp_rw_enabled_option' ) && ! sn_mcp_rw_enabled_option() ) {
		$rw_state = 'option_off';
	} elseif ( ! function_exists( 'sn_mcp_rw_bound_uuid' ) || '' === sn_mcp_rw_bound_uuid() ) {
		$rw_state = 'inactive';
	} else {
		$bound_uuid     = sn_mcp_rw_bound_uuid();
		$passwords      = class_exists( 'WP_Application_Passwords' )
			? (array) WP_Application_Passwords::get_user_application_passwords( get_current_user_id() )
			: array();
		$bound_password = null;
		foreach ( $passwords as $pw ) {
			if ( is_array( $pw ) && ! empty( $pw['uuid'] ) && hash_equals( (string) $pw['uuid'], $bound_uuid ) ) {
				$bound_password = $pw;
				break;
			}
		}
		if ( null !== $bound_password ) {
			$rw_state     = 'bound';
			$rw_name      = (string) ( $bound_password['name'] ?? '' );
			$rw_last_used = ! empty( $bound_password['last_used'] ) ? (int) $bound_password['last_used'] : 0;
		} else {
			$rw_state = 'unresolvable';
		}
	}

	// Remote door. Everything after the constant is guarded, because this file
	// renders in test harnesses that do not load the bridge lane at all.
	$remote_state = 'option_off';
	if ( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ) {
		$remote_state = 'constant_killed';
	} elseif ( function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged() ) {
		// The door is open. Distinguish "ready" from "on but unusable", because
		// the endpoint deliberately answers 404 for both and the owner cannot
		// tell them apart from outside.
		$remote_state = ( function_exists( 'sn_bridge_secret' ) && '' !== sn_bridge_secret() )
			? 'bridge_ready'
			: 'secret_missing';
	}

	return array(
		'read_count'     => $read_count,
		'read_url'       => $read_url,
		'rw_count'       => $rw_count,
		'rw_url'         => $rw_url,
		'rw_state'       => $rw_state,
		'rw_name'        => $rw_name,
		'rw_last_used'   => $rw_last_used,
		'adapter_active' => $adapter_active,
		'adapter_url'    => $adapter_url,
		'remote_state'   => $remote_state,
	);
}

/**
 * Echo the remote-door toggle. The house admin form pattern: a POST back to the
 * same screen carrying the shared sn_theme_options_nonce and an sn_action, read
 * by the existing options handler — no settings-API registration, no bespoke
 * admin_post_ hook.
 *
 * The checkbox is disabled when SN_MCP_REMOTE_DISABLED is set: a door killed in
 * wp-config must not look reopenable from the web.
 *
 * @return void
 */
function sn_admin_render_mcp_remote_toggle() {
	$constant_killed = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$door_open       = function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged();
	?>
	<form method="post">
		<?php wp_nonce_field( 'sn_theme_options_nonce' ); ?>
		<input type="hidden" name="sn_action" value="remote_toggle" />
		<label>
			<input type="checkbox" name="sn_remote_enabled" value="1"
				<?php checked( $door_open ); ?>
				<?php disabled( $constant_killed ); ?> />
			<?php esc_html_e( 'Remote analytics door enabled', 'signal-and-noise-tools' ); ?>
		</label>
		<?php submit_button( __( 'Save', 'signal-and-noise-tools' ), 'secondary' ); ?>
	</form>
	<?php
}

/**
 * Echo the MCP status glance. Silent when the grid helper is absent.
 *
 * @return void
 */
function sn_admin_render_mcp_status_glance() {
	if ( ! function_exists( 'sn_admin_glance_grid' ) ) {
		return;
	}
	sn_admin_glance_grid( sn_admin_mcp_status_cards( sn_admin_mcp_status_state() ) );
	sn_admin_render_mcp_remote_toggle();
}
