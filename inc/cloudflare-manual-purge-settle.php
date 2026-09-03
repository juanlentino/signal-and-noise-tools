<?php
/**
 * Signal & Noise Tools — record a manual purge's verdict once it has SETTLED.
 *
 * THE DEFECT. Pressing "Purge caches" wrote a verdict into the probe log from
 * the theme's INLINE probe — the one that runs in the same request that
 * dispatched the Cloudflare zone purge. That probe samples one moment of
 * propagation, so it books "stale" whenever the colo serving the origin box has
 * not caught up yet.
 *
 * Measured 2026-09-02/03: FOUR OF ELEVEN manual purges recorded stale, including
 *
 *   04:09:13  fresh
 *   04:09:42  stale      <- 29 seconds later
 *
 * while EVERY auto purge over the same window resolved fresh. Auto purges take
 * the theme's deferred cron verify (sn_verify_auto_purge_cron, +75s); manual
 * purges were explicitly excluded from it, on the reasoning that they "already
 * probed inline". They do — with the one probe that cannot be trusted.
 *
 * Theme v12.18.2 lets a non-resolving manual purge defer like an auto purge, so
 * `sn_last_purge_report` becomes correct on its own. This closes the other half:
 * the log row must be written from the SETTLED report, not from the inline
 * sample that preceded it.
 *
 * WHY IT WAITS ON A MARKER, NOT A DELAY. The theme's verify is a WP-cron event,
 * and WP-cron is traffic-driven — on a quiet site "+75 seconds" can be much
 * later. A fixed plugin delay would be racing another cron, which is the same
 * class of bug one layer up. This waits for `verify === 'cron'` on a report
 * whose epoch still matches, and re-checks a bounded number of times.
 *
 * A `fresh` inline reading is still recorded immediately and never deferred: it
 * saw the new epoch AT THE EDGE, which cannot be a false positive, and recording
 * it at once preserves what v13.70.0 was for — pressing Purge visibly updates
 * the cell.
 *
 * @package SignalNoiseTools
 * @since   13.88.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hook for the settle check. */
const SN_CF_SETTLE_HOOK = 'snt_cf_settle_manual_purge';

/**
 * Seconds between settle checks.
 *
 * Comfortably past the theme's SN_AUTO_PURGE_VERIFY_DELAY (75s) so the first
 * check normally finds a settled report, while the marker test — not this
 * number — is what actually decides.
 */
const SN_CF_SETTLE_DELAY = 150;

/** How many times to look before giving up. Bounded: silence beats a guess. */
const SN_CF_SETTLE_MAX_ATTEMPTS = 3;

/** The report the theme writes and later corrects. */
const SN_CF_SETTLE_REPORT_OPT = 'sn_last_purge_report';

/**
 * Schedule a settle check for one purge epoch.
 *
 * Deduplicated on the epoch, so pressing Purge repeatedly against the same
 * epoch cannot stack checks. A NEWER purge bumps the epoch and supersedes this
 * one on its own — the handler checks that before recording anything.
 *
 * @param int $epoch   The render epoch this purge bumped to.
 * @param int $attempt 1-based attempt counter.
 * @return bool True when a check is scheduled.
 */
function snt_cf_schedule_settle( $epoch, $attempt = 1 ) {
	$epoch   = (int) $epoch;
	$attempt = (int) $attempt;
	if ( $epoch < 1 || $attempt > SN_CF_SETTLE_MAX_ATTEMPTS ) {
		return false;
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
		return false;
	}

	$args = array( $epoch, $attempt );
	$next = wp_next_scheduled( SN_CF_SETTLE_HOOK, $args );
	if ( $next && function_exists( 'wp_unschedule_event' ) ) {
		wp_unschedule_event( $next, SN_CF_SETTLE_HOOK, $args );
	}

	return false !== wp_schedule_single_event( time() + SN_CF_SETTLE_DELAY, SN_CF_SETTLE_HOOK, $args );
}

/**
 * Record the manual purge's verdict, once the deferred verify has produced one.
 *
 * Three outcomes, and two of them deliberately record NOTHING:
 *
 *   superseded  — a newer purge owns the answer now. Recording ours would
 *                 describe an edge state nobody is asking about.
 *   not settled — the deferred verify has not run. That is an absence of
 *                 evidence, which is the log's standing rule, so it re-checks
 *                 and eventually gives up silently rather than guessing.
 *   settled     — record `resolved` as the verdict. THIS is the trustworthy
 *                 reading: taken after propagation, by the same probe the
 *                 auto-purge path has always used.
 *
 * @param int $epoch   Epoch this check was scheduled for.
 * @param int $attempt 1-based attempt counter.
 * @return void
 */
function snt_cf_settle_manual_purge( $epoch, $attempt = 1 ) {
	$epoch   = (int) $epoch;
	$attempt = (int) $attempt;

	if ( ! function_exists( 'snt_cf_probe_record' ) ) {
		return;
	}

	$report = get_option( SN_CF_SETTLE_REPORT_OPT, null );
	if ( ! is_array( $report ) || (int) ( $report['epoch'] ?? 0 ) !== $epoch ) {
		return; // superseded, or gone.
	}

	// The theme stamps verify => 'cron' when the DEFERRED probe has run. Until
	// then `resolved` still carries the inline sample, which is the value this
	// whole module exists to stop recording.
	if ( 'cron' !== (string) ( $report['verify'] ?? '' ) ) {
		snt_cf_schedule_settle( $epoch, $attempt + 1 );
		return;
	}

	snt_cf_probe_record( array(
		'time'    => time(),
		'post_id' => 0,
		'url'     => function_exists( 'home_url' ) ? home_url( '/' ) : '',
		'result'  => empty( $report['resolved'] ) ? 'stale' : 'fresh',
		'source'  => 'manual_zone_purge',
	) );
}
add_action( SN_CF_SETTLE_HOOK, 'snt_cf_settle_manual_purge', 10, 2 );
