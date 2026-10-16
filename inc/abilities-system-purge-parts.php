<?php
/**
 * Signal & Noise Tools: abilities-system.php, the health-scan and purge
 * callbacks and the Cloudflare verdict.
 *
 * Split from inc/abilities-system.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for signal-noise/run-health-scan.
 *
 * Returns the scan's own summary shape (total + flagged) so a ⌘K toast
 * can say something useful without a second read.
 *
 * @since 9.78.0
 * @param array $input Validated against input_schema above (empty object).
 * @return array{ok:bool,total:int,flagged:int}
 */
function snt_ability_run_health_scan( $input = array() ) {
	if ( ! function_exists( 'sn_health_run_scan' ) ) {
		return array( 'ok' => false, 'total' => 0, 'flagged' => 0 );
	}
	$scan = sn_health_run_scan();
	if ( ! is_array( $scan ) ) {
		return array( 'ok' => false, 'total' => 0, 'flagged' => 0 );
	}
	return array(
		'ok'      => true,
		'total'   => function_exists( 'sn_health_check_total' ) ? (int) sn_health_check_total( $scan ) : 0,
		'flagged' => function_exists( 'sn_health_flagged_checks' ) ? count( (array) sn_health_flagged_checks( $scan ) ) : 0,
	);
}

/**
 * Execute callback for signal-noise/purge-all-caches.
 */
function snt_ability_purge_all_caches( $input ) {
	// $input is null when the run-path is called with no ?input= (the schema
	// permits 'null'); guard before indexing so PHP 8 does not warn on null.
	$include_overrides = is_array( $input ) && ! empty( $input['include_template_overrides'] );
	// 20.9.0: Redis is its own, explicit choice. A full reset (overrides) keeps it.
	$flush_redis       = $include_overrides || ( is_array( $input ) && ! empty( $input['flush_object_cache'] ) );

	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Cache helper unavailable: theme module not loaded.', array( 'status' => 500 ) );
	}

	// v10.4.1: verified => true routes the theme's CF leg through the BLOCKING
	// sn_cf_purge_everything_verified() (the wp-admin "Purge All Caches" path
	// since v8.7.0) and makes the theme write its per-leg sn_last_purge_report.
	// Before this the ability's CF purge was fire-and-forget: unconfigured or
	// rejected, it silently no-oped while this response still said "All caches
	// purged." (2026-07-29 stale-edge incident).
	$dispatched_at = time();
	$count         = (int) apply_filters( 'sn_purge_all_caches_result', 0, array(
		'template_overrides' => $include_overrides,
		'object_cache'       => $flush_redis,
		'verified'           => true,
	) );

	$cf = snt_purge_cf_verdict( $dispatched_at );

	// v13.70.0: RECORD THE VERDICT WHERE THE SURFACE READS IT. The dashboard's
	// "Last purge" cell reads the probe log (SN_CF_PROBE_LOG_OPT), and until now
	// ONLY the post-save probe ever wrote to it. So pressing "Purge caches"
	// cleared the edge, computed a perfectly good edge_fresh reading right here,
	// threw it away, and left the cell showing a verdict from whenever a Note was
	// last published — for weeks. Owner-reported 2026-09-02: "I purged them, but
	// that didn't change."
	//
	// Only a CONFIRMED purge that produced a definite edge reading is recorded:
	// an unconfirmed or rejected purge measured nothing, and the log's standing
	// rule is that an outage is a gap in evidence, never a verdict.
	// v13.87.2: A MANUAL PURGE NO LONGER WRITES TO THE PROBE LOG.
	//
	// v13.70.0 added that write so the "Last purge" cell would update when
	// someone pressed Purge. The need was real; the mechanism polluted a
	// measurement store with an operator action, and every defect since has
	// been a consequence — the count climbed when you purged (racing probes
	// booked as stale), then fell when you purged (each row evicting an older
	// one), while manual rows squeezed the post-save probes out of a twenty-row
	// window (10/10 -> 13/7 -> 15/5 on 2026-09-03).
	//
	// sn_last_purge_report ALREADY carries this purge's time, epoch and
	// resolved, and the theme's deferred verify corrects it in place once
	// propagation finishes. snt_cf_freshness_summary() reads it directly, so
	// the cell updates on every purge with no copy to keep in step — and the
	// probe log goes back to being what it has been since v11.10.0: a record of
	// whether per-post purges clear the edge.

	$ok = true;

	switch ( $cf['status'] ) {
		case 'confirmed':
			$message = 'All caches purged; Cloudflare zone purge confirmed.';
			if ( false === ( $cf['edge_fresh'] ?? null ) ) {
				// v13.88.0: was "give it a minute or purge again". Purging again
				// re-empties the cache and books another inline sample, so the
				// advice manufactured the symptom it was responding to. The
				// verdict is now confirmed automatically after propagation.
				$message .= ' The edge had not caught up yet — a zone purge takes a moment to reach every location. A confirmation check is scheduled; no action needed.';
			}
			break;
		case 'failed':
			$ok      = false;
			$message = sprintf( 'Origin caches purged, but Cloudflare REJECTED the zone purge (HTTP %d); the edge may keep serving stale pages. Check the API token on the Cloudflare tab.', (int) $cf['http'] );
			break;
		case 'not_configured':
			$ok      = false;
			$message = 'Origin caches purged, but the Cloudflare purge could not run: no API token/zone configured (Signal & Noise, Cloudflare tab). The edge may keep serving stale pages.';
			break;
		default: // unconfirmed — dispatched, but no verified report to read back.
			$message = 'Caches purged; the Cloudflare purge was dispatched but not confirmed (no verified purge report from the theme).';
			break;
	}

	if ( $include_overrides ) {
		$message .= sprintf( ' %d template override%s cleared.', $count, 1 === $count ? '' : 's' );
	}

	return array(
		'ok'         => $ok,
		'message'    => $message,
		'count'      => $count,
		'cloudflare' => $cf,
	);
}

/**
 * Verdict for the Cloudflare leg of a just-dispatched verified purge.
 *
 * Configuration is checked plugin-locally (sn_cf_is_configured, same package:
 * inc/cloudflare-purge.php). The accept-confirmation is read back from the
 * sn_last_purge_report option the THEME writes synchronously during the
 * filter dispatch (theme inc/purge-verify.php, >= 10.23.0). The report is
 * trusted only when it is fresh (written at/after this dispatch), verified-
 * mode, and carries a cf_success leg; anything else degrades to
 * 'unconfirmed' — never a fake success (the pre-v10.4.1 behavior this
 * replaces was exactly that fake success).
 *
 * @since 10.4.1
 * @param int $since Unix time captured immediately before the dispatch.
 * @return array{status:string,http:int,edge_fresh?:bool} status is one of
 *         'confirmed' | 'failed' | 'not_configured' | 'unconfirmed'.
 */
function snt_purge_cf_verdict( $since ) {
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		return array( 'status' => 'not_configured', 'http' => 0 );
	}

	$report = get_option( 'sn_last_purge_report', array() );
	$cf     = is_array( $report ) && isset( $report['legs']['cf'] ) && is_array( $report['legs']['cf'] )
		? $report['legs']['cf']
		: array();

	$confirmable = is_array( $report )
		&& (int) ( $report['time'] ?? 0 ) >= (int) $since
		&& 'verified' === ( $report['mode'] ?? '' )
		&& array_key_exists( 'cf_success', $cf );

	if ( ! $confirmable ) {
		return array( 'status' => 'unconfirmed', 'http' => 0 );
	}

	$out = array(
		'status' => ! empty( $cf['cf_success'] ) ? 'confirmed' : 'failed',
		'http'   => (int) ( $cf['http'] ?? 0 ),
	);
	if ( is_array( $report ) && array_key_exists( 'resolved', $report ) ) {
		$out['edge_fresh'] = ! empty( $report['resolved'] );
	}
	return $out;
}

