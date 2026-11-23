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
		// 19.3.0: the consolidated reads, not the absorbed singles. This run
		// was one of the callers keeping the singles' telemetry up (wave-4
		// read, 2026-09-27), and its calls are not usage by anyone.
		'signal-noise__sn-status'  => array( 'sections' => array( 'health_scan', 'uptime', 'deploy', 'anchor' ) ),
		'signal-noise__sn-metrics' => array( 'sections' => array( 'analytics_summary' ) ),
	);
}

/** Execute the fixed list through the real read door and append to history. */
function snt_scheduled_reads_run() {
	if ( ! function_exists( 'sn_mcp_call_tool' ) ) {
		return null;
	}
	$door = defined( 'SN_MCP_DOOR_READ' ) ? SN_MCP_DOOR_READ : 'read';
	// The read kill switch lives on the REST layer, NOT inside
	// sn_mcp_call_tool — an in-process call would sail straight past a
	// darkened door. Honor it here explicitly so the owner's switch governs
	// scheduled runs the same way it governs remote callers.
	if ( function_exists( 'sn_mcp_read_kill_switch_engaged' ) && sn_mcp_read_kill_switch_engaged() ) {
		$run     = array( 'ran_at' => time(), 'door' => $door, 'kill_switch' => true, 'tools' => array() );
		$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $run );
		update_option( SNT_SCHEDULED_READS_HISTORY, array_slice( $history, 0, SNT_SCHEDULED_READS_HISTORY_CAP ), false );
		return $run;
	}
	$tools = array();
	foreach ( snt_scheduled_reads_tools() as $name => $args ) {
		$res    = sn_mcp_call_tool( $name, $args, $door );
		$result = is_array( $res ) ? ( $res['result'] ?? null ) : null;
		$tools[ $name ] = array( 'error' => snt_scheduled_reads_failed( $result ) );
	}
	$run     = array( 'ran_at' => time(), 'door' => $door, 'tools' => $tools );
	$history = get_option( SNT_SCHEDULED_READS_HISTORY, array() );
	$history = is_array( $history ) ? $history : array();
	array_unshift( $history, $run );
	update_option( SNT_SCHEDULED_READS_HISTORY, array_slice( $history, 0, SNT_SCHEDULED_READS_HISTORY_CAP ), false );
	return $run;
}

/**
 * Did a read fail? The call itself (isError), or, for the consolidated
 * sn-status / sn-metrics, ONE of its sections: those degrade a failed source
 * to {error:"unavailable"} inside a successful call (19.3.1; the singles they
 * replaced failed the whole call, so 19.3.0 had stopped seeing it). PURE.
 *
 * @param mixed $result The tool result envelope.
 * @return bool
 */
function snt_scheduled_reads_failed( $result ) {
	if ( ! is_array( $result ) || ! empty( $result['isError'] ) ) {
		return true;
	}
	$sc       = json_decode( (string) json_encode( $result['structuredContent'] ?? array() ), true );
	$sections = is_array( $sc ) && is_array( $sc['sections'] ?? null ) ? $sc['sections'] : array();
	foreach ( $sections as $section ) {
		if ( is_array( $section ) && array_key_exists( 'error', $section ) ) {
			return true;
		}
	}
	return false;
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
/**
 * WP-Cron carries no authenticated user, and every listed ability gates on
 * manage_options — an anonymous run would refuse all five reads on every
 * fire. The run executes the owner's standing instruction (the opt-in
 * toggle), so the callback assumes the first administrator's identity for
 * the duration of the fixed read-only list, then restores the previous
 * user. Exposure is bounded by the read-door allowlist and the code-pinned
 * tool list; no user input reaches this path.
 */
function snt_scheduled_reads_daily_cron_cb() {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ) );
	if ( empty( $admins ) ) {
		return;
	}
	$previous = get_current_user_id();
	wp_set_current_user( (int) $admins[0] );
	snt_scheduled_reads_run();
	wp_set_current_user( $previous );
}
add_action( SNT_SCHEDULED_READS_CRON_HOOK, 'snt_scheduled_reads_daily_cron_cb' );

require_once __DIR__ . '/scheduled-reads-admin.php'; // the switch's form (Settings > AI) and the box Connections > Cron keeps.
