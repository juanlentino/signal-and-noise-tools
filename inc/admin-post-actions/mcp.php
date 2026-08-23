<?php
/**
 * Signal & Noise — admin POST handlers: MCP read/write credential binding and the remote door toggle.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: bind_mcp_rw_credential, remote_toggle
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * R9 (v9.51.0, lane SEC-C): bind (or unbind) the MCP write-door credential
 * from the Tools → MCP leaf's binding form
 * (inc/admin-forms/mcp-connect.php's sn_admin_render_mcp_rw_binding()).
 *
 * sn_handle_admin_post() (inc/admin-post-handler.php) already ran
 * check_admin_referer() + current_user_can('manage_options') before this
 * handler is ever dispatched — the capability check below is a defensive
 * re-verification (the same "never trust the dispatcher alone" posture every
 * other security-sensitive handler in this file takes for its own per-
 * resource check, e.g. sn_handle_tag_ai_apply()'s edit_post gate), reachable
 * in practice only when this function is called directly (as the unit tests
 * do) rather than through the real POST dispatch path.
 *
 * OWNERSHIP CHECK (the load-bearing part of this handler): manage_options
 * says nothing about which Application Password the submitted UUID names —
 * that value is fully attacker-controlled POST input. Binding it without
 * verifying it belongs to the CURRENT user's own Application Passwords would
 * let anyone who can reach this form point the write door's R1 credential
 * check (inc/mcp/mcp-rw-guard.php) at a UUID for a DIFFERENT application
 * password entirely — an unrelated credential the submitting admin may not
 * even hold. WP_Application_Passwords::get_user_application_passwords() is
 * itself scoped to one user id, so this loop can only ever match a password
 * that already belongs to get_current_user_id(); nothing here trusts the
 * $_POST value beyond that membership test.
 *
 * '' (the form's "— Unbind —" option) always succeeds without an ownership
 * check — an empty string is a legal, explicit clear per
 * sn_mcp_set_rw_bound_uuid()'s own contract, and there is no owner to verify
 * for "nothing bound".
 *
 * is_string guard (project convention — a crafted sn_mcp_rw_uuid[]= array
 * POST would otherwise warn on the string cast): non-string payloads are
 * treated as absent, not cast.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'mcp_rw_bound' | 'mcp_rw_unbound' | 'mcp_rw_bind_invalid'.
 */
function sn_handle_bind_mcp_rw_credential( $post ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'mcp_rw_bind_invalid';
	}
	if ( ! function_exists( 'sn_mcp_set_rw_bound_uuid' ) ) {
		return 'mcp_rw_bind_invalid';
	}

	$raw  = isset( $post['sn_mcp_rw_uuid'] ) && is_string( $post['sn_mcp_rw_uuid'] ) ? $post['sn_mcp_rw_uuid'] : '';
	$uuid = trim( sanitize_text_field( wp_unslash( $raw ) ) );

	if ( '' === $uuid ) {
		return sn_mcp_set_rw_bound_uuid( '' ) ? 'mcp_rw_unbound' : 'mcp_rw_bind_invalid';
	}

	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return 'mcp_rw_bind_invalid';
	}
	$owned = false;
	foreach ( (array) WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) as $pw ) {
		if ( is_array( $pw ) && ! empty( $pw['uuid'] ) && hash_equals( (string) $pw['uuid'], $uuid ) ) {
			$owned = true;
			break;
		}
	}
	if ( ! $owned ) {
		return 'mcp_rw_bind_invalid'; // never bind a UUID this user doesn't hold.
	}

	return sn_mcp_set_rw_bound_uuid( $uuid ) ? 'mcp_rw_bound' : 'mcp_rw_bind_invalid';
}

/**
 * Toggle the remote analytics door (R3 §3D).
 *
 * THE PHONE-REACHABLE CONTROL. sn_mcp_remote_enabled is absent by default and
 * fails CLOSED, so without this handler the door needs WP-CLI to turn on and
 * WP-CLI to turn off — a terminal in both directions. The "off" half is the one
 * that matters at 2am away from a laptop.
 *
 * SN_MCP_REMOTE_DISABLED WINS UNCONDITIONALLY. A wp-config kill that a web form
 * could undo would be decorative. Same shape as sn_handle_cf_save() refusing to
 * override SN_CLOUDFLARE_API_TOKEN.
 *
 * The secret itself has no UI here, deliberately: an option is readable by
 * anything that reaches the database, while wp-config.php is readable by no web
 * request. Stopping the door is urgent and belongs on the web; rotating the
 * secret is rare and belongs on a laptop.
 *
 * @param array $post Raw $_POST.
 * @return string Flash key.
 */
function sn_handle_remote_toggle( $post ) {
	if ( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ) {
		return 'remote_constant_locked';
	}
	$on = ! empty( $post['sn_remote_enabled'] );
	update_option( 'sn_mcp_remote_enabled', $on, false );
	return $on ? 'remote_enabled' : 'remote_disabled';
}
