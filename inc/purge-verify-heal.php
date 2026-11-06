<?php
/**
 * Signal & Noise Tools: a purge whose deferred verify was lost gets a new one.
 *
 * The theme queues a one-shot verify about 75 seconds after an auto purge, and
 * that verify writes the verdict onto sn_last_purge_report. On 2026-11-05 one
 * was gone before its turn: no run in the cron history, nothing queued, so the
 * dashboard read "verifying" until the next purge. Scheduled events all live
 * in one option, so two writers at once can drop an event; no log could prove
 * that was the cause. This checks the outcome instead of the cause.
 *
 * Asking again is safe: the theme's scheduler refuses a duplicate for the same
 * epoch, and the verify skips a purge that a newer one has replaced.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PURGE_VERIFY_GRACE = 10 * 60; // Well past the theme's 75 seconds and a busy cron pass.

/**
 * The epoch of a purge still waiting on a verify past the grace, else 0. Pure.
 *
 * Any `resolved` is a verdict, including false: a stale edge is reported, not
 * retried.
 *
 * @param mixed $report sn_last_purge_report.
 * @param int   $now    Unix time.
 * @return int
 */
function snt_purge_verify_lost_epoch( $report, $now ) {
	if ( ! is_array( $report ) || array_key_exists( 'resolved', $report ) ) {
		return 0;
	}
	$time = (int) ( $report['time'] ?? 0 );
	if ( $time <= 0 || (int) $now - $time < SNT_PURGE_VERIFY_GRACE ) {
		return 0;
	}
	return max( 0, (int) ( $report['epoch'] ?? 0 ) );
}

/**
 * Ask the theme for a new verify when the last purge's was lost.
 *
 * @return void
 */
function snt_purge_verify_heal() {
	$epoch = snt_purge_verify_lost_epoch( get_option( 'sn_last_purge_report', array() ), time() );
	if ( $epoch > 0 && function_exists( 'sn_schedule_auto_purge_verify' ) ) {
		sn_schedule_auto_purge_verify( $epoch );
	}
}
add_action( 'snt_alerts_hourly', 'snt_purge_verify_heal' ); // SNT_ALERTS_HOOK: always scheduled, and that constant loads later.
