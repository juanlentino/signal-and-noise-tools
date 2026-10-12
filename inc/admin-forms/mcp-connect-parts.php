<?php
/**
 * Signal & Noise: AI › MCP Clients and Agent tools, the classic helpers split
 * from mcp-connect.php and mcp-connect-status.php (house line cap, review on
 * #2022): the write door's off reason and credential picker, the binding form
 * with its confirmed Unbind, the remote door and the per-tool list both doors
 * share, and the adapter in two sentences. The native leaves read the same
 * helpers, so the twins cannot drift.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why the write door is off, the same words on its card and in its
 * credential box ('' unless the state is constant_killed or option_off).
 * Escaped HTML: the constant or the option as code.
 *
 * @param array $state The status state.
 * @return string
 */
function sn_admin_mcp_status_off_reason( array $state ) {
	switch ( (string) ( $state['rw_state'] ?? '' ) ) {
		case 'constant_killed':
			/* translators: %s: SN_MCP_RW_DISABLED wrapped in <code>. */
			return sprintf( esc_html__( 'The %s constant is set in wp-config.php, and only removing it reopens this door.', 'signal-and-noise-tools' ), '<code>SN_MCP_RW_DISABLED</code>' );
		case 'option_off':
			/* translators: %s: sn_mcp_rw_enabled wrapped in <code>. */
			return sprintf( esc_html__( 'The write door’s switch is off (the %s option).', 'signal-and-noise-tools' ), '<code>sn_mcp_rw_enabled</code>' );
	}
	return '';
}

/**
 * The write-door credential picker's options, the same on both twins: the
 * resting value says the state ("Not bound", or a credential that no longer
 * exists) and is never the action of unbinding, which is its own confirmed
 * button (2026-10-12 audit).
 *
 * @param array  $passwords  The user's Application Passwords.
 * @param string $bound_uuid The bound UUID ('' when none).
 * @param bool   $resolved   Whether the bound UUID matches one of $passwords.
 * @return array<string,string> uuid => label.
 */
function sn_admin_mcp_rw_options( array $passwords, $bound_uuid, $resolved ) {
	$options = array();
	if ( ! $resolved ) {
		$options[''] = '' === (string) $bound_uuid ? __( 'Not bound', 'signal-and-noise-tools' ) : __( 'Bound to a credential this screen can’t find (revoked, or another user’s)', 'signal-and-noise-tools' );
	}
	foreach ( $passwords as $pw ) {
		if ( ! is_array( $pw ) || empty( $pw['uuid'] ) ) {
			continue;
		}
		$label = isset( $pw['name'] ) ? (string) $pw['name'] : '';
		if ( ! empty( $pw['created'] ) && function_exists( 'human_time_diff' ) ) {
			/* translators: 1: Application Password name, 2: relative time. */
			$label = sprintf( __( '%1$s, created %2$s ago', 'signal-and-noise-tools' ), $label, human_time_diff( (int) $pw['created'], time() ) );
		}
		$options[ (string) $pw['uuid'] ] = $label;
	}
	return $options;
}

/**
 * R9 (v9.51.0, lane SEC-C): the write-door credential-binding form, the ONE
 * form on MCP Clients' otherwise read-only classic leaf. Reads the bound state
 * through sn_mcp_rw_bound_uuid() (inc/mcp/mcp-rw-guard.php, lane SEC-A,
 * called through a function_exists() guard and never required directly, like
 * every other cross-lane call here) and cross-references it against the
 * CURRENT user's own Application Passwords
 * (WP_Application_Passwords::get_user_application_passwords()), so the owner
 * sees WHICH credential is bound, by name and last use, not a bare UUID.
 * The states are distinguished on purpose: off (the constant or the switch),
 * unbound (door inactive), bound and resolvable (name and last use shown),
 * and bound but unresolvable (the UUID matches none of this user's own
 * Application Passwords: revoked, or another user's; re-binding is the only
 * way out, and Unbind is its own confirmed button).
 *
 * Submits to sn_handle_bind_mcp_rw_credential() (inc/admin-post-actions/mcp.php)
 * via the plugin's standard admin-post.php contract, action=sn_<action> plus
 * that action's nonce (see inc/admin-forms/login.php for the same minimal
 * shape: no hidden tab/sub fields needed, since sn_admin_post_url() carries
 * them on the query string).
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

	echo '<p class="sn-callout-h">' . esc_html__( 'Bind the write-door credential', 'signal-and-noise-tools' ) . '</p>';

	// 2026-10-12 audit, the native box's states in the same words: an off door
	// says binding does not open it; the select rests on the state, never on
	// the action of unbinding, which is its own confirmed button.
	$state = function_exists( 'sn_admin_mcp_status_state' ) ? sn_admin_mcp_status_state() : array();
	if ( '' !== sn_admin_mcp_status_off_reason( $state ) ) {
		echo '<p><strong>' . esc_html__( 'The write door is off.', 'signal-and-noise-tools' ) . '</strong> ' . sn_admin_mcp_status_off_reason( $state ) . ' ' . esc_html__( 'Binding a credential does not open it while it is off.', 'signal-and-noise-tools' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the reason is escaped where it is built (sn_admin_mcp_status_off_reason()).
	} elseif ( '' === $bound_uuid ) {
		echo '<p><strong>' . esc_html__( 'The write door is INACTIVE.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'No Application Password is bound to it yet, so every call to /mcp-rw is denied. Bind one to turn it on.', 'signal-and-noise-tools' ) . '</p>';
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
		echo '<p>' . esc_html__( 'A write-door credential is bound, but it no longer matches any of your own Application Passwords: it may have been revoked, or belongs to a different user. Bind a current one.', 'signal-and-noise-tools' ) . '</p>';
	}

	if ( empty( $passwords ) ) {
		echo '<p>' . sprintf(
			/* translators: %s: a link to the current user's Application Passwords section. */
			esc_html__( 'You have no Application Passwords yet: create one under %s first.', 'signal-and-noise-tools' ),
			'<a href="' . esc_url( get_edit_profile_url() . '#application-passwords-section' ) . '">' . esc_html__( 'your profile', 'signal-and-noise-tools' ) . '</a>'
		) . '</p>';
		return;
	}

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_bind_mcp_rw_credential' );
	echo '<input type="hidden" name="action" value="sn_bind_mcp_rw_credential">';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_mcp_rw_uuid">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</label>';
	echo '<select id="sn_mcp_rw_uuid" name="sn_mcp_rw_uuid">';
	$resting = null !== $bound_password ? $bound_uuid : '';
	foreach ( sn_admin_mcp_rw_options( $passwords, $bound_uuid, null !== $bound_password ) as $uuid => $label ) {
		echo '<option value="' . esc_attr( (string) $uuid ) . '"' . selected( $resting, (string) $uuid, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="sn-field-helper">' . esc_html__( 'The same list as your profile’s Application Passwords: this only scopes one of them to the write door.', 'signal-and-noise-tools' ) . '</p>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save write-door credential', 'signal-and-noise-tools' ) . '</button>';
	echo '</div>';
	echo '</form>';

	if ( '' !== $bound_uuid ) {
		echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
		wp_nonce_field( 'sn_bind_mcp_rw_credential' );
		echo '<input type="hidden" name="action" value="sn_bind_mcp_rw_credential"><input type="hidden" name="sn_mcp_rw_uuid" value=""><input type="hidden" name="sn_mcp_rw_unbind" value="1">';
		echo '<button type="submit" class="button button-link-delete" data-snt-confirm="' . esc_attr__( 'Unbind the write-door credential? Every write-door call is denied until you bind one again.', 'signal-and-noise-tools' ) . '" data-snt-confirm-danger="1">' . esc_html__( 'Unbind', 'signal-and-noise-tools' ) . '</button>';
		echo '</form>';
	}
}

/**
 * The remote door: the read-only twins the Cloudflare Worker reaches through
 * the bridge, listed with the two native doors since the 2026-10-12 audit
 * (the native leaf's words, ai-agent-tools-doors.php).
 *
 * @param array|null $usage From sn_mcp_telemetry_usage().
 */
function sn_admin_render_mcp_door_remote( $usage = null ) {
	$slugs = function_exists( 'sn_mcp_remote_slugs' ) ? sn_mcp_remote_slugs() : array();
	$url   = function_exists( 'rest_url' ) ? (string) rest_url( 'signal-noise/v1/bridge' ) : '';
	echo '<div class="sn-callout">';
	echo '<p class="sn-callout-h">' . esc_html__( 'Remote door: through the Cloudflare Worker', 'signal-and-noise-tools' ) . '</p>';
	echo '<p>' . esc_html__( 'The Cloudflare Worker’s door: a remote MCP client reaches these read-only twins through the Worker, which signs every call with the bridge secret, and nothing outside this list answers. Each runs under a capability no role holds, granted for that one call.', 'signal-and-noise-tools' ) . '</p>';
	echo '<p><code>POST ' . esc_url( $url ) . '</code> <span class="sn-badge">' . esc_html__( 'read-only', 'signal-and-noise-tools' ) . '</span></p>';
	sn_admin_render_mcp_tool_list( $slugs, $usage );
	echo '</div>';
}

/**
 * A door's tool list, each slug with its calls through a door in the call
 * log's window, or why it has none (sn_admin_mcp_tool_reading()).
 *
 * @param string[]   $slugs The door's slugs.
 * @param array|null $usage From sn_mcp_telemetry_usage().
 */
function sn_admin_render_mcp_tool_list( $slugs, $usage = null ) {
	echo '<ul class="sn-mcp-tool-list">';
	foreach ( (array) $slugs as $slug ) {
		$reading = function_exists( 'sn_admin_mcp_tool_reading' ) ? sn_admin_mcp_tool_reading( (string) $slug, $usage ) : null;
		$text    = null === $reading ? '' : ( '' !== (string) ( $reading['reason'] ?? '' ) ? sprintf( /* translators: 1: "not measured", 2: why, e.g. "the remote door is off". */ __( '%1$s, %2$s', 'signal-and-noise-tools' ), $reading['text'], $reading['reason'] ) : $reading['text'] );
		echo '<li><code>' . esc_html( (string) $slug ) . '</code>' . ( '' === $text ? '' : ': ' . esc_html( $text ) ) . '</li>';
	}
	echo '</ul>';
}

/**
 * The adapter door in two sentences, the same on both twins (2026-10-12
 * audit: the not-installed paragraph ran about 110 words).
 *
 * @param bool $active Whether WP\MCP\Core\McpAdapter is loaded.
 * @return string
 */
function sn_admin_mcp_adapter_sentence( $active ) {
	return $active
		? __( 'The WordPress MCP Adapter plugin is active on this site: it is a separate WordPress plugin, not part of the wp.org “AI” plugin. Its default server carries only abilities that opt in through meta.mcp.public, which here are the read door’s tools, each still gated by its own capability check, at this address:', 'signal-and-noise-tools' )
		: __( 'No MCP Adapter is installed, so the read, write and remote doors are the only MCP endpoints this site serves; the adapter is a separate WordPress plugin (wordpress.org/plugins/mcp-adapter), and the wp.org “AI” plugin lists MCP as coming soon rather than bundling it. Installed, its default server would carry only abilities that opt in through meta.mcp.public, which here are the read door’s tools, at this address:', 'signal-and-noise-tools' );
}
