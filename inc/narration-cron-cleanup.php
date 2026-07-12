<?php
/**
 * Signal & Noise Tools — one-time cleanup of the retired weekly-digest cron.
 *
 * v9.4.1 (annotations Release 2) removed the weekly-digest DASHBOARD surface and
 * its self-healing scheduler (inc/insights-narration.php no longer schedules the
 * `sn_insights_narration_weekly` event). Any install that had the opt-in enabled
 * is left with an orphaned recurring event whose scheduler is gone. Install hooks
 * cannot self-observe an in-place update (memory: feedback_install_hooks_cannot_self_observe),
 * so this rides admin_init behind a version sentinel and fires exactly once.
 *
 * The narrator CORE and the two narration Abilities (signal-noise/run-narration,
 * get-narration) are intentionally kept — only the automated weekly schedule is gone.
 *
 * @package SignalNoiseTools
 * @since 9.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clear the orphaned weekly-digest cron once, then stamp a version sentinel so it
 * never re-runs for this version. wp_clear_scheduled_hook is a no-op when nothing
 * is scheduled, so this is safe on installs that never enabled the digest.
 *
 * @return void
 */
function sn_narration_cron_cleanup_maybe_run() {
	$done = get_option( 'sn_narration_cron_cleaned', '' );
	if ( '9.5.0' === $done ) {
		return;
	}
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'sn_insights_narration_weekly' );
	}
	update_option( 'sn_narration_cron_cleaned', '9.5.0', false );
}
add_action( 'admin_init', 'sn_narration_cron_cleanup_maybe_run' );
