<?php
/**
 * Signal & Noise Tools — the Content-Health scan gets a schedule.
 *
 * Until v12.22.2 nothing scheduled it. sn_health_run_scan() had exactly two
 * callers — the run-health-scan ability and the wp-admin "Run scan" button — so
 * the scan ran when a human clicked and at no other time. A reading was found
 * 18 hours old, and the Ledger CI chip reported a red verdict the trust repo had
 * already cleared eleven hours earlier. Nothing was broken; the panel was simply
 * reading the last time anyone asked.
 *
 * WHY DAILY, AND WHY 08:00 UTC — both derived, neither picked.
 *
 * Cadence is set by the fastest thing the checks watch, not by how often the
 * site publishes. Publishing runs at one Note every 3.1 days (measured over the
 * last eight gaps: 3,2,3,3,3,3,4,4; 3.6 days across 119 days), which would
 * suggest something slower. But five of the sixteen checks watch surfaces that
 * drift with no edit at all — broken-links (link rot), cf-security-headers,
 * rights-signals, rights-anchored, and ledger-ci. The last one reads the
 * provenance repo's DAILY scheduled verify, so a scan slower than daily could
 * never track it, which is exactly how a cleared verdict stayed on screen.
 *
 * The hour is the free one. The ledger's verify.yml fires at 07:00 UTC and takes
 * ~40s; version-tag-parity is 07:00 and the theme's is 07:20, so 07:00-07:30 is
 * a GitHub Actions cluster. 08:00 leaves an hour for a slow or retried verify to
 * settle before this reads its verdict. On the WordPress side the daily hooks
 * sit at 23:42, 01:43, 02:48, 11:44, 16:56 and 21:07 UTC — 03:00 to 11:00 is
 * empty, and 08:00 is three hours clear of the nearest neighbour on either
 * side. Locally it is 04:00 EDT, so a ~48s walk over every post plus external
 * link probes lands when nothing else wants the box.
 *
 * DELIBERATE DIVERGENCE from the sibling crons. Every other daily hook here
 * schedules with `time() + HOUR_IN_SECONDS`, which anchors to whenever the
 * plugin first loaded — that is why they fire at 23:42 and 01:43 rather than at
 * any meaningful hour. For those it does not matter. Here it does: this scan has
 * to run AFTER a specific external job, so it anchors to a fixed UTC hour
 * instead. Changing it back to a relative offset would silently re-introduce the
 * stale-verdict window.
 *
 * @package SignalNoiseTools
 * @since 12.22.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The hook name. Stable: renaming it orphans the scheduled event on live. */
const SN_HEALTH_CRON_HOOK = 'sn_health_scan_daily';

/** The UTC hour the scan runs at. See the docblock for why it is 08. */
const SN_HEALTH_CRON_UTC_HOUR = 8;

/**
 * The next occurrence of SN_HEALTH_CRON_UTC_HOUR, as a UTC timestamp.
 *
 * Built from gmdate() rather than strtotime('today 08:00'), which would resolve
 * against the server's local timezone and put the run somewhere other than the
 * hour this module reasons about.
 *
 * @param int|null $now Timestamp to compute from; defaults to time().
 * @return int
 */
function sn_health_cron_next_slot( $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$today = (int) strtotime( gmdate( 'Y-m-d', $now ) . ' ' . sprintf( '%02d', SN_HEALTH_CRON_UTC_HOUR ) . ':00:00 UTC' );
	return $today > $now ? $today : $today + DAY_IN_SECONDS;
}

/**
 * Run the scan on the schedule. A named function, not a closure, so the event
 * stays unschedulable and the callback is assertable.
 *
 * @return void
 */
function sn_health_cron_run() {
	if ( function_exists( 'sn_health_run_scan' ) ) {
		sn_health_run_scan();
	}
}

add_action( SN_HEALTH_CRON_HOOK, 'sn_health_cron_run' );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( SN_HEALTH_CRON_HOOK ) ) {
		wp_schedule_event( sn_health_cron_next_slot(), 'daily', SN_HEALTH_CRON_HOOK );
	}
} );
