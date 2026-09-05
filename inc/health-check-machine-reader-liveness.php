<?php
/**
 * Signal & Noise Tools — Health check 25 (v13.98.0): the machine-reader
 * dataset went quiet.
 *
 * WHY. The rights-signals worker's sensor reports `last_write_ok` from
 * ISOLATE MEMORY: null until that isolate has attempted a write, and a fresh
 * isolate on every colo. Read on 2026-09-05, six days after a deploy, it said
 * null — and null is what a sensor that never fires ALSO says. The plugin's
 * edge-workers check flags `false` (a write threw) and an unbound dataset,
 * so a sensor that quietly stopped matching crawlers reads exactly like a
 * quiet day. The dataset is the one place that can tell: at ~450 reads a day
 * over the last 30, a day of zero is a signal in itself.
 *
 * WHAT. Reads the durable snapshot the cron already captures
 * (snt_mr_snapshot(), machine-readers-snapshot.php), which since v13.98.0
 * carries `by_day`. "Yesterday" is relative to the CAPTURE, not to now: a
 * capture covers whole UTC days up to its own, so the day before it is
 * complete. A finding when that day has zero hits against a baseline mean of
 * at least SN_HEALTH_MR_QUIET_FLOOR over the earlier days in the window.
 * Below the floor there is nothing to judge — that is a pass, not a skip.
 *
 * WHAT IT CANNOT SAY. Whether the silence is the sensor or the crawlers. The
 * note names both and points at the sensor readout; the finding clears on the
 * next capture with hits.
 *
 * Skips (never a pass): the module absent, no measurement yet, a stale
 * measurement, or a snapshot from before `by_day` existed.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Baseline mean (hits/day) below which a zero day is not evidence of anything. */
if ( ! defined( 'SN_HEALTH_MR_QUIET_FLOOR' ) ) {
	define( 'SN_HEALTH_MR_QUIET_FLOOR', 20 );
}

/**
 * @param array|null $snap A snt_mr_snapshot() record; production reads it.
 * @return array sn_health_pack_check() envelope.
 */
function sn_health_check_machine_reader_liveness( $snap = null ) {
	$label    = 'Machine-reader dataset liveness';
	$fix_hint = 'Read /_sn/rights-signals/version (sensor.ae_bound, sensor.last_write_ok) and the sn_machine_readers dataset in Cloudflare. A dead sensor and a day with no crawlers look the same from here; the sensor readout is where they differ. The finding clears on the next capture that records hits.';

	if ( null === $snap ) {
		if ( ! function_exists( 'snt_mr_snapshot' ) ) {
			return sn_health_pack_check( $label, array(), $fix_hint, 'The machine-readers module is not loaded, so nothing was read.' );
		}
		$snap = snt_mr_snapshot();
	}
	if ( ! function_exists( 'snt_mr_snapshot_has_measurement' ) || ! snt_mr_snapshot_has_measurement( $snap ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'No machine-reader snapshot has been captured yet (the read is not configured, or the cron has not run), so liveness could not be judged.' );
	}
	if ( function_exists( 'snt_mr_snapshot_is_stale' ) && true === snt_mr_snapshot_is_stale( $snap ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The machine-reader snapshot is stale, so liveness could not be judged from it. The snapshot cron is the thing to look at.' );
	}
	$by_day = $snap['by_day'] ?? null;
	if ( ! is_array( $by_day ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The snapshot predates the per-day series (v13.98.0); the next capture carries it.' );
	}

	$captured  = (int) $snap['captured_at'];
	$yesterday = gmdate( 'Y-m-d', $captured - DAY_IN_SECONDS );
	$window    = max( 2, (int) ( $snap['days'] ?? 30 ) );

	// Baseline: every day in the window BEFORE yesterday, missing days as 0.
	$sum = 0;
	$n   = 0;
	for ( $i = 2; $i <= $window; $i++ ) {
		$day  = gmdate( 'Y-m-d', $captured - $i * DAY_IN_SECONDS );
		$sum += max( 0, (int) ( $by_day[ $day ] ?? 0 ) );
		$n++;
	}
	$mean = $n > 0 ? $sum / $n : 0;
	if ( $mean < SN_HEALTH_MR_QUIET_FLOOR ) {
		return sn_health_pack_check( $label, array(), $fix_hint, null ); // Too quiet a baseline to judge a zero: ran, nothing to say.
	}

	$hits = max( 0, (int) ( $by_day[ $yesterday ] ?? 0 ) );
	if ( $hits > 0 ) {
		return sn_health_pack_check( $label, array(), $fix_hint, null );
	}

	// How long has it been quiet? Walk back from yesterday to the newest day with hits.
	$quiet_days = 1;
	for ( $i = 2; $i <= $window; $i++ ) {
		$day = gmdate( 'Y-m-d', $captured - $i * DAY_IN_SECONDS );
		if ( ( (int) ( $by_day[ $day ] ?? 0 ) ) > 0 ) {
			break;
		}
		$quiet_days++;
	}

	return sn_health_pack_check( $label, array(
		array(
			'subject_type'  => 'edge_sensor',
			'subject_id'    => 0,
			'subject_label' => 'sn_machine_readers',
			'subject_url'   => 'https://juanlentino.com/_sn/rights-signals/version',
			'edit_url'      => '',
			'note'          => sprintf(
				'0 machine-reader hits recorded for %1$s (quiet for %2$d day%3$s) against ~%4$d/day over the previous %5$d days. Either no crawler came, or the sensor stopped writing; from here they are the same silence.',
				$yesterday,
				$quiet_days,
				1 === $quiet_days ? '' : 's',
				(int) round( $mean ),
				$n
			),
			'quiet_days'    => $quiet_days,
			'baseline_mean' => round( $mean, 1 ),
		),
	), $fix_hint );
}
