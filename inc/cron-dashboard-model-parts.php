<?php
/**
 * Signal & Noise Tools: Cron Dashboard, the localize summary and the per-hook expectations (interval, opt-in gates, on-demand).
 *
 * Split from inc/cron-dashboard.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact summary for desktop-mode wp_localize_script. Avoids serializing
 * the full event list into snDesktopData on every admin page load.
 */
function snt_cron_summary_for_localize() {
	$rows = snt_cron_get_events_impl();
	$total = count( $rows );
	$sn_count = 0;
	$orphans = 0;
	foreach ( $rows as $row ) {
		if ( $row['is_sn_owned'] ) {
			$sn_count++;
		}
		if ( ! $row['has_handler'] ) {
			$orphans++;
		}
	}
	// v11.29.2: a STATE, not just counts.
	//
	// A WordPress install always carries core events — wp_version_check,
	// wp_scheduled_delete and friends — so a total of ZERO does not mean "a
	// tidy schedule", it means the scheduler is disabled (DISABLE_WP_CRON with
	// nothing replacing it) or the array was wiped. The widget rendered that
	// GREEN, because nothing was wrong with any event it could see.
	//
	// Absence of faults is not evidence of running. That is the same error the
	// purge verifier made for three months, and the reason it is amber rather
	// than red is that this is a strong suspicion about the site, not a fault
	// this function can prove.
	$state = 'ok';
	$note  = '';
	if ( 0 === $total ) {
		$state = 'warn';
		$note  = __( 'No scheduled events at all — WP-Cron is probably disabled', 'signal-and-noise-tools' );
	} elseif ( $orphans > 0 ) {
		$state = 'warn';
		/* translators: %d orphaned cron events */
		$note = sprintf( _n( '%d event has no handler', '%d events have no handler', $orphans, 'signal-and-noise-tools' ), $orphans );
	}

	// 15.8.2: the widget judged nothing; it counted. Two more readings, from
	// producers that already exist: the cron-health verdict (the Site Health
	// test's facts, minus the HTML) and the soonest SN-owned event, so the card
	// can say the pipeline is alive and not merely registered.
	$health = array();
	if ( function_exists( 'snt_cron_health_summary_impl' ) ) {
		$h      = snt_cron_health_summary_impl();
		$health = array(
			'ok'      => (bool) ( $h['ok'] ?? false ),
			'status'  => (string) ( $h['status'] ?? '' ),
			'summary' => (string) ( $h['summary'] ?? '' ),
			'missing' => array_values( (array) ( $h['missing'] ?? array() ) ),
			'overdue' => count( (array) ( $h['overdue'] ?? array() ) ),
		);
	}
	$next = null;
	$now  = time();
	foreach ( $rows as $row ) {
		// Rows arrive SN-first, then next_run_ts ascending: the first SN row
		// is the soonest of ours. A past next_run_ts is wp-cron lagging, and
		// the widget words it as "due", never as a negative "in".
		if ( ! empty( $row['is_sn_owned'] ) ) {
			$next = array(
				'hook'  => (string) $row['hook'],
				'in_s'  => (int) $row['next_run_ts'] - $now,
			);
			break;
		}
	}

	return array(
		'total'    => $total,
		'sn_count' => $sn_count,
		'orphans'  => $orphans,
		'state'    => $state,
		'note'     => $note,
		'health'   => $health,
		'next'     => $next,
	);
}

/* ─────────────────────────────────────────────────────────────────────
 * Native WordPress Site Health async test (v4.9.0, Task 2)
 *
 * Tools → Site Health → Status runs an async test against the SN cron
 * pipeline. Core fetches the REST route below and renders the returned
 * envelope. This surfaces SN cron health where site admins already look
 * for it (native WP), without a bespoke SN widget.
 * ───────────────────────────────────────────────────────────────────── */

/**
 * The full set of cron hooks the pipeline owns, including the cron-history
 * prune hook (defined in inc/cron-history.php). Kept separate from
 * snt_cron_sn_owned_hooks() (which the dashboard uses to pin rows) so the
 * Site Health test can include the history-prune hook without changing the
 * dashboard's ordering set.
 */
function snt_cron_site_health_hooks() {
	$hooks = snt_cron_sn_owned_hooks();
	if ( defined( 'SNT_CRON_HISTORY_CRON_HOOK' ) ) {
		$hooks[] = SNT_CRON_HISTORY_CRON_HOOK;
	}
	return array_values( array_unique( $hooks ) );
}

/**
 * A next-run instant as a relative-time sentence: "in 5 mins" while it is
 * still ahead, "5 mins overdue" once it is behind (#1222).
 *
 * human_time_diff() always returns the ABSOLUTE difference between its two
 * timestamps, so calling it as human_time_diff($now, $next) and always
 * wrapping the result in "in %s" reads a PAST next_run_ts (wp-cron simply
 * hasn't fired it yet — a stalled or disabled cron, not a data error) as
 * though it were still upcoming. Pure: takes both instants explicitly so it
 * is directly testable without mocking time().
 *
 * @param int $now         Current Unix timestamp.
 * @param int $next_run_ts The hook's next scheduled Unix timestamp.
 * @return string
 */
function snt_cron_next_run_label( $now, $next_run_ts ) {
	$now         = (int) $now;
	$next_run_ts = (int) $next_run_ts;
	$diff        = human_time_diff( $now, $next_run_ts );
	return $next_run_ts < $now
		/* translators: %s: human time diff. */
		? sprintf( __( '%s overdue', 'signal-and-noise-tools' ), $diff )
		/* translators: %s: human time diff. */
		: sprintf( __( 'in %s', 'signal-and-noise-tools' ), $diff );
}

/**
 * Resolve a hook's recurrence interval in seconds (for staleness math).
 * Returns 0 when the schedule is unknown.
 */
function snt_cron_interval_seconds( $hook ) {
	if ( ! function_exists( 'wp_get_schedule' ) || ! function_exists( 'wp_get_schedules' ) ) {
		return 0;
	}
	$slug = wp_get_schedule( $hook );
	if ( ! $slug ) {
		return 0;
	}
	$schedules = wp_get_schedules();
	return isset( $schedules[ $slug ]['interval'] ) ? (int) $schedules[ $slug ]['interval'] : 0;
}

/**
 * Whether a hook is EXPECTED to be scheduled given its feature's config.
 * Mirrors the owning modules' scheduling gates: the weekly insights scan only
 * schedules when enabled (inc/insights.php), and Config-off features leave their
 * hooks unscheduled BY DESIGN and must not read as pipeline issues (the
 * v8.1.2 noise rule). Unknown hooks default to expected; each gate is
 * function_exists-guarded so a module rename fails safe — a real
 * scheduling failure surfaces rather than hiding behind a missing gate.
 *
 * @since 8.1.5
 * @param string $hook Hook name.
 * @return bool True when the hook should be scheduled.
 */
function snt_cron_hook_is_expected( $hook ) {
	$hook = (string) $hook;
	foreach ( snt_cron_opt_in_gates() as $gated_hook => $gate ) {
		if ( $hook !== $gated_hook ) {
			continue;
		}
		// A module that is not loaded cannot say OFF, so its hook stays
		// expected — the pre-v13.56.1 behaviour, and the safe direction: this
		// branch can only REMOVE a false alarm, never hide a real gap, because
		// a loaded module answering ON still yields expected.
		return function_exists( $gate ) ? (bool) call_user_func( $gate ) : true;
	}
	return true;
}

/**
 * The opt-in modules: hook => the module's OWN enable predicate.
 *
 * WHY THIS EXISTS (v13.56.1). snt_cron_hook_is_expected() used to say "yes"
 * for every hook but one, so an opt-in feature that was OFF — and had
 * therefore unscheduled its own hook, deliberately — read as "expected but
 * not scheduled". Site Health carried that false alarm quietly; the
 * cron-health summary (v13.52.0) put it somewhere read, and on its first live
 * reading it flagged snt_morning_brief_daily and snt_scheduled_reads_daily,
 * both simply switched off.
 *
 * DERIVED, not remembered: every entry is a module that unschedules its own
 * hook when its gate says off — the grep is `wp_unschedule_event(` beside an
 * enable predicate. If you add such a module, add its gate here, and the
 * parity test in tests/cron-dashboard.php will tell you if you forget: it
 * walks the same grep.
 *
 * @since 13.56.1
 * @return array<string,string> hook => predicate function name.
 */
function snt_cron_opt_in_gates() {
	$pairs = array(
		array( 'SN_INSIGHTS_CRON_HOOK',         'sn_insights_weekly_scan',   'snt_insights_weekly_cron_enabled' ),
		array( 'SNT_MORNING_BRIEF_CRON_HOOK',   'snt_morning_brief_daily',   'snt_morning_brief_enabled' ),
		array( 'SNT_SCHEDULED_READS_CRON_HOOK', 'snt_scheduled_reads_daily', 'snt_scheduled_reads_enabled' ),
		array( 'SN_SECURITY_DIGEST_CRON_HOOK',  'sn_security_digest_weekly', 'snt_security_digest_enabled' ),
		// Readiness rather than a toggle, but the same contract: the module
		// keeps its schedule equal to the predicate, so absence-when-false is
		// correct, not missing.
		array( 'SNT_GSC_SYNC_HOOK',             'sn_gsc_sync_daily',         'snt_gsc_sync_is_ready' ),
		array( 'SN_ZENODO_PASS_HOOK',           'sn_zenodo_backfill_pass',   'sn_zenodo_is_enabled' ),
		array( 'SN_BING_SYNC_HOOK',             'sn_bing_sync_daily',        'sn_bing_is_ready' ),
		array( 'SN_JEV_SYNC_HOOK',              'sn_jev_notes_daily',        'sn_jev_is_ready' ),
		array( 'SN_JEV_FIT_HOOK',               'sn_jev_fit_weekly',         'sn_jev_fit_is_ready' ),
		array( 'SN_JEV_TAGS_HOOK',              'sn_jev_tags_weekly',        'sn_jev_is_ready' ),
		array( 'SN_RIGHTS_EVIDENCE_HOOK',       'sn_rights_evidence_daily',  'sn_rights_evidence_is_ready' ),
		array( 'SN_UPTIME_STATUS_AVAIL_WARM_HOOK', 'sn_uptime_availability_hourly', 'sn_uptime_status_configured' ), // 18.1.0.
		array( 'SN_ARCHIVE_CONFIRM_HOOK',       'sn_archive_confirm_hourly', 'sn_archive_confirm_enabled' ), // on with the Internet Archive keys.
		// v13.63.0 — same readiness predicate, weekly.
		array( 'SNT_GSC_COVERAGE_HOOK',         'sn_gsc_coverage_weekly',    'snt_gsc_sync_is_ready' ),
	);
	$out = array();
	foreach ( $pairs as $p ) {
		$out[ defined( $p[0] ) ? constant( $p[0] ) : $p[1] ] = $p[2];
	}
	return $out;
}

/**
 * Hooks that are on-demand SINGLE-event hooks: they schedule via
 * wp_schedule_single_event() and CLEAR after firing, so "unscheduled" is
 * their documented resting state — never a pipeline issue (the v8.1.2 noise
 * rule). The analytics SWR warmer is the canonical case: its recurring
 * counterpart (sn_analytics_rollup_daily) is the hook whose schedule the
 * Site Health test should actually judge. Staleness math is also skipped:
 * an on-demand hook's firing cadence tracks admin visits, not cron health
 * (and live single events carry no schedule slug anyway).
 *
 * @since 10.27.1
 * @param string $hook Hook name.
 * @return bool True when the hook fires on demand (no standing schedule).
 */
function snt_cron_hook_is_on_demand( $hook ) {
	$warmer  = defined( 'SN_ANALYTICS_ROLLUP_HOOK' ) ? SN_ANALYTICS_ROLLUP_HOOK : 'sn_analytics_rollup';
	// v13.69.0 — the inbound pass's after-publish run: a single event that clears after firing.
	$inbound = defined( 'SN_INBOUND_PASS_PUBLISH_HOOK' ) ? SN_INBOUND_PASS_PUBLISH_HOOK : 'sn_inbound_pass_after_publish';
	// 15.8.2 — the URL Inspection single events (day 3 and day 10 after a
	// publish, v14.7.0). Never listed here, so cron health read the hook as an
	// expected recurring job with no schedule and said "1 expected but not
	// scheduled" on every read since 14.7.0; the Cron widget did not paint the
	// verdict, which is how a standing false alarm stayed invisible.
	$inspect = defined( 'SNT_GSC_INSPECT_ONE_HOOK' ) ? SNT_GSC_INSPECT_ONE_HOOK : 'sn_gsc_inspect_one';
	$zenodo  = defined( 'SN_ZENODO_HOOK' ) ? SN_ZENODO_HOOK : 'sn_zenodo_deposit_one'; // 15.11.0: booked by an anchor confirmation, clears after firing.
	return $hook === $warmer || $hook === $inbound || $hook === $inspect || $hook === $zenodo;
}
