<?php
/**
 * Tools > MCP: the read door's optional credential binding (2026-10-26 MCP doors
 * audit, N0's read half). Twin of apps/sn-dashboard/parts/leaves/
 * ai-mcp-connect-read-binding.php: same states, field, action and words.
 *
 * Unlike the write door, the read door needs no binding: unbound, any
 * administrator Application Password works there, as always. Binding one
 * limits THAT password to /mcp and the read door's tools
 * (inc/mcp/mcp-rw-credential-scope.php). The write door's bound password is
 * left out of the list: each door's scope refuses the other door's routes.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read binding's state, shared by both twins.
 *
 * @return array{bound:string,passwords:array,bound_password:?array,off:bool}
 */
function sn_admin_mcp_read_binding_state() {
	$bound     = function_exists( 'sn_mcp_read_bound_uuid' ) ? sn_mcp_read_bound_uuid() : '';
	$rw_bound  = function_exists( 'sn_mcp_rw_bound_uuid' ) ? strtolower( sn_mcp_rw_bound_uuid() ) : '';
	$passwords = class_exists( 'WP_Application_Passwords' ) ? (array) WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) : array();
	$passwords = array_values( array_filter( $passwords, static fn( $pw ) => is_array( $pw ) && ! empty( $pw['uuid'] ) && strtolower( (string) $pw['uuid'] ) !== $rw_bound ) );
	$match     = null;
	foreach ( $passwords as $pw ) {
		if ( '' !== $bound && hash_equals( strtolower( (string) $pw['uuid'] ), $bound ) ) {
			$match = $pw;
		}
	}
	return array(
		'bound'          => $bound,
		'passwords'      => $passwords,
		'bound_password' => $match,
		'off'            => function_exists( 'sn_mcp_read_kill_switch_engaged' ) && sn_mcp_read_kill_switch_engaged(),
	);
}

/**
 * The status line, in the same words on both twins (plain text, escaped by the caller).
 *
 * @param array $s From sn_admin_mcp_read_binding_state().
 * @return string
 */
function sn_admin_mcp_read_binding_status( array $s ) {
	if ( '' === $s['bound'] ) {
		return __( 'Not bound: any administrator Application Password works on the read door, as before. Bind one to limit that password to the read door and its tools.', 'signal-and-noise-tools' );
	}
	if ( null === $s['bound_password'] ) {
		return __( 'A read-door credential is bound, but it no longer matches any of your own Application Passwords: it may have been revoked, or belongs to a different user. Bind a current one, or unbind.', 'signal-and-noise-tools' );
	}
	/* translators: %s: the bound Application Password's name. */
	return sprintf( __( 'Bound to %s: it works only on /mcp and the read door\'s tools; other credentials still reach the read door.', 'signal-and-noise-tools' ), (string) ( $s['bound_password']['name'] ?? '' ) );
}

/**
 * The classic Tools > MCP form.
 */
function sn_admin_render_mcp_read_binding() {
	$s = sn_admin_mcp_read_binding_state();
	echo '<p class="sn-callout-h">' . esc_html__( 'Bind the read-door credential', 'signal-and-noise-tools' ) . '</p>';
	if ( $s['off'] ) {
		echo '<p><strong>' . esc_html__( 'The read door is off.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'Binding a credential does not open it while it is off.', 'signal-and-noise-tools' ) . '</p>';
	}
	echo '<p>' . esc_html( sn_admin_mcp_read_binding_status( $s ) ) . '</p>';
	if ( empty( $s['passwords'] ) ) {
		echo '<p>' . esc_html__( 'You have no other Application Password to bind: create one under your profile first.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_bind_mcp_read_credential' );
	echo '<input type="hidden" name="action" value="sn_bind_mcp_read_credential">';
	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mcp_read_uuid">' . esc_html__( 'Application Password', 'signal-and-noise-tools' ) . '</label><select id="sn_mcp_read_uuid" name="sn_mcp_read_uuid">';
	$resting = null !== $s['bound_password'] ? $s['bound'] : '';
	foreach ( sn_admin_mcp_rw_options( $s['passwords'], $s['bound'], null !== $s['bound_password'] ) as $uuid => $label ) {
		echo '<option value="' . esc_attr( (string) $uuid ) . '"' . selected( $resting, strtolower( (string) $uuid ), false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select><p class="sn-field-helper">' . esc_html__( 'Your profile\'s Application Passwords, minus the write door\'s: each door needs its own.', 'signal-and-noise-tools' ) . '</p></div>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save read-door credential', 'signal-and-noise-tools' ) . '</button>';
	if ( '' !== $s['bound'] ) {
		echo ' <button type="submit" form="sn-mcp-read-unbind" class="button button-link-delete" data-snt-confirm="' . esc_attr__( 'Unbind the read-door credential? That Application Password stops being limited to the read door.', 'signal-and-noise-tools' ) . '">' . esc_html__( 'Unbind', 'signal-and-noise-tools' ) . '</button>';
	}
	echo '</div></form>';
	if ( '' !== $s['bound'] ) {
		echo '<form id="sn-mcp-read-unbind" method="post" action="' . esc_url( sn_admin_post_url() ) . '" hidden>';
		wp_nonce_field( 'sn_bind_mcp_read_credential', '_wpnonce', true, true );
		echo '<input type="hidden" name="action" value="sn_bind_mcp_read_credential"><input type="hidden" name="sn_mcp_read_uuid" value=""><input type="hidden" name="sn_mcp_read_unbind" value="1"></form>';
	}
}
