<?php
/**
 * Signal & Noise — Content Health check: the Search Console history stalled.
 *
 * THE GAP THIS CLOSES. `search_drift` reads a rolling history of position
 * snapshots, and `cron_health` already reports when `sn_gsc_sync_daily` stops
 * FIRING. Nothing reported the other half: the sync fires, and the history does
 * not grow. `snt_gsc_history_append()` returns silently when the payload has no
 * window end or no pages, so a property returning nothing leaves `synced_at`
 * fresh while the newest snapshot ages — and `search_drift` sits on `accruing`
 * forever, which reads exactly like "still accumulating".
 *
 * That ambiguity is what made 2026-09-03 cost a release: on the day the drift
 * watch came due, "one day short" and "the producer stalled" were the same
 * readout. v13.88.2 made the state report its span; this makes the STALL
 * announce itself, so nobody has to look on the right day.
 *
 * WHAT IT DOES NOT CATCH, stated so the green is not over-read: a history that
 * never grew at all. `synced_at` minus the newest snapshot is undefined with no
 * snapshots, and flagging an empty history would fire on every fresh install
 * whose property has not been crawled yet. That case is visible instead in
 * `search_drift`'s own `progress.snapshots: 0`.
 *
 * @package SignalNoiseTools
 * @since 13.89.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Days the newest snapshot may lag the last sync before this is a stall.
 *
 * Google's data lags roughly two to three days, and the sync is daily, so the
 * healthy gap sits around 2-3. Six leaves generous slack for a slow week at
 * Google while still catching a real stall inside one.
 */
const SNT_GSC_HISTORY_STALL_DAYS = 6;

/**
 * Has the sync kept running while the history stopped growing?
 *
 * @return array The sn_health_pack_check envelope.
 */
function snt_health_check_gsc_history() {
	$label    = __( 'Search Console history stalled', 'signal-and-noise-tools' );
	$fix_hint = __( 'The daily Search Console sync is still running, but no new position snapshot has landed. The append step drops a payload with no window end or no page rows, so this is usually the property returning nothing — check the property is still verified and returning data. Until it resolves, search_drift cannot advance and will read "accruing" indefinitely.', 'signal-and-noise-tools' );

	// COULD NOT RUN is not PASSED. Four checks used to be counted as passes on
	// days they never ran; $skipped is what keeps this one honest.
	if ( ! function_exists( 'snt_gsc_data' ) || ! function_exists( 'snt_gsc_history' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, __( 'Search Console module not loaded.', 'signal-and-noise-tools' ) );
	}

	$data = snt_gsc_data();
	if ( ! is_array( $data ) || empty( $data['synced_at'] ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, __( 'Never synced — nothing to compare a stall against.', 'signal-and-noise-tools' ) );
	}

	$history = (array) snt_gsc_history();
	if ( empty( $history ) ) {
		// Deliberately SKIPPED, not a finding: see the file docblock. An empty
		// history on a fresh property is normal, and search_drift reports it
		// directly as progress.snapshots = 0.
		return sn_health_pack_check( $label, array(), $fix_hint, __( 'No snapshots yet — a never-grown history is reported by search_drift, not here.', 'signal-and-noise-tools' ) );
	}

	$entries = array_values( $history );
	$newest  = end( $entries );
	$end     = (string) ( is_array( $newest ) ? ( $newest['end'] ?? '' ) : '' );
	$end_ts  = '' !== $end ? strtotime( $end ) : false;
	if ( false === $end_ts ) {
		return sn_health_pack_check( $label, array(), $fix_hint, __( 'Newest snapshot carries no readable window end.', 'signal-and-noise-tools' ) );
	}

	$lag_days = ( (int) $data['synced_at'] - $end_ts ) / DAY_IN_SECONDS;
	if ( $lag_days <= SNT_GSC_HISTORY_STALL_DAYS ) {
		return sn_health_pack_check( $label, array(), $fix_hint, null );
	}

	return sn_health_pack_check(
		$label,
		array(
			array(
				'subject_label' => __( 'Search Console position history', 'signal-and-noise-tools' ),
				'note'          => sprintf(
					/* translators: 1: lag in days, 2: newest snapshot date, 3: threshold in days */
					__( 'Last sync is %1$.1f days ahead of the newest snapshot (%2$s). The sync is running; the history is not growing. Threshold %3$d days.', 'signal-and-noise-tools' ),
					$lag_days,
					$end,
					SNT_GSC_HISTORY_STALL_DAYS
				),
			),
		),
		$fix_hint
	);
}
