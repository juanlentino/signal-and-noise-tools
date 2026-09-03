<?php
/**
 * Signal & Noise Tools — retire the manual-purge settle cron.
 *
 * v13.87.1 shipped `snt_cf_settle_manual_purge`, a scheduled check that copied
 * the settled purge verdict into the probe log. v13.87.2 removed the module
 * entirely: the surfaces now read sn_last_purge_report directly, so there is
 * nothing to copy and nothing to keep in step.
 *
 * WHY THIS FILE EXISTS. Deleting a handler does not delete the events already
 * scheduled against it. A site that ran v13.87.1 for even an hour can hold a
 * pending `snt_cf_settle_manual_purge` event, and once the handler is gone that
 * event fires into nothing, forever, on every cron sweep. This plugin surfaces
 * exactly that as an ORPHANED cron on the dashboard, so the removal would have
 * reported itself as a defect — which is how it was caught.
 *
 * wp_clear_scheduled_hook() is a no-op when nothing is scheduled, so this is
 * safe on installs that never ran v13.87.1.
 *
 * @package SignalNoiseTools
 * @since   13.87.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The hook retired in v13.87.2, named here because nothing else references it now. */
const SN_SETTLE_RETIRED_HOOK = 'snt_cf_settle_manual_purge';

/**
 * Clear the retired settle cron once, then stamp a sentinel so it never re-runs.
 *
 * @return void
 */
function snt_settle_cron_cleanup_maybe_run() {
	if ( '13.87.2' === get_option( 'snt_settle_cron_cleaned', '' ) ) {
		return;
	}
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( SN_SETTLE_RETIRED_HOOK );
	}
	update_option( 'snt_settle_cron_cleaned', '13.87.2', false );
}
add_action( 'admin_init', 'snt_settle_cron_cleanup_maybe_run' );
