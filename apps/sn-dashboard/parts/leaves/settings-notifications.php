<?php
/**
 * S&N Dashboard: Settings > Notifications and reports, painted from the kit.
 *
 * The first pane of the Settings tab, the native twin of
 * inc/admin-forms/notifications.php: the three email switches, each with its
 * hint, its test button and its last-sent lines. The morning brief and the
 * alerts email came from Connections > Cron (connections-cron-parts.php), the
 * weekly security digest from Security > Login defense
 * (security-login-defense.php). Field names, actions and handlers are the
 * ones those leaves used: `morning_brief_save` and `security_digest_save`.
 *
 * An `<os-form>` fires one event per submit and cannot tell two buttons apart,
 * so each test verb is its own compact form carrying its differentiator as a
 * hidden field. A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), so it lands back on this pane.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The Last sent hint and the Last send failed notice of one email.
 *
 * @param int   $last_sent  Unix time of the last send, 0 for never.
 * @param mixed $last_error The stored error (an array with `message`), or anything else for none.
 * @return string
 */
function notifications_last_lines( $last_sent, $last_error ) {
	$out = '';
	if ( $last_sent > 0 ) {
		/* translators: %s: relative time element */
		$out .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Last sent %s.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $last_sent ) ) . '</p>';
	}
	if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
		$out .= \snt_kit_notice( 'err', '<b>' . \snt_kit_esc( __( 'Last send failed:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $last_error['message'] ) );
	}
	return $out;
}

/**
 * The morning brief and alerts box. Both checkboxes sit in ONE Save form:
 * sn_handle_morning_brief_save() writes both switches on every plain save, so
 * a form carrying one of them would turn the other off.
 *
 * @return string
 */
function notifications_brief_html() {
	$inner = \snt_kit_field( 'checkbox', 'snt_morning_brief_enabled', __( 'Email a daily morning brief to the admin address', 'signal-and-noise-tools' ), \snt_morning_brief_enabled(), array( 'value' => '1', 'hint' => __( 'A deterministic prose reading of the latest health scan, cron history, uptime status, deploy state, and any unacknowledged settings drift. Scheduled for 7:00 a.m. site time.', 'signal-and-noise-tools' ) ) );
	if ( function_exists( 'snt_alerts_enabled' ) ) {
		$inner .= \snt_kit_field( 'checkbox', 'snt_alerts_enabled', __( 'Email an alert when a page spikes or breaks', 'signal-and-noise-tools' ), \snt_alerts_enabled(), array( 'value' => '1', 'hint' => __( 'Checked hourly against stored data: a page or the whole site far above its 7-day mean, or a page with 3 or more server errors in a day. One email per alert per day, to the admin address.', 'signal-and-noise-tools' ) ) );
	}
	$inner .= notifications_last_lines( (int) get_option( SNT_MORNING_BRIEF_LAST_SENT, 0 ), get_option( SNT_MORNING_BRIEF_LAST_ERROR ) );
	$test   = \snt_kit_form( 'morning_brief_save', \snt_kit_field( 'hidden', 'snt_morning_brief_test', '', '1' ), array( 'submit' => __( 'Send test brief', 'signal-and-noise-tools' ), 'inline' => true ) );
	return \snt_kit_section(
		__( 'Morning brief and alerts', 'signal-and-noise-tools' ),
		\snt_kit_form( 'morning_brief_save', $inner, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) . \snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), $test )
	);
}

/**
 * The weekly security digest box. The switch is a kit checkbox, never an
 * `os-switch`: os-form reads a boolean only off a checkbox-shaped tag, and a
 * switch would submit its static value '1' whether on or off. The checkbox
 * label has no description, so the hint is its own line.
 *
 * @return string
 */
function notifications_digest_html() {
	$inner  = \snt_kit_field( 'checkbox', 'sn_digest_enabled', __( 'Email a weekly security digest to the admin address', 'signal-and-noise-tools' ), function_exists( 'snt_security_digest_enabled' ) && \snt_security_digest_enabled(), array( 'value' => '1' ) );
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Failed logins, recon probes, account and site changes, app-password sign-ins, lockouts, login-guard blocks, denylist freshness, and the last message the mail server refused, for the last 7 days. Sends every week, including quiet weeks: the quiet email is the heartbeat.', 'signal-and-noise-tools' ) ) . '</p>';
	$inner .= notifications_last_lines( (int) get_option( SN_SECURITY_DIGEST_LAST_SENT, 0 ), get_option( SN_SECURITY_DIGEST_LAST_ERROR ) );
	$test   = '<p class="snt-hint">' . \snt_kit_esc( __( 'Sends this week’s digest to the admin address now, whether or not the weekly email is on.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_form( 'security_digest_save', \snt_kit_field( 'hidden', 'sn_digest_test', '', '1' ), array( 'submit' => __( 'Send test digest', 'signal-and-noise-tools' ), 'inline' => true ) );
	return \snt_kit_section(
		__( 'Weekly security digest', 'signal-and-noise-tools' ),
		\snt_kit_form( 'security_digest_save', $inner, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) . $test
	);
}

/**
 * The leaf: the two boxes on one row, then the line about in-app alerts,
 * which are a per-device system choice and have no control here. A box paints
 * only when its module is loaded.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_notifications( array $ctx ) {
	unset( $ctx );
	$boxes = array();
	if ( function_exists( 'snt_morning_brief_render_settings' ) ) {
		$boxes[] = notifications_brief_html();
	}
	if ( function_exists( 'snt_security_digest_render_settings' ) ) {
		$boxes[] = notifications_digest_html();
	}
	// One sentence, shared with the classic twin (inc/admin-forms/notifications.php).
	$pointer = function_exists( 'snt_notifications_os_pointer_text' ) ? '<p class="snt-hint">' . \snt_kit_esc( \snt_notifications_os_pointer_text() ) . '</p>' : '';
	return ( array() === $boxes ? '' : \snt_kit_grid( $boxes, 320 ) ) . $pointer;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/notifications'] = __NAMESPACE__ . '\\paint_settings_notifications';
		return $painters;
	}
);
