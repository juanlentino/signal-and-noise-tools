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
			? sprintf( /* translators: %s: relative time element. */ \snt_kit_esc( __( 'Last used %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( $last_used ) )
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
 * The two native doors' live allowlists, for AI › Agent tools
 * (ai-agent-tools-doors.php paints them). Null when a reader is not loaded:
 * unknown, never an empty door. The read stays in this file because the door
 * manifest suite lists the files allowed to name the allowlist functions.
 *
 * @return array{read:?array,rw:?array}
 */
function mcp_connect_door_slugs() {
	return array(
		'read' => function_exists( 'sn_mcp_allowlist' ) ? array_values( (array) sn_mcp_allowlist() ) : null,
		'rw'   => function_exists( 'sn_mcp_rw_allowlist' ) ? array_values( (array) sn_mcp_rw_allowlist() ) : null,
	);
}

/**
 * Deep links: AI › Agent tools (an in-window link, where the door
 * inventories live, instead of a second copy of them here), the Tools menu (a
 * door to another admin screen) and the Abilities catalog doc (external link).
 *
 * @return string
 */
function mcp_connect_deep_links_html() {
	$links = '<ul class="snt-plain"><li>' . \snt_kit_go( __( 'Agent tools: every tool each door exposes, and its calls', 'signal-and-noise-tools' ), array( 'sub' => 'agent-tools' ) ) . '</li>'
		. '<li>' . \snt_kit_door( __( 'Tools menu. Abilities Explorer, if the AI plugin adds it', 'signal-and-noise-tools' ), function_exists( 'admin_url' ) ? admin_url( 'tools.php' ) : '' ) . '</li>'
		. '<li>' . \snt_kit_link( __( 'The Abilities catalog (this repo’s docs)', 'signal-and-noise-tools' ), 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ) . '</li></ul>';
	return \snt_kit_section( __( 'More', 'signal-and-noise-tools' ), $links );
}
