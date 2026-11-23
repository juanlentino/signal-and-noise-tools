<?php
/**
 * S&N Dashboard: Connections → Cron, the readout box and the settings row.
 *
 * The classic tab is do_action( 'sn_admin_cron_tab' ), and two of its three
 * callbacks carry a form: the configuration drift box (inc/config-drift.php,
 * `snt_config_drift_render_cron_box()`, priority 20, `morning_brief_save`
 * with `snt_config_drift_acknowledge`, painted only while drift exists) and
 * the scheduled read-only runs (inc/scheduled-reads-admin.php,
 * `snt_scheduled_reads_render_cron_box()`, priority 30, Run now alone).
 * The brief and alerts switches that shared the drift box are a pane of
 * Settings now (settings-notifications.php), as is the daily run switch
 * (settings-ai.php). A classic form shares one
 * `sn_action` between its Save button and its secondary verb (Run now), the
 * click told apart by an extra field only that button carries. An `<os-form>`
 * fires one event per submit and a one-click button drops extra args
 * (sn-dashboard.os.php posted_values), so each verb is its own kit form with
 * the differentiator as a hidden field. Same readers, same field names, same
 * actions. 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): a secondary
 * verb is the compact inline form under Save, not a full-width row.
 *
 * @package SignalNoiseTools
 * @since 17.3.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The configuration drift box: what stayed here when the brief's email
 * switches moved to Settings > Notifications and reports
 * (settings-notifications.php). Drift is a problem, so it is a warn notice,
 * with Acknowledge current settings under it while it exists; the form posts
 * `morning_brief_save` carrying `snt_config_drift_acknowledge`, and the
 * handler returns on that field before it writes a switch. One line says
 * where the switches went.
 *
 * @return string
 */
function cron_drift_html() {
	$drift = function_exists( 'snt_config_drift_status' ) ? \snt_config_drift_status() : array( 'has_drift' => false );
	if ( empty( $drift['has_drift'] ) ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( __( 'No settings differ from the acknowledged snapshot.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$body = \snt_kit_notice(
			'warn',
			\snt_kit_esc( sprintf(
				/* translators: %d: number of settings that differ from the acknowledged snapshot */
				__( 'Configuration drift: %d settings differ from the acknowledged snapshot.', 'signal-and-noise-tools' ),
				(int) ( $drift['count'] ?? 0 )
			) )
		) . \snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), \snt_kit_form( 'morning_brief_save', \snt_kit_field( 'hidden', 'snt_config_drift_acknowledge', '', '1' ), array( 'submit' => __( 'Acknowledge current settings', 'signal-and-noise-tools' ), 'inline' => true ) ) );
	}
	$moved = '<p class="snt-hint">' . \snt_kit_esc( __( 'The morning brief and alert email switches moved to', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_go( __( 'Settings › Notifications and reports', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'notifications', 'current' => 'connections' ) ) . '.</p>';
	return \snt_kit_section( __( 'Configuration drift', 'signal-and-noise-tools' ), $body . $moved );
}

/**
 * The scheduled read-only runs box: what the run is, the last-run tally, Run
 * now, and one line on where the daily run switch went (Settings > AI). Run
 * now posts `scheduled_reads_save` with `snt_scheduled_reads_now` hidden; the
 * handler returns on that field before it writes the switch.
 *
 * @return string
 */
function cron_scheduled_reads_html() {
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$last    = is_array( $history ) && isset( $history[0] ) ? $history[0] : null;
	$out     = function_exists( 'snt_scheduled_reads_about' ) ? '<p class="snt-hint">' . \snt_kit_esc( \snt_scheduled_reads_about() ) . '</p>' : '';
	if ( $last ) {
		$errors = 0;
		foreach ( (array) $last['tools'] as $tool_outcome ) {
			$errors += empty( $tool_outcome['error'] ) ? 0 : 1;
		}
		$out .= '<p class="snt-hint">' . sprintf(
			/* translators: 1: relative time element, 2: failed reads, 3: total reads */
			\snt_kit_esc( __( 'Last run %1$s: %2$d of %3$d reads failed.', 'signal-and-noise-tools' ) ),
			\snt_kit_relative_time( (int) $last['ran_at'] ),
			$errors,
			count( (array) $last['tools'] )
		) . '</p>';
	}
	$out .= \snt_kit_tag( 'os-cluster', array( 'gap' => '8' ), \snt_kit_form( 'scheduled_reads_save', \snt_kit_field( 'hidden', 'snt_scheduled_reads_now', '', '1' ), array( 'submit' => __( 'Run now', 'signal-and-noise-tools' ), 'inline' => true ) ) );
	if ( function_exists( 'snt_settings_ai_moved_text' ) ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( \snt_settings_ai_moved_text( 'reads' ) ) . ' ' . \snt_kit_go( __( 'Settings › AI', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'ai', 'current' => 'connections' ) ) . '.</p>';
	}
	return \snt_kit_section( __( 'Scheduled read-only runs', 'signal-and-noise-tools' ), $out );
}

/**
 * The Action Scheduler backlog box: the Site Health reading
 * (inc/scheduled-actions-health.php) painted where the scheduled-jobs
 * question is asked. One snapshot per paint, shared by the notices and the
 * rows; each threshold crossed is a problem, so it is a warn notice on top
 * of the box. Row labels are the raw status enums the Info row prints. An
 * absent table says so: absence is an answer, never a row of zeros.
 *
 * @return string
 */
function cron_backlog_html() {
	if ( ! function_exists( 'snt_asb_snapshot' ) ) {
		return '';
	}
	$snap = \snt_asb_snapshot();
	$top  = '';
	$door = '';
	if ( null === $snap ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( \snt_asb_summary_line( null ) ) . '</p>';
	} else {
		foreach ( \snt_asb_warnings( $snap ) as $warning ) {
			$top .= \snt_kit_notice( 'warn', \snt_kit_esc( $warning ) );
		}
		$overdue = (int) $snap['overdue_pending'];
		$rows    = array();
		foreach ( (array) $snap['counts'] as $status => $n ) {
			// Raw figures, as the Info row and the shared threshold sentences print them.
			$row = array( 'label' => (string) $status, 'value' => (string) (int) $n );
			if ( 'pending' === $status && $overdue > 0 ) {
				/* translators: %d: pending actions already past their run date */
				$row['value'] .= sprintf( __( ' (%d overdue)', 'signal-and-noise-tools' ), $overdue );
				$row['tone']   = $overdue >= SN_ASB_OVERDUE_WARN ? 'warn' : null;
			}
			$rows[] = $row;
		}
		$rows[] = array( 'label' => 'total', 'value' => (string) (int) $snap['total'], 'tone' => (int) $snap['total'] >= SN_ASB_ROWS_WARN ? 'warn' : null );
		$body   = \snt_kit_kv( $rows );
		// The same gate as the Site Health result: no door to a Tools page that is not there.
		if ( class_exists( 'ActionScheduler' ) ) {
			$door = '<p>' . \snt_kit_door( __( 'Open Scheduled Actions', 'signal-and-noise-tools' ), admin_url( 'tools.php?page=action-scheduler' ) ) . '</p>';
		}
	}
	return \snt_kit_section(
		__( 'Action Scheduler backlog', 'signal-and-noise-tools' ),
		$top . $body . $door,
		__( 'Another plugin\'s queue; its pending-and-due count runs on every page load, so its size is this site\'s concern.', 'signal-and-noise-tools' )
	);
}

/**
 * The boxes under the ledger, on one row (17.2.1: boxes share a row): the
 * drift box, the scheduled reads box and the Action Scheduler backlog, five short facts that stood
 * alone across the full width above them (2026-10-12 audit). Each settings
 * side paints only when its classic callback is loaded and only while not
 * watching (its checkboxes cannot ride a poll); a missing box leaves the rest
 * to share the row.
 *
 * @param bool $watching Whether the leaf is watching live.
 * @return string
 */
function cron_settings_row_html( $watching = false ) {
	$brief = ! $watching && function_exists( 'snt_config_drift_render_cron_box' ) ? cron_drift_html() : '';
	$reads = ! $watching && function_exists( 'snt_scheduled_reads_render_cron_box' ) ? cron_scheduled_reads_html() : '';
	$boxes = array();
	foreach ( array( $brief, $reads, cron_backlog_html() ) as $box ) {
		if ( '' !== $box ) {
			$boxes[] = \snt_kit_tag( 'os-card', array(), $box );
		}
	}
	return 1 === count( $boxes ) ? (string) preg_replace( '#^<os-card>(.*)</os-card>$#s', '$1', $boxes[0] ) : \snt_kit_grid( $boxes );
}
