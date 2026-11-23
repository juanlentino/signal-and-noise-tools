<?php
/**
 * Signal & Noise: Settings > Notifications and reports, the classic section.
 *
 * The first pane of the Settings tab: the three email switches, gathered from
 * the two readings they sat on. The morning brief and the alerts email came
 * from Connections > Cron, the weekly security digest from Security > Login
 * defense. Each brought its hint, its test button and its last-sent lines.
 *
 * Nothing about saving changed. The two forms are the ones the old screens
 * painted (inc/morning-brief.php, inc/security-digest.php), with the same
 * field names and the same two actions, morning_brief_save and
 * security_digest_save. A form posts back to the address it was painted on
 * (sn_admin_post_url()), so a save or a test from this pane returns to it.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-notifications.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the lines the old screens keep.
 *
 * @return string
 */
function snt_notifications_settings_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'notifications' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=notifications' );
}

/**
 * The one line an old screen keeps: what moved, with a link to the pane.
 *
 * @param string $what The sentence up to the link, e.g. "The weekly security digest switch moved to".
 * @return void
 */
function snt_notifications_moved_line( $what ) {
	echo '<p class="sn-field-helper">' . esc_html( $what ) . ' <a href="' . esc_url( snt_notifications_settings_url() ) . '">'
		. esc_html__( 'Settings › Notifications and reports', 'signal-and-noise-tools' ) . '</a>.</p>';
}

/**
 * In-app alert notifications are per device and belong to the system, so the
 * pane only says where that switch is. Shared by both twins.
 *
 * @return string
 */
function snt_notifications_os_pointer_text() {
	return __( 'In-app alert notifications are a system choice: set them in OS Settings › Signal & Noise.', 'signal-and-noise-tools' );
}

/**
 * The section: the two forms side by side, then the pointer line.
 *
 * @return void
 */
function sn_admin_render_notifications_section() {
	echo '<div class="sn-2up">';
	if ( function_exists( 'snt_morning_brief_render_settings' ) ) {
		snt_morning_brief_render_settings();
	}
	if ( function_exists( 'snt_security_digest_render_settings' ) ) {
		snt_security_digest_render_settings();
	}
	echo '</div>';
	echo '<p class="sn-field-helper">' . esc_html( snt_notifications_os_pointer_text() ) . '</p>';
}
