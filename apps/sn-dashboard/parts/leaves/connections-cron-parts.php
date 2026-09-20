<?php
/**
 * S&N Dashboard: Connections → Cron, the settings row.
 *
 * The classic tab is do_action( 'sn_admin_cron_tab' ), and two of its three
 * callbacks carry a form: the morning brief (inc/morning-brief.php,
 * `snt_morning_brief_render_settings()`, priority 20, `morning_brief_save`)
 * and the scheduled read-only runs (inc/scheduled-reads.php,
 * `snt_scheduled_reads_render_settings()`, priority 30, `scheduled_reads_save`).
 * Each classic form shares one `sn_action` between its Save button and its
 * secondary verbs (Send test brief, Acknowledge current settings, Run now),
 * the click told apart by an extra field only that button carries. An
 * `<os-form>` fires one event per submit and a one-click button drops extra
 * args (sn-dashboard.os.php posted_values), so each verb is its own kit form
 * with the differentiator as a hidden field: the security-login-defense.php
 * shape. Same readers, same field names, same actions.
 *
 * @package SignalNoiseTools
 * @since 17.3.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The morning brief box: toggle, helper, readouts, Save, Send test brief,
 * and Acknowledge current settings while drift exists. Drift is a problem,
 * so it is a notice on top of the box, not a helper line under the toggle.
 *
 * @return string
 */
function cron_morning_brief_html() {
	$drift      = function_exists( 'snt_config_drift_status' ) ? \snt_config_drift_status() : array( 'has_drift' => false );
	$has_drift  = ! empty( $drift['has_drift'] );
	$last_sent  = (int) get_option( SNT_MORNING_BRIEF_LAST_SENT, 0 );
	$last_error = get_option( SNT_MORNING_BRIEF_LAST_ERROR );

	$top = '';
	if ( $has_drift ) {
		$top = \snt_kit_notice(
			'warn',
			\snt_kit_esc( sprintf(
				/* translators: %d: number of settings that differ from the acknowledged snapshot */
				__( 'Configuration drift: %d settings differ from the acknowledged snapshot.', 'signal-and-noise-tools' ),
				(int) ( $drift['count'] ?? 0 )
			) )
		);
	}

	// os-checkbox-label has no description prop, so the helper is its own hint.
	$inner  = \snt_kit_field( 'checkbox', 'snt_morning_brief_enabled', __( 'Email a daily morning brief to the admin address', 'signal-and-noise-tools' ), \snt_morning_brief_enabled(), array( 'value' => '1' ) );
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'A deterministic prose reading of the latest health scan, cron history, uptime status, deploy state, and any unacknowledged settings drift. Scheduled for 7:00 a.m. site time.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( $last_sent > 0 ) {
		/* translators: %s: human time diff */
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Last sent %s ago.', 'signal-and-noise-tools' ), human_time_diff( $last_sent, time() ) ) ) . '</p>';
	}
	if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
		$inner .= \snt_kit_notice( 'err', '<b>' . \snt_kit_esc( __( 'Last send failed:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $last_error['message'] ) );
	}

	$out  = \snt_kit_form( 'morning_brief_save', $inner, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	$out .= \snt_kit_form( 'morning_brief_save', \snt_kit_field( 'hidden', 'snt_morning_brief_test', '', '1' ), array( 'submit' => __( 'Send test brief', 'signal-and-noise-tools' ) ) );
	if ( $has_drift ) {
		$out .= \snt_kit_form( 'morning_brief_save', \snt_kit_field( 'hidden', 'snt_config_drift_acknowledge', '', '1' ), array( 'submit' => __( 'Acknowledge current settings', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_section( __( 'Morning operations brief', 'signal-and-noise-tools' ), $top . $out );
}

/**
 * The scheduled read-only runs box: toggle, helper, last-run tally, Save, Run now.
 *
 * @return string
 */
function cron_scheduled_reads_html() {
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$last    = is_array( $history ) && isset( $history[0] ) ? $history[0] : null;

	$inner  = \snt_kit_field( 'checkbox', 'snt_scheduled_reads_enabled', __( 'Run a fixed set of read-door abilities daily and keep a two-week outcome history', 'signal-and-noise-tools' ), \snt_scheduled_reads_enabled(), array( 'value' => '1' ) );
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Read door only: the run goes through the same gate, kill switch, and telemetry as a live caller, and the tool list is fixed in code.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( $last ) {
		$errors = 0;
		foreach ( (array) $last['tools'] as $tool_outcome ) {
			$errors += empty( $tool_outcome['error'] ) ? 0 : 1;
		}
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( sprintf(
			/* translators: 1: human time diff, 2: failed reads, 3: total reads */
			__( 'Last run %1$s ago: %2$d of %3$d reads failed.', 'signal-and-noise-tools' ),
			human_time_diff( (int) $last['ran_at'], time() ),
			$errors,
			count( (array) $last['tools'] )
		) ) . '</p>';
	}

	$out  = \snt_kit_form( 'scheduled_reads_save', $inner, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	$out .= \snt_kit_form( 'scheduled_reads_save', \snt_kit_field( 'hidden', 'snt_scheduled_reads_now', '', '1' ), array( 'submit' => __( 'Run now', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Scheduled read-only runs', 'signal-and-noise-tools' ), $out );
}

/**
 * The two settings boxes on one row under the ledger (17.2.1: boxes share a
 * row). Each side paints only when its classic callback is loaded; a missing
 * side leaves the other at full width.
 *
 * @return string
 */
function cron_settings_row_html() {
	$brief = function_exists( 'snt_morning_brief_render_settings' ) ? cron_morning_brief_html() : '';
	$reads = function_exists( 'snt_scheduled_reads_render_settings' ) ? cron_scheduled_reads_html() : '';
	if ( '' === $brief || '' === $reads ) {
		return $brief . $reads;
	}
	return '<div class="snt-cols"><section class="snt-col">' . $brief . '</section><section class="snt-col">' . $reads . '</section></div>';
}
