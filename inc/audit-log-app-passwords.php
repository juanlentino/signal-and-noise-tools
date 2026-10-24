<?php
/**
 * Signal & Noise Tools: application passwords in the audit log (2026-10-24).
 *
 * An app password skips the login door, the edge guard and 2FA, and a refused
 * Basic-auth sign-in never fires wp_login_failed: core's
 * wp_validate_application_password() calls wp_authenticate_application_password()
 * directly from determine_current_user. Core's own app-password hooks feed
 * the log instead:
 *
 *   - a success is counted per day (the MCP door signs in on every call);
 *   - a refusal is counted per day, and the first refusal of each reason code
 *     on a day is a change row, so a Basic-auth spray cannot push real
 *     changes out of the capped list;
 *   - creating, renaming and revoking a password are change rows on the
 *     account (an administrator account is high: the password signs in past 2FA).
 *
 * The day counts live in their own small option, autoload off, so the hot path
 * never rewrites the autoloaded audit blob. Never stored: the attempted
 * username, any password or hash, the uuid, an IP address, core's error text.
 *
 * The two auth hooks run inside determine_current_user, where
 * wp_get_current_user() would re-enter the filter and re-run the check; they
 * never ask who is signed in.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_AUDIT_APP_PW_OPTION = 'sn_audit_app_passwords_v1';

/**
 * The day counts: { 'Y-m-d' => { ok:int, failed:int, codes:{ code:int } } }.
 *
 * @return array<string,array>
 */
function snt_audit_app_pw_days() {
	$days = get_option( SN_AUDIT_APP_PW_OPTION, array() );
	return is_array( $days ) ? $days : array();
}

/**
 * Add one to today's count.
 *
 * @param string $field ok or failed.
 * @param string $code  The refusal reason, for failed.
 * @return int Today's count for that code (failed), else for the field.
 */
function snt_audit_app_pw_bump( $field, $code = '' ) {
	$days  = snt_audit_app_pw_days();
	$today = snt_audit_today_key();
	$row   = isset( $days[ $today ] ) && is_array( $days[ $today ] ) ? $days[ $today ] : array();
	$row  += array( 'ok' => 0, 'failed' => 0, 'codes' => array() );

	$row[ $field ] = (int) $row[ $field ] + 1;
	$count         = $row[ $field ];
	if ( '' !== $code ) {
		$row['codes'][ $code ] = (int) ( $row['codes'][ $code ] ?? 0 ) + 1;
		$count                 = $row['codes'][ $code ];
	}
	$days[ $today ] = $row;
	update_option( SN_AUDIT_APP_PW_OPTION, $days, false );
	return $count;
}

/**
 * Drop day counts older than the cutoff. Called from snt_audit_prune_impl().
 *
 * @param string $cutoff_date Y-m-d; older days go.
 */
function snt_audit_app_pw_prune( $cutoff_date ) {
	$days = snt_audit_app_pw_days();
	$kept = array_filter(
		$days,
		static function ( $date ) use ( $cutoff_date ) {
			return (string) $date >= $cutoff_date;
		},
		ARRAY_FILTER_USE_KEY
	);
	if ( count( $kept ) !== count( $days ) ) {
		update_option( SN_AUDIT_APP_PW_OPTION, $kept, false );
	}
}

/**
 * A refused app-password sign-in. Only core's reason code is kept.
 *
 * @param WP_Error $error The authentication error.
 */
function snt_audit_app_pw_failed_cb( $error ) {
	$code = is_object( $error ) && method_exists( $error, 'get_error_code' ) ? (string) $error->get_error_code() : '';
	$code = substr( (string) preg_replace( '/[^a-z0-9_-]/i', '', $code ), 0, 64 );
	$code = '' !== $code ? $code : 'unknown';
	if ( 1 === snt_audit_app_pw_bump( 'failed', $code ) ) {
		snt_audit_record_change_impl( 'app_password_failed', $code, '', false, '' );
	}
}
add_action( 'application_password_failed_authentication', 'snt_audit_app_pw_failed_cb', 10, 1 );

/**
 * A successful app-password sign-in: counted, never a row.
 */
function snt_audit_app_pw_ok_cb() {
	snt_audit_app_pw_bump( 'ok' );
}
add_action( 'application_password_did_authenticate', 'snt_audit_app_pw_ok_cb', 10, 0 );

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
