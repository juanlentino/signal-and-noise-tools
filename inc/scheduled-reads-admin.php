<?php
/**
 * Signal & Noise Tools: scheduled read-only runs, the two classic boxes.
 *
 * One setting and one operation, on two screens since 2026-11. The daily run
 * switch is a setting and sits on Settings > AI; the last-run tally and Run
 * now are a reading and an operation and stayed on Connections > Cron. Both
 * forms post scheduled_reads_save (inc/admin-post-actions/reports.php), whose
 * handler writes the switch from `! empty()` on every post that does not
 * carry snt_scheduled_reads_now: so the switch lives in exactly one form, and
 * the Run now form carries that field hidden (tests/lib/whole-forms.php).
 *
 * @package SignalNoiseTools
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The daily run switch on Settings > AI (inc/admin-forms/settings-ai.php): the
 * checkbox, its hint and Save. sn_handle_scheduled_reads_save() writes the
 * switch from `! empty()` on every plain save, so this is the ONE form that
 * posts the action without snt_scheduled_reads_now.
 */
function snt_scheduled_reads_render_settings() {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset"><input type="hidden" name="action" value="sn_scheduled_reads_save" />'; wp_nonce_field( 'sn_scheduled_reads_save' );
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Scheduled reads', 'signal-and-noise-tools' ) . '</h2>';
	echo '<label><input type="checkbox" name="snt_scheduled_reads_enabled" value="1" '; checked( snt_scheduled_reads_enabled() ); echo ' /> ' . esc_html__( 'Run a fixed set of read-door abilities daily and keep a two-week outcome history', 'signal-and-noise-tools' ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html( snt_scheduled_reads_about() ) . '</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button></div></form>';
}

/** What the run is, in the words both twins print beside the switch and beside Run now. */
function snt_scheduled_reads_about() {
	return __( 'Read door only: the run goes through the same gate, kill switch, and telemetry as a live caller, and the tool list is fixed in code.', 'signal-and-noise-tools' );
}

/**
 * The box Connections > Cron keeps: the last-run tally and Run now, an
 * operation. Its form carries snt_scheduled_reads_now as a HIDDEN field, so
 * the handler returns on it before it writes the switch.
 */
function snt_scheduled_reads_render_cron_box() {
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$last    = is_array( $history ) && isset( $history[0] ) ? $history[0] : null;
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Scheduled read-only runs', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-field-helper">' . esc_html( snt_scheduled_reads_about() ) . '</p>';
	if ( $last ) {
		$errors = 0;
		foreach ( (array) $last['tools'] as $tool_outcome ) { $errors += empty( $tool_outcome['error'] ) ? 0 : 1; }
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Last run %s ago: %d of %d reads failed.', human_time_diff( (int) $last['ran_at'], time() ), $errors, count( (array) $last['tools'] ) ) ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '"><input type="hidden" name="action" value="sn_scheduled_reads_save" /><input type="hidden" name="snt_scheduled_reads_now" value="1" />'; wp_nonce_field( 'sn_scheduled_reads_save' );
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button">' . esc_html__( 'Run now', 'signal-and-noise-tools' ) . '</button></div></form>';
	if ( function_exists( 'snt_settings_ai_moved_line' ) ) {
		snt_settings_ai_moved_line( 'reads' );
	}
	echo '</div>';
}
add_action( 'sn_admin_cron_tab', 'snt_scheduled_reads_render_cron_box', 30 );
