<?php
/**
 * Signal & Noise Tools: Insights, recommendation state, the scan run, the last-error diagnostic, the weekly cron and the localize summary.
 *
 * Split from inc/insights.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the per-recommendation state from sn_insights_state.
 * Returns dict with three arrays/maps. Defaults to empty.
 */
function snt_insights_state_read() {
	$stored = get_option( SN_INSIGHTS_STATE_OPT, array() );
	if ( ! is_array( $stored ) ) { $stored = array(); }
	return array(
		'dismissed_ids' => isset( $stored['dismissed_ids'] ) && is_array( $stored['dismissed_ids'] ) ? array_values( $stored['dismissed_ids'] ) : array(),
		'snoozed_until' => isset( $stored['snoozed_until'] ) && is_array( $stored['snoozed_until'] ) ? $stored['snoozed_until'] : array(),
		'done_ids'      => isset( $stored['done_ids'] )      && is_array( $stored['done_ids'] )      ? array_values( $stored['done_ids'] )      : array(),
	);
}

function snt_insights_state_write( $state ) {
	// FIFO cap each list.
	if ( count( $state['dismissed_ids'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		$state['dismissed_ids'] = array_slice( $state['dismissed_ids'], -SN_INSIGHTS_STATE_LIST_CAP );
	}
	if ( count( $state['done_ids'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		$state['done_ids'] = array_slice( $state['done_ids'], -SN_INSIGHTS_STATE_LIST_CAP );
	}
	if ( count( $state['snoozed_until'] ) > SN_INSIGHTS_STATE_LIST_CAP ) {
		asort( $state['snoozed_until'] );
		$state['snoozed_until'] = array_slice( $state['snoozed_until'], -SN_INSIGHTS_STATE_LIST_CAP, null, true );
	}
	update_option( SN_INSIGHTS_STATE_OPT, $state, false );
}

function snt_insights_dismiss( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	if ( ! in_array( $rec_id, $state['dismissed_ids'], true ) ) {
		$state['dismissed_ids'][] = $rec_id;
		snt_insights_state_write( $state );
	}
}

function snt_insights_snooze( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	$state['snoozed_until'][ $rec_id ] = time() + ( SN_INSIGHTS_SNOOZE_DAYS * DAY_IN_SECONDS );
	snt_insights_state_write( $state );
}

function snt_insights_mark_done( $rec_id ) {
	$rec_id = (string) $rec_id;
	if ( '' === $rec_id ) { return; }
	$state = snt_insights_state_read();
	if ( ! in_array( $rec_id, $state['done_ids'], true ) ) {
		$state['done_ids'][] = $rec_id;
		snt_insights_state_write( $state );
	}
}

/**
 * Filter a recommendations array against the saved state.
 * Hides dismissed + active-snoozed. Done recs stay (callers can flag).
 */
function snt_insights_filter_active( $recommendations ) {
	if ( ! is_array( $recommendations ) ) { return array(); }
	$state = snt_insights_state_read();
	$now   = time();
	$dismissed = array_flip( $state['dismissed_ids'] );
	$snoozed   = $state['snoozed_until'];

	$out = array();
	foreach ( $recommendations as $rec ) {
		$id = isset( $rec['id'] ) ? (string) $rec['id'] : '';
		if ( '' === $id ) { continue; }
		if ( isset( $dismissed[ $id ] ) ) { continue; }
		if ( isset( $snoozed[ $id ] ) && (int) $snoozed[ $id ] > $now ) { continue; }
		$out[] = $rec;
	}
	return $out;
}

/**
 * Run a full scan: collect signals → call AI → parse → cache.
 *
 * @param bool $force If true, bypass the 7-day cache.
 * @return array|WP_Error Scan result OR WP_Error.
 */
function snt_insights_run_scan( $force = false ) {
	if ( ! $force ) {
		$cached = snt_insights_last_scan();
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$started = microtime( true );

	$signals = snt_insights_collect_signals();
	$raw     = snt_insights_call_ai( $signals );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$parsed = snt_insights_parse_response( $raw );
	if ( is_wp_error( $parsed ) ) {
		// v13.20.4: a parse failure is frequently NOT a parse problem — the
		// parser can only report what it was handed. Attach the call's token
		// accounting so the admin page can say why the text was unusable.
		return snt_insights_annotate_budget( $parsed, $raw );
	}

	$result = array(
		'scanned_at'      => time(),
		'elapsed_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
		'recommendations' => $parsed,
		'signal_summary'  => array(
			'posts_count'    => count( $signals['posts'] ),
			'excerpts_count' => count( array_filter( $signals['posts'], function( $p ) { return isset( $p['excerpt'] ) && '' !== $p['excerpt']; } ) ),
			'webhooks_count' => isset( $signals['webhooks']['total_active'] ) ? (int) $signals['webhooks']['total_active'] : 0,
			'cron_hooks_seen' => count( $signals['cron_freshness'] ),
		),
	);

	set_transient( SN_INSIGHTS_CACHE_KEY, $result, SN_INSIGHTS_CACHE_TTL );
	return $result;
}

/**
 * Persist the most recent scan FAILURE so the admin surface can report the real
 * error (code + message) instead of a blanket "configure AI" catch-all. A short
 * TTL is right: this is an ephemeral diagnostic consumed by the very next admin
 * render (Post/Redirect/Get), not durable state. A non-WP_Error is a no-op, so
 * the slot never holds anything but a genuine error.
 *
 * @param WP_Error $err The error returned by snt_insights_run_scan().
 * @return void
 * @since 7.0.1
 */
/**
 * Attach the just-made call's TOKEN ACCOUNTING to a parse failure.
 *
 * Why this exists (live, 2026-08-28). An Insights scan failed with
 * `snt_insights_invalid_json` and the notice showed the model's whole reply:
 * `[ {`. The parser was right and the message was misleading — it named JSON,
 * when the call had generated and BILLED its entire output budget and returned
 * three characters. The tokens were in the usage log the whole time; nothing
 * put them next to the error, so the page could not say so.
 *
 * Extended thinking is the usual explanation (thinking tokens bill as output
 * and count against max_tokens, but never appear in the returned text), but
 * this function deliberately does NOT assert that — it reports the numbers and
 * lets the reader see the shape. A near-exhausted budget beside a near-empty
 * answer is the finding; naming a cause we did not observe would be exactly the
 * overreach that made the original message useless.
 *
 * Honest absence: `completion` stays null when the log holds no entry for this
 * feature. "No record" and "recorded zero tokens" are different answers.
 *
 * @since 13.20.4
 * @param WP_Error $err The parse error.
 * @param string   $raw The raw model text the parser was handed.
 * @return WP_Error The same error, with a `budget` block added to its data.
 */
function snt_insights_annotate_budget( $err, $raw ) {
	if ( ! is_wp_error( $err ) ) {
		return $err;
	}
	$last = function_exists( 'snt_ai_usage_last' ) ? snt_ai_usage_last( 'insights' ) : null;

	$data = $err->get_error_data();
	if ( ! is_array( $data ) ) {
		$data = array();
	}
	$data['budget'] = array(
		'max_tokens' => (int) SN_INSIGHTS_MAX_TOKENS,
		'completion' => ( is_array( $last ) && isset( $last['completion'] ) ) ? (int) $last['completion'] : null,
		'prompt'     => ( is_array( $last ) && isset( $last['prompt'] ) ) ? (int) $last['prompt'] : null,
		'chars'      => strlen( trim( (string) $raw ) ),
	);

	return new WP_Error( $err->get_error_code(), $err->get_error_message(), $data );
}

function snt_insights_store_last_error( $err ) {
	if ( ! is_wp_error( $err ) ) {
		return;
	}
	// v7.1.0: also capture the model's RAW output when the error carries it (the
	// invalid-json error attaches `raw` = the first 500 chars of the response), so
	// the admin notice can show exactly what came back — the definitive diagnostic
	// for a parse failure. Bounded to 300 chars for the transient + the notice.
	$data = $err->get_error_data();
	$raw  = ( is_array( $data ) && isset( $data['raw'] ) ) ? (string) $data['raw'] : '';
	set_transient(
		SN_INSIGHTS_LAST_ERROR_KEY,
		array(
			'code'    => (string) $err->get_error_code(),
			'message' => (string) $err->get_error_message(),
			'raw'     => '' !== $raw ? substr( $raw, 0, 500 ) : '',
			// v13.20.4: carry the token accounting through the flatten, so the
			// admin notice can explain a near-empty answer instead of only
			// showing it. Absent for every error that is not a parse failure.
			'budget'  => ( is_array( $data ) && isset( $data['budget'] ) && is_array( $data['budget'] ) ) ? $data['budget'] : null,
			'at'      => time(),
		),
		15 * MINUTE_IN_SECONDS
	);
}

/**
 * Read the stored last-scan error, or null when none is recorded.
 *
 * @return array{code:string,message:string,at:int}|null
 * @since 7.0.1
 */
function snt_insights_last_error() {
	$err = get_transient( SN_INSIGHTS_LAST_ERROR_KEY );
	return is_array( $err ) ? $err : null;
}

/**
 * Clear any stored last-scan error (called after a scan succeeds, so a stale
 * failure never lingers on the surface).
 *
 * @return void
 * @since 7.0.1
 */
function snt_insights_clear_last_error() {
	delete_transient( SN_INSIGHTS_LAST_ERROR_KEY );
}

/**
 * Read the cached scan result, or null if no cache.
 */
function snt_insights_last_scan() {
	$cached = get_transient( SN_INSIGHTS_CACHE_KEY );
	return is_array( $cached ) ? $cached : null;
}

/**
 * Returns true if the weekly cron is opted in.
 */
function snt_insights_weekly_cron_enabled() {
	return (bool) sn_setting( 'insights.weekly_cron_enabled', false );
}

/**
 * Schedule the weekly cron if the setting is on AND not already scheduled.
 */
function snt_insights_maybe_schedule_weekly_cron() {
	if ( ! snt_insights_weekly_cron_enabled() ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_INSIGHTS_CRON_HOOK ) ) {
		// First firing in 1 hour; recurrence is weekly.
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', SN_INSIGHTS_CRON_HOOK );
	}
}

/**
 * Cancel any scheduled weekly cron event.
 */
function snt_insights_unschedule_weekly_cron() {
	$ts = wp_next_scheduled( SN_INSIGHTS_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, SN_INSIGHTS_CRON_HOOK );
	}
}

/**
 * Cron handler: run a forced scan (bypasses 7-day cache).
 */
function snt_insights_weekly_scan_cb() {
	snt_insights_run_scan( true );
}
add_action( SN_INSIGHTS_CRON_HOOK, 'snt_insights_weekly_scan_cb' );

// v4.1.1 (B-04): hook on `init` (not `admin_init`) — see cron-history.php for rationale.
// admin_init does not fire on WP-CLI or front-end-only requests, so the cron
// was never scheduled on installs whose first hit wasn't an admin page.
add_action( 'init', 'snt_insights_maybe_schedule_weekly_cron' );

/**
 * Compact summary for desktop-mode's wp_localize_script.
 * Mirrors snt_cron_summary_for_localize from v3.0.0.
 * @since 3.6.0
 */
function snt_insights_summary_for_localize() {
	$last = snt_insights_last_scan();
	if ( ! is_array( $last ) ) {
		return array(
			'scanned_at'      => null,
			'active_count'    => 0,
			'total_count'     => 0,
		);
	}
	$state = snt_insights_state_read();
	$active = snt_insights_filter_active( $last['recommendations'] );
	return array(
		'scanned_at'      => isset( $last['scanned_at'] ) ? (int) $last['scanned_at'] : null,
		'active_count'    => count( $active ),
		'total_count'     => count( $last['recommendations'] ),
		'dismissed_count' => count( $state['dismissed_ids'] ),
		'done_count'      => count( $state['done_ids'] ),
	);
}
