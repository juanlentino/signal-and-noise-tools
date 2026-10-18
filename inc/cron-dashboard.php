<?php
/**
 * Signal & Noise Tools — Cron Dashboard module.
 *
 * Surfaces WP-Cron health in the wp-admin under the Cron tab. For every
 * scheduled cron event, shows next-run, recurrence, last-fired, args,
 * and provides a Run-now button gated by manage_options + confirm().
 *
 * 4-surface dispatch (per the plugin's established Phase 14 pattern):
 *   - wp-admin form (Cron tab → Run-now button)
 *   - REST POST signal-noise/v1/cron/run
 *   - Abilities API: signal-noise/list-cron-events (hook/args_signature
 *     filters since v7.7.0; they subsumed get-cron-event, removed v8.0.0)
 *   - desktop-mode ⌘K: sn-cmd-cron-health + sn-cmd-cron-list (read-only)
 *
 * All 4 surfaces converge on the snt_cron_*_impl() pure functions (cron-dashboard-events-parts.php).
 * Run-now IS a registered ability (signal-noise/run-cron-event, abilities-cron.php).
 * The spec § 6 / Q4 "not exposed to AI" decision predates OpenStation 0.9.8, whose
 * agents Tools picker enrols EVERY registered ability, read or write — non-exposure
 * is no longer platform-enforced. The enforced gates are the ability's own
 * manage_options permission_callback and its destructive/non-idempotent annotations.
 *
 * Last-fired tracking: WordPress core does not track last-fired natively.
 * We register snt_cron_track_last_fired_cb() at PHP_INT_MAX for every
 * unique cron hook during DOING_CRON requests, gated at wp_loaded so
 * non-cron requests pay zero cost.
 *
 * @package SignalNoiseTools
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every ACTIVE recurring/scheduled hook this plugin owns. Used by
 * snt_cron_is_sn_owned() to (a) pin SN events at the top of the dashboard table
 * and (b) refuse unschedule-cron-event for any of them — unscheduling a live SN
 * hook would silently break a rollup / prune / tracker / heartbeat (each
 * self-heals at the next init guard but costs one firing).
 *
 * RETIRED hooks (e.g. the old sn_plausible_* events) are intentionally NOT listed
 * so leftover orphans stay cleanable via the unschedule ability — that is the
 * whole point of cron cleanup. This is why the guard is an explicit allow-list of
 * live hooks, not an sn_/snt_ prefix match (a prefix would wrongly refuse
 * cleanup of a retired SN hook).
 *
 * Constant references (defined()-guarded with a literal fallback for partial-
 * deploy resilience) so a hook rename in its owning module auto-propagates.
 * v6.39.5 (audit fix): expanded from the RSS hook alone to all ~10 live SN hooks;
 * the prior single-entry list made the unschedule guard's "SN-owned refused"
 * docblock false for 9 of 10 hooks. ADD any new recurring SN hook here.
 */
function snt_cron_sn_owned_hooks() {
	$owned = array(
		array( 'SN_RSS_TRACKER_CRON_HOOK',      'sn_rss_tracker_daily_prune' ),
		array( 'SN_ANALYTICS_ROLLUP_HOOK',      'sn_analytics_rollup' ),
		array( 'SN_ANALYTICS_ROLLUP_DAILY_HOOK', 'sn_analytics_rollup_daily' ),
		array( 'SN_AUDIT_PRUNE_HOOK',           'sn_audit_log_prune' ),
		array( 'SNT_CRON_HISTORY_CRON_HOOK',    'snt_cron_history_prune' ),
		array( 'SN_DISCOGRAPHY_CRON_HOOK',      'sn_discography_cron' ),
		array( 'SN_EDGE_ROLLUP_HOOK',           'sn_edge_rollup_cron' ),
		array( 'SN_INSIGHTS_CRON_HOOK',         'sn_insights_weekly_scan' ),
		array( 'SN_MR_SNAPSHOT_HOOK',           'snt_mr_snapshot_refresh' ),
		array( 'SNT_NSM_ZENODO_HOOK',           'snt_nsm_zenodo_daily' ),
		// v11.32.0 made this recurring (5-minute fleet warm). Unscheduling it
		// would return the worker cells to "warming…" with no visible cause.
		array( 'SNT_DEPLOY_WORKERS_WARM_HOOK', 'snt_deploy_workers_warm' ),
		// v13.49.0 — MISSING SINCE THE HOOK SHIPPED, found while building
		// schedule_cron_event. sn_health_scan_daily is scheduled recurring
		// ('daily', inc/health-scan-cron.php:86) and was absent from this list,
		// so the "SN-owned refused" guard did not cover it: unschedule-cron-event
		// — which IS on the rw door — would have silently stopped the daily
		// health scan, with the dashboard's verdict simply ageing and no cause
		// visible anywhere. That is precisely the harm this allow-list exists to
		// prevent, and it is also why the list carries "ADD any new recurring SN
		// hook here" above.
		array( 'SN_HEALTH_CRON_HOOK', 'sn_health_scan_daily' ),
		// v13.62.0 — weekly enum-drift check (weave Phase 5). Always-on.
		array( 'SN_FAMILY_DRIFT_HOOK', 'sn_family_drift_weekly' ),
		// 14.9.0 — the daily Cloudflare monitor (token verify, zone, firewall). Always-on.
		array( 'SN_CF_MONITOR_HOOK', 'sn_cf_monitor_daily' ),
		// v13.91.0: the daily IPv6-criterion store, so the watch reading it costs
		// an option read rather than an uncached analytics query.
		array( 'SNT_IPV6_CRITERION_HOOK', 'snt_ipv6_criterion_refresh' ),
		array( 'SN_ARCHIVE_CONFIRM_HOOK', 'sn_archive_confirm_hourly' ), // hourly: did the Internet Archive capture what it was asked to.
		array( 'SN_AI_MODELS_HOOK', 'snt_ai_models_refresh' ), // 21.0.0: daily read of what the AI providers serve, for the model pickers.
		array( 'SNT_PDF_ENGINE_HOOK', 'snt_pdf_engine_check' ), // 20.6.0: daily latest-Dompdf read the watch consumes.
		array( 'SNT_ALERTS_HOOK', 'snt_alerts_hourly' ), // 20.8.0: hourly spike and break alerts; always scheduled, the toggle is read in the callback.
		array( 'SN_PUBLIC_STATS_MACHINES_HOOK', 'sn_public_stats_machines_refresh' ), // 22.0.0: hourly /stats machine-reads snapshot, off the render path.
		// v13.68.0 — daily inbound-link pass for freshly published notes. Always-on.
		array( 'SN_INBOUND_PASS_HOOK', 'sn_inbound_pass_daily' ),
		array( 'SN_INBOUND_PASS_PUBLISH_HOOK', 'sn_inbound_pass_after_publish' ), // v13.69.0 — single event after a publish; on-demand.
		// v13.63.0 — weekly URL Inspection coverage sync (readiness-gated, see the opt-in map).
		array( 'SNT_GSC_COVERAGE_HOOK', 'sn_gsc_coverage_weekly' ),
		array( 'SNT_GSC_INSPECT_ONE_HOOK', 'sn_gsc_inspect_one' ), // v14.7.0 — single events at day 3 and day 10 after a publish; on-demand, readiness-gated at schedule time.
		// 15.11.0 — Zenodo: the hourly backfill pass (token-gated, see the opt-in map) and the per-document deposit booked by an anchor confirmation.
		array( 'SN_ZENODO_PASS_HOOK', 'sn_zenodo_backfill_pass' ),
		array( 'SN_BING_SYNC_HOOK', 'sn_bing_sync_daily' ), // 16.2.0 — daily Bing Webmaster sync.
		array( 'SN_JEV_SYNC_HOOK', 'sn_jev_notes_daily' ), // 16.3.0 — daily Jev pass over the notes.
		array( 'SN_JEV_FIT_HOOK', 'sn_jev_fit_weekly' ), // 16.5.0 — weekly query-to-page fit.
		array( 'SN_JEV_TAGS_HOOK', 'sn_jev_tags_weekly' ), // 16.8.0 — weekly tag fit.
		array( 'SN_RIGHTS_EVIDENCE_HOOK', 'sn_rights_evidence_daily' ), // 17.0.0: daily rights evidence.
		array( 'SN_UPTIME_STATUS_AVAIL_WARM_HOOK', 'sn_uptime_availability_hourly' ), // 18.1.0: hourly 30d availability warmer.
		array( 'SN_ZENODO_HOOK', 'sn_zenodo_deposit_one' ), // 15.11.0 — single event after an anchor confirms; on-demand.
		// v13.49.0 — NINE more, found by DERIVING the list instead of reading it.
		// Every one of these is scheduled recurring by this plugin and was absent
		// here, so the rw-doored unschedule-cron-event could stop any of them
		// silently: the output just stops arriving, with no error anywhere. The
		// drift was 10 entries deep against a list whose own docblock says "ADD
		// any new recurring SN hook here", which is the argument for the parity
		// test in tests/cron-dashboard.php rather than more remembering.
		//
		// Two of these outrank the health scan by harm:
		//   sn_prov_reconcile — hourly provenance reconciliation. Stopping it
		//     halts pending OpenTimestamps upgrades, and there is a live watch
		//     whose trigger is "an observed window where pending OTS proofs
		//     cannot upgrade". A silent unschedule would MANUFACTURE that
		//     trigger, and the watch would read our own outage as evidence
		//     about Bitcoin anchoring.
		//   sn_schedule_reconcile — every five minutes, and post scheduling
		//     depends on it. Overdue `future` posts are already a sharp edge
		//     (sn-apply refuses on them because core early-publishes on any
		//     write); stopping the reconciler makes that state common.
		array( 'SN_PROV_CONFIRM_HOOK',          'sn_prov_reconcile' ),
		array( 'SN_PROV_ORPHAN_HOOK',           'sn_prov_orphan_sweep' ),
		array( 'SN_SCHEDULE_RECONCILE_HOOK',    'sn_schedule_reconcile' ),
		array( 'SN_CIT_CRON_HOOK',              'sn_citations_verify_batch' ),
		array( 'SN_SECURITY_DIGEST_CRON_HOOK',  'sn_security_digest_weekly' ),
		array( 'SN_SESSION_ROLLUP_HOOK',        'sn_session_rollup_daily' ),
		array( 'SNT_ML_REBUILD_HOOK',           'snt_ml_rebuild' ),
		array( 'SNT_MORNING_BRIEF_CRON_HOOK',   'snt_morning_brief_daily' ),
		array( 'SNT_SCHEDULED_READS_CRON_HOOK', 'snt_scheduled_reads_daily' ),
		array( 'SNT_GSC_SYNC_HOOK',             'sn_gsc_sync_daily' ),
		array( 'SN_DOOR_LEAK_HOOK',             'sn_login_door_leak_check' ),
		array( 'SN_CSP_HASH_HOOK',              'sn_csp_hash_check' ),
		array( 'SN_SEC_GUARD_ROUTES_HOOK',      'sn_security_guard_routes_check' ),
		array( 'SN_FI_HOOK',                    'sn_file_integrity_check' ),
	);
	$hooks = array();
	foreach ( $owned as $pair ) {
		$hooks[] = defined( $pair[0] ) ? constant( $pair[0] ) : $pair[1];
	}
	return array_values( array_unique( $hooks ) );
}

function snt_cron_is_sn_owned( $hook ) {
	return in_array( $hook, snt_cron_sn_owned_hooks(), true );
}

/**
 * Last-fired storage: write helper.
 *
 * Key format: snt_cron_last_fired_<md5(hook)>. md5 avoids the
 * varchar(191) wp_options key column limit for long hook names like
 * 'action_scheduler_run_queue' and handles hook names with slashes.
 *
 * Stored as integer unix timestamp. autoload=false so it doesn't
 * bloat the autoloaded options cache.
 */
function snt_cron_record_last_fired( $hook ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return;
	}
	update_option( 'snt_cron_last_fired_' . md5( $hook ), time(), false );
}

/**
 * Last-fired storage: read helper. Returns int|null.
 */
function snt_cron_last_fired_for( $hook ) {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return null;
	}
	$value = get_option( 'snt_cron_last_fired_' . md5( $hook ), null );
	if ( null === $value || '' === $value ) {
		return null;
	}
	return (int) $value;
}

/**
 * Named callback referenced by both wp_loaded path (registered for each
 * cron hook during DOING_CRON requests) and the synchronous run-now
 * path (registered ad-hoc in snt_cron_run_event_impl). Uses
 * current_action() so one function works for every hook.
 */
function snt_cron_track_last_fired_cb() {
	snt_cron_record_last_fired( current_action() );
}

/**
 * During DOING_CRON requests, register snt_cron_track_last_fired_cb at
 * PHP_INT_MAX for every unique cron hook. This way it fires AFTER the
 * real handler completes, capturing last-fired exactly once per event
 * firing.
 *
 * Gated at wp_loaded priority 1 so non-cron requests pay only one
 * defined() check. Pre-walks _get_cron_array() to register named
 * (not closure) listeners so WordPress's internal callback dedupe
 * works if multiple plugins do this trick.
 *
 * _get_cron_array() is underscore-prefixed (technically private) but
 * stable since WP 2.1 (2007). It's the only way to enumerate all
 * scheduled events; documented in spec § 7.4 as accepted API risk.
 */
add_action( 'wp_loaded', function() {
	if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! function_exists( '_get_cron_array' ) ) {
		return;
	}
	$crons = _get_cron_array();
	if ( empty( $crons ) ) {
		return;
	}
	$seen = array();
	$history_loaded = function_exists( 'snt_cron_history_pre_cb' );
	foreach ( $crons as $events ) {
		foreach ( $events as $hook => $_ ) {
			if ( isset( $seen[ $hook ] ) ) {
				continue;
			}
			$seen[ $hook ] = true;
			add_action( $hook, 'snt_cron_track_last_fired_cb', PHP_INT_MAX );
			// v3.2.0: history capture pair. Pre at -PHP_INT_MAX stashes
			// start time; post at PHP_INT_MAX records elapsed + INSERTs.
			// Bracketing the real handlers gives an accurate elapsed_ms
			// (within PHP precision; some firings will see negligible
			// time but that still surfaces as "fired ok, ~0ms").
			if ( $history_loaded ) {
				add_action( $hook, 'snt_cron_history_pre_cb', -PHP_INT_MAX );
				// #1228: accepted_args was implicitly 1 (the add_action()
				// default), so snt_cron_history_post_cb() never saw the
				// hook's real args and always recorded args_signature
				// against an empty array. 10 is a generous ceiling — no
				// core or plugin cron hook here passes more.
				add_action( $hook, 'snt_cron_history_post_cb', PHP_INT_MAX, 10 );
			}
		}
	}
}, 1 );

// The rest of the module lives in three parts files (house line cap, 2026-10-14),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/cron-dashboard-events-parts.php';
require_once __DIR__ . '/cron-dashboard-model-parts.php';
require_once __DIR__ . '/cron-dashboard-health-parts.php';
