<?php
/**
 * Signal & Noise Tools: app-password lifecycle rows in the audit log (2026-10-24).
 *
 * Core's wp_create_application_password, wp_update_application_password and
 * wp_delete_application_password become change rows on the account
 * (snt_audit_record_change_impl). The row keeps the password name only: never
 * the new plaintext password (the creation callback takes two arguments, so it
 * is never passed in), the hash, the uuid or the last IP. An app password on an
 * administrator account is high: it signs in past 2FA. These fire after
 * authentication has settled, so asking who made the change is safe here.
 * Sign-in counts and refusals: inc/audit-log-app-passwords.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The password name, never anything else from the item.
 *
 * @param array $item Core's app-password item.
 * @return string
 */
function snt_audit_app_pw_name( $item ) {
	return is_array( $item ) ? substr( (string) ( $item['name'] ?? '' ), 0, 100 ) : '';
}

/**
 * Created. Two arguments only: the new plaintext password is never passed in.
 *
 * @param int   $user_id Account ID.
 * @param array $item    The new item.
 */
function snt_audit_app_pw_created_cb( $user_id, $item ) {
	$user = get_userdata( (int) $user_id );
	$high = $user && in_array( 'administrator', (array) $user->roles, true );
	snt_audit_record_change_impl( 'app_password_created', snt_audit_change_login( $user_id ), snt_audit_app_pw_name( $item ), $high );
}
add_action( 'wp_create_application_password', 'snt_audit_app_pw_created_cb', 10, 2 );

/**
 * Renamed (core updates only the name). Core fires this for a save with
 * the same name too, so a no-op rename is a row as well.
 *
 * @param int   $user_id Account ID.
 * @param array $item    The item after the update.
 */
function snt_audit_app_pw_updated_cb( $user_id, $item ) {
	snt_audit_record_change_impl( 'app_password_updated', snt_audit_change_login( $user_id ), snt_audit_app_pw_name( $item ) );
}
add_action( 'wp_update_application_password', 'snt_audit_app_pw_updated_cb', 10, 2 );

/**
 * Revoked.
 *
 * @param int   $user_id Account ID.
 * @param array $item    The deleted item.
 */
function snt_audit_app_pw_revoked_cb( $user_id, $item ) {
	snt_audit_record_change_impl( 'app_password_revoked', snt_audit_change_login( $user_id ), snt_audit_app_pw_name( $item ) );
}
add_action( 'wp_delete_application_password', 'snt_audit_app_pw_revoked_cb', 10, 2 );
