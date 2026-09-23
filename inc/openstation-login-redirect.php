<?php
/**
 * Signal & Noise Tools: land admins in OpenStation after login.
 *
 * Owner, 2026-09-22: /wp-admin/ then OpenStation cost a second full page load
 * (the Dashboard, then the shell) on every login. When the login carries no
 * specific destination, send an admin straight to admin.php?page=openstation.
 *
 * Only the DEFAULT destination is replaced: a login that asked for a page
 * (redirect_to=/wp-admin/post.php?…, the PWA start_url, a reauth) keeps it.
 * Nothing is read from the request here; the target is built with admin_url().
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string           $redirect_to           Where WordPress will send the user.
 * @param string           $requested_redirect_to The redirect_to the login form carried.
 * @param WP_User|WP_Error $user                  The user logging in.
 * @return string
 */
function snt_os_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->has_cap( 'manage_options' ) || ! snt_os_active() ) {
		return $redirect_to;
	}
	$default = admin_url();
	if ( '' !== (string) $requested_redirect_to && untrailingslashit( (string) $requested_redirect_to ) !== untrailingslashit( $default ) ) {
		return $redirect_to;
	}
	return admin_url( 'admin.php?page=openstation' );
}
add_filter( 'login_redirect', 'snt_os_login_redirect', 10, 3 );
