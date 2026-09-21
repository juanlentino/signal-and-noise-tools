<?php
/**
 * Stubs for the admin-post form helpers (inc/admin-post-handler.php) in
 * render suites that do not load the dispatcher: sn_admin_post_url() and
 * sn_admin_post_button() print a recognisable URL, esc_url() passes through.
 * The real helpers are pinned in tests/openstation-host.php (#1614).
 *
 * @package SignalNoiseTools
 */

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'sn_admin_post_url' ) ) {
	function sn_admin_post_url( $action = '' ) {
		$url = 'https://example.test/wp-admin/admin-post.php';
		return '' === (string) $action ? $url : $url . '?_wpnonce=nonce-sn_' . $action;
	}
}
if ( ! function_exists( 'sn_admin_post_button' ) ) {
	function sn_admin_post_button( $action ) {
		return ' name="action" value="sn_' . $action . '" formaction="' . sn_admin_post_url( $action ) . '"';
	}
}
