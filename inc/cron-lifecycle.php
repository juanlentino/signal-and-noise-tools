<?php
/**
 * Signal & Noise Tools — the plugin's cron events leave with the plugin.
 *
 * 15.3.4. The Plugin Handbook (Cron › Scheduling WP Cron Events) says a plugin
 * unschedules its events in its deactivation hook: a deactivated plugin's
 * events stay in the `cron` option and WP-Cron keeps firing them into a
 * callback that no longer exists. Every module here re-arms its own event on
 * `init` through `wp_next_scheduled()`, so re-activation needs nothing.
 *
 * `wp_unschedule_hook()` (core, 4.9) removes every event on a hook regardless
 * of arguments, which is what the single events carrying payloads need.
 * `tests/cron-lifecycle.php` pins this list against every schedule site in
 * `inc/`, so a new cron cannot ship without joining it.
 *
 * @package SignalNoiseTools
 * @since 15.3.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every hook this plugin schedules, recurring or single.
 *
 * @return string[]
 */
function sn_cron_hooks() {
	return array(
		// Recurring.
		SNT_CRON_HISTORY_CRON_HOOK,
		SNT_GSC_COVERAGE_HOOK,
		SNT_GSC_SYNC_HOOK,
		SNT_IPV6_CRITERION_HOOK,
		SNT_ML_REBUILD_HOOK,
		SNT_MORNING_BRIEF_CRON_HOOK,
		SNT_SCHEDULED_READS_CRON_HOOK,
		SN_ANALYTICS_ROLLUP_DAILY_HOOK,
		SN_AUDIT_PRUNE_HOOK,
		SN_CF_MONITOR_HOOK,
		SN_CIT_CRON_HOOK,
		SN_DISCOGRAPHY_CRON_HOOK,
		SN_EDGE_ROLLUP_HOOK,
		SN_EDGE_RESAMPLE_HOOK, // 17.9.1: one-shot, but a deactivation before it ran must not orphan it.
		SN_FAMILY_DRIFT_HOOK,
		SN_HEALTH_CRON_HOOK,
		SN_INBOUND_PASS_HOOK,
		SN_INSIGHTS_CRON_HOOK,
		SN_MR_SNAPSHOT_HOOK,
		SN_PROV_CONFIRM_HOOK,
		SN_RSS_TRACKER_CRON_HOOK,
		SN_SCHEDULE_RECONCILE_HOOK,
		SN_SECURITY_DIGEST_CRON_HOOK,
		SN_SESSION_ROLLUP_HOOK,
		SNT_ANALYTICS_RECOMPUTE_HOOK,
		SN_ZENODO_PASS_HOOK,
		SN_BING_SYNC_HOOK,
		SN_JEV_SYNC_HOOK,
		SN_JEV_FIT_HOOK, // 16.5.0: weekly query-to-page fit.
		SN_JEV_TAGS_HOOK, // 16.8.0: weekly tag fit.
		SNT_NSM_ZENODO_HOOK, // the north star's daily Zenodo stats snapshot.
		SN_RIGHTS_EVIDENCE_HOOK, // 17.0.0: daily rights evidence.
		SN_UPTIME_STATUS_AVAIL_WARM_HOOK, // 18.1.0: hourly 30d availability warmer.
		'snt_deploy_workers_warm',
		SNT_PDF_ENGINE_HOOK, // 20.6.0: daily latest-Dompdf read.
		SN_AI_MODELS_HOOK,   // 21.0.0: daily AI model-list read.
		SNT_ALERTS_HOOK, // Unreleased: hourly spike and break alerts.
		// Single events, some with arguments.
		SN_ARCHIVE_PUSH_HOOK, // one Internet Archive capture after a first publish.
		SN_ARCHIVE_EXISTING_HOOK, // 21.0.1: one tick of the owner-started run over older notes.
		SNT_DEPLOY_HISTORY_PURGE_HOOK,
		SNT_GSC_INSPECT_ONE_HOOK,
		SNT_ML_REBUILD_ASYNC_HOOK,
		SN_ANALYTICS_REALTIME_HOOK,
		SN_ANALYTICS_ROLLUP_HOOK,
		SN_CF_PROBE_HOOK,
		SN_CF_RETRY_HOOK,
		SN_INBOUND_PASS_PUBLISH_HOOK,
		SN_INDEXNOW_CRON_HOOK,
		SN_NARRATION_HOOK,
		SN_PROV_DISPATCH_ASYNC_HOOK,
		SN_SCHEDULE_FIRE_HOOK,
		SN_WEBHOOK_DISPATCH_HOOK,
		SN_WEBSUB_CRON_HOOK,
		SN_ZENODO_HOOK,
		'snt_prepop_event',
		'snt_core_version_refill', // one-off core update check after an object-cache flush.
	);
}

/**
 * Deactivation: unschedule every hook above. Runs from
 * `register_deactivation_hook( __FILE__, ... )` in the main plugin file.
 *
 * @return string[] The hooks unscheduled.
 */
function sn_cron_deactivate() {
	$hooks = array_values( array_unique( sn_cron_hooks() ) );
	foreach ( $hooks as $hook ) {
		wp_unschedule_hook( $hook );
	}
	return $hooks;
}
