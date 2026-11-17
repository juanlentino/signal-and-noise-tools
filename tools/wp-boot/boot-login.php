<?php
/**
 * tools/wp-boot only: ?sn_boot_login=1 from 127.0.0.1 signs in as user 1, since
 * the plugin hides wp-login.php. Acts only when the rig's wp-config defines
 * SN_WP_BOOT; never ships in the plugin.
 */
add_action( 'init', static function () {
	if ( ! defined( 'SN_WP_BOOT' ) || ! SN_WP_BOOT || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) || empty( $_GET['sn_boot_login'] ) ) {
		return;
	}
	wp_set_current_user( 1 );
	wp_set_auth_cookie( 1 );
	wp_safe_redirect( admin_url() );
	exit;
}, 0 );
