<?php
/**
 * Signal & Noise Tools — scheduled read-only agent runs (R6a, AI family).
 *
 * A recurring report over the READ door only. The run executes a FIXED,
 * code-defined list of read abilities through sn_mcp_call_tool() itself, so
 * the read kill switch, the permission layer, and Layer B telemetry apply to
 * every scheduled call exactly as they do to a live caller. The list is code,
 * never configuration: a user-supplied tool name here would be an injection
 * surface, and the write door is unreachable because the door argument is
 * pinned to read at the single call site.
 *
 * History stores per-tool outcome flags only, never result payloads — the
 * payloads are re-readable on demand and an option is the wrong place for a
 * corpus-sized cache.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'SNT_SCHEDULED_READS_CRON_HOOK', 'snt_scheduled_reads_daily' );
define( 'SNT_SCHEDULED_READS_HISTORY', 'snt_scheduled_reads_history' );
define( 'SNT_SCHEDULED_READS_HISTORY_CAP', 14 );

function snt_scheduled_reads_enabled() {
	return (bool) sn_setting( 'operations.scheduled_reads_enabled', false );
}

/** The run list — read-door tools only, pinned byte-for-byte by test. */
function snt_scheduled_reads_tools() {
	return array(
		'signal-noise__get-health-scan'       => array(),
		'signal-noise__uptime-status'         => array(),
		'signal-noise__get-deploy-status'     => array(),
		'signal-noise__anchor-status'         => array(),
		'signal-noise__get-analytics-summary' => array(),
	);
}

/** Execute the fixed list through the real read door and append to history. */
function snt_scheduled_reads_run() {
	if ( ! function_exists( 'sn_mcp_call_tool' ) ) {
		return null;
	}
	$door  = defined( 'SN_MCP_DOOR_READ' ) ? SN_MCP_DOOR_READ : 'read';
	$tools = array();
	foreach ( snt_scheduled_reads_tools() as $name => $args ) {
		$res    = sn_mcp_call_tool( $name, $args, $door );
		$result = is_array( $res ) ? ( $res['result'] ?? null ) : null;
		$tools[ $name ] = array( 'error' => ! is_array( $result ) || ! empty( $result['isError'] ) );
	}
	$run     = array( 'ran_at' => time(), 'door' => $door, 'tools' => $tools );
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$history = is_array( $history ) ? $history : array();
	array_unshift( $history, $run );
	update_option( SNT_SCHEDULED_READS_HISTORY, array_slice( $history, 0, SNT_SCHEDULED_READS_HISTORY_CAP ), false );
	return $run;
}

/**
 * Plain daily recurrence with no site-time anchor: the history is a machine
 * record, not a 7:00 inbox, so it takes no DST re-anchoring machinery.
 */
function snt_scheduled_reads_maybe_schedule_cron() {
	$scheduled = wp_next_scheduled( SNT_SCHEDULED_READS_CRON_HOOK );
	if ( snt_scheduled_reads_enabled() && ! $scheduled ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', SNT_SCHEDULED_READS_CRON_HOOK );
	} elseif ( ! snt_scheduled_reads_enabled() && $scheduled ) {
		wp_unschedule_event( $scheduled, SNT_SCHEDULED_READS_CRON_HOOK );
	}
}
add_action( 'init', 'snt_scheduled_reads_maybe_schedule_cron' );
function snt_scheduled_reads_daily_cron_cb() { snt_scheduled_reads_run(); }
add_action( SNT_SCHEDULED_READS_CRON_HOOK, 'snt_scheduled_reads_daily_cron_cb' );

function snt_scheduled_reads_render_settings() {
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$last    = is_array( $history ) && isset( $history[0] ) ? $history[0] : null;
	echo '<form method="post" class="sn-fieldset"><input type="hidden" name="sn_action" value="scheduled_reads_save" />'; wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Scheduled read-only runs', 'signal-and-noise-tools' ) . '</h2>';
	echo '<label><input type="checkbox" name="snt_scheduled_reads_enabled" value="1" '; checked( snt_scheduled_reads_enabled() ); echo ' /> ' . esc_html__( 'Run a fixed set of read-door abilities daily and keep a two-week outcome history', 'signal-and-noise-tools' ) . '</label>';
	echo '<p class="sn-field-helper">' . esc_html__( 'Read door only — the run goes through the same gate, kill switch, and telemetry as a live caller, and the tool list is fixed in code.', 'signal-and-noise-tools' ) . '</p>';
	if ( $last ) {
		$errors = 0;
		foreach ( (array) $last['tools'] as $tool_outcome ) { $errors += empty( $tool_outcome['error'] ) ? 0 : 1; }
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Last run %s ago: %d of %d reads failed.', human_time_diff( (int) $last['ran_at'], time() ), $errors, count( (array) $last['tools'] ) ) ) . '</p>';
	}
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'signal-and-noise-tools' ) . '</button> <button type="submit" class="button" name="snt_scheduled_reads_now" value="1">' . esc_html__( 'Run now', 'signal-and-noise-tools' ) . '</button></div></form>';
}
add_action( 'sn_admin_cron_tab', 'snt_scheduled_reads_render_settings', 30 );
