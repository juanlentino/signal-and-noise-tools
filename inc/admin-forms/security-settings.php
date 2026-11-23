<?php
/**
 * Signal & Noise: Settings > Security, the classic section.
 *
 * The second pane of the Settings tab, two settings:
 *
 * 1. The login address. It came WHOLE from Security > Login URL: that leaf
 *    was nothing but the slug setting and its own state (module status, slug
 *    form, current URL, emergency unlock), so it moved rather than leave a
 *    leaf holding only a pointer. sn_admin_render_login_section()
 *    (inc/admin-forms/login.php) is called as it is, not retyped, so the
 *    form keeps its nonce, its action and every warning it had.
 *    sn_admin_subtab_moves() sends the old address here.
 * 2. Audit log "Retention (days)", from Security > Audit log. That leaf keeps
 *    its log and one line saying where the field went. The form is
 *    snt_audit_log_render_retention_form() (inc/audit-log-admin.php).
 *
 * Nothing about saving changed: save_login and audit_save_retention, the same
 * fields, the same handlers. A form posts back to the address it was painted
 * on (sn_admin_post_url()), so a save from this pane returns to it.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-security.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the line an old screen keeps.
 *
 * @return string
 */
function snt_security_settings_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'security' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=security' );
}

/**
 * The sentence the Audit log leaf keeps, up to the link. Shared by both
 * twins. It names the setting and never a value.
 *
 * @return string
 */
function snt_security_settings_moved_text() {
	return __( 'The retention setting moved to', 'signal-and-noise-tools' );
}

/**
 * That line on the classic Audit log leaf, with its link to the pane.
 *
 * @return void
 */
function snt_security_settings_moved_line() {
	echo '<p class="sn-field-helper">' . esc_html( snt_security_settings_moved_text() ) . ' <a href="' . esc_url( snt_security_settings_url() ) . '">'
		. esc_html__( 'Settings › Security', 'signal-and-noise-tools' ) . '</a>.</p>';
}

/**
 * The section: the login address card and the retention card, side by side.
 * The pane is 'wide' in the registry, so the first card is drawn here; the
 * retention form is its own card.
 *
 * @return void
 */
function sn_admin_render_security_settings_section() {
	echo '<div class="sn-2up">';
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Login URL', 'signal-and-noise-tools' ) . '</h2>';
	if ( function_exists( 'sn_admin_render_login_section' ) ) {
		sn_admin_render_login_section();
	}
	echo '</div>';
	if ( function_exists( 'snt_audit_log_render_retention_form' ) ) {
		snt_audit_log_render_retention_form();
	}
	echo '</div>';
}
