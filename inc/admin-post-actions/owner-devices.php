<?php
/**
 * Signal & Noise Tools: admin-post actions for owner device enrollment
 * (SN Traffic Lanes, Phase 1; the logic is inc/owner-devices.php).
 *
 * Actions served: owner_device_enroll, owner_device_revoke, owner_device_revoke_all.
 * The dispatcher (inc/admin-post-handler.php) already checked the action's
 * nonce and manage_options; revoke also checks its per-row nonce.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<string,mixed> $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_owner_device_enroll( $post ) {
	$label = isset( $post['sn_owner_device_label'] ) ? sanitize_text_field( wp_unslash( (string) $post['sn_owner_device_label'] ) ) : '';
	return sn_owner_device_enroll( $label, time() );
}

/**
 * @param array<string,mixed> $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_owner_device_revoke( $post ) {
	$row   = isset( $post['sn_owner_row'] ) ? (int) $post['sn_owner_row'] : -1;
	$nonce = isset( $post['sn_owner_row_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $post['sn_owner_row_nonce'] ) ) : '';
	return sn_owner_device_revoke( $row, $nonce, time() );
}

/**
 * @param array<string,mixed> $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_owner_device_revoke_all( $post ) {
	unset( $post );
	return sn_owner_device_revoke_all( time() );
}
