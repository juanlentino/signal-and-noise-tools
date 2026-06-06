<?php
/**
 * Signal & Noise Tools — WP version pre-warning admin notice (v4.6.0).
 *
 * Dismissible admin notice rendered on every wp-admin page when the
 * current WP version is < 7.0. Announces that v5.0.0 will require WP 7.0.
 *
 * Persisted via user-meta key `snt_dismissed_wp_version_notice_v460` so
 * each admin user can dismiss independently. The version-suffix in the
 * meta key allows future minors to re-introduce the notice if needed.
 *
 * v5.0.0 hard-raises Requires at least: 7.0, after which this file is
 * deleted entirely (along with its require_once in the plugin bootstrap).
 *
 * @package SignalNoiseTools
 * @since 4.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test seam: returns the current WordPress version.
 *
 * Wraps the global $wp_version so tests can stub it. Test stub override:
 * if $GLOBALS['SNT_WP_VERSION_OVERRIDE'] is non-empty, returns it.
 * (Global, not constant — constants are immutable and tests need to
 * exercise both <7.0 and >=7.0 paths in a single run.)
 *
 * @return string e.g. "6.4.2" or "7.0".
 */
function snt_get_wp_version() {
	if ( ! empty( $GLOBALS['SNT_WP_VERSION_OVERRIDE'] ) ) {
		return (string) $GLOBALS['SNT_WP_VERSION_OVERRIDE'];
	}
	global $wp_version;
	return is_string( $wp_version ) ? $wp_version : '0.0';
}

/**
 * Renders the WP < 7.0 admin notice if applicable.
 *
 * Hooked to admin_notices. Bails when:
 *   - WP version is >= 7.0 (the floor v5.0.0 will require)
 *   - Current user has dismissed via user-meta sentinel
 *   - User lacks manage_options (the notice is for site admins)
 */
function snt_render_wp_version_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( version_compare( snt_get_wp_version(), '7.0', '>=' ) ) {
		return;
	}
	$user_id = get_current_user_id();
	if ( $user_id && get_user_meta( $user_id, 'snt_dismissed_wp_version_notice_v460', true ) ) {
		return;
	}

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'snt_dismiss_wp_version_notice', '1' ),
		'snt_dismiss_wp_version_notice'
	);

	printf(
		'<div class="notice notice-warning is-dismissible"><p><strong>Signal &amp; Noise Tools:</strong> v5.0.0 will require WordPress 7.0 (you are on %s). Plan your upgrade — the v5.0.0 release will refuse to install on WP &lt; 7.0.</p><p><a href="%s">Dismiss this notice</a></p></div>',
		esc_html( snt_get_wp_version() ),
		esc_url( $dismiss_url )
	);
}
add_action( 'admin_notices', 'snt_render_wp_version_notice' );

/**
 * Handles dismiss-link click — records user-meta sentinel.
 */
function snt_handle_wp_version_notice_dismiss() {
	if ( empty( $_GET['snt_dismiss_wp_version_notice'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'snt_dismiss_wp_version_notice' );

	update_user_meta( get_current_user_id(), 'snt_dismissed_wp_version_notice_v460', '1' );

	wp_safe_redirect( remove_query_arg( array( 'snt_dismiss_wp_version_notice', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'snt_handle_wp_version_notice_dismiss' );
