<?php
/**
 * Signal & Noise Tools — the Search Console scheduled sync (R6b, last piece).
 *
 * The store shipped in v11.19.0 with one refresh path: the leaf's "Sync now"
 * button. The Search view's own header promises "fetched on a schedule", and
 * the gap between the two was measured on 2026-08-27: the stored window was
 * NINE DAYS stale — the system worked, and nobody pressed the button. This
 * file closes R6b by making the promise true.
 *
 * ONE PRODUCER. The cron callback spends `snt_gsc_sync()` — the exact function
 * the button spends. No parallel fetch path exists to drift; the test pins it
 * at source level.
 *
 * SELF-HEALING SCHEDULE, BOTH DIRECTIONS. The event exists only while the
 * integration is configured (credential stored AND property chosen). An
 * unconfigured install never accumulates a daily job that fails forever, and
 * clearing the credential unschedules on the next load — the cron dashboard
 * shows a live event or none, never an orphan.
 *
 * FAILURE IS A RECORDED FACT, NEVER A CLOBBER. `snt_gsc_sync()` returns
 * WP_Error before touching the stored window, so a failed night keeps the
 * last good data. The attempt itself lands in a status option (code +
 * message + clock) and the leaf prints it beside the window line — a stale
 * window with a reason, never a silent one.
 *
 * DAILY IS THE HONEST CADENCE: the window ends `lag_days` (3) back because
 * Google is still counting; syncing more often than daily fetches the same
 * finished days again.
 *
 * @package SignalNoiseTools
 * @since 13.9.0
 */

defined( 'ABSPATH' ) || exit;

const SNT_GSC_SYNC_HOOK          = 'sn_gsc_sync_daily';
const SNT_GSC_SYNC_STATUS_OPTION = 'snt_gsc_sync_status';

/**
 * True when the integration can actually sync: credential AND property.
 *
 * @return bool
 */
function snt_gsc_sync_is_ready() {
	return snt_gsc_credential_is_configured()
		&& '' !== (string) sn_setting( 'search_console.property', '' );
}

/**
 * Keep the schedule equal to readiness — create when ready, remove when not.
 */
function snt_gsc_sync_schedule() {
	$next = wp_next_scheduled( SNT_GSC_SYNC_HOOK );
	if ( snt_gsc_sync_is_ready() ) {
		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SNT_GSC_SYNC_HOOK );
		}
		return;
	}
	if ( $next ) {
		wp_unschedule_event( $next, SNT_GSC_SYNC_HOOK );
	}
}
add_action( 'init', 'snt_gsc_sync_schedule' );

/**
 * The cron callback: spend the ONE producer, record the attempt.
 */
function snt_gsc_sync_cron() {
	$result = snt_gsc_sync();
	$status = array(
		'ran_at' => time(),
		'ok'     => ! is_wp_error( $result ),
	);
	if ( is_wp_error( $result ) ) {
		// Google's own words, per error-messages-must-not-outrun-evidence:
		// the 403 class taught that a paraphrase here sends the reader to
		// the wrong fix. Code for machines, message for the human.
		$status['code']    = $result->get_error_code();
		$status['message'] = $result->get_error_message();
	}
	update_option( SNT_GSC_SYNC_STATUS_OPTION, $status, false );
}
add_action( SNT_GSC_SYNC_HOOK, 'snt_gsc_sync_cron' );

/**
 * The last scheduled attempt, or null when the cron has never run.
 *
 * NULL versus a failed row are different facts (realtime-zero-vs-null):
 * "never ran" must not render as "ran and failed", nor either as success.
 *
 * @return array|null
 */
function snt_gsc_sync_last_status() {
	$s = get_option( SNT_GSC_SYNC_STATUS_OPTION, null );
	return is_array( $s ) && isset( $s['ran_at'] ) ? $s : null;
}
