<?php
/**
 * Signal & Noise Tools — Insights Tab + Content Opportunity Advisor.
 *
 * Cross-system AI synthesis: combines Plausible analytics + WP publish
 * history + webhook delivery patterns + cron firings + site identity
 * into a single AI call that returns 5 actionable recommendations per
 * scan ("write_about", "update_post", "cadence_change",
 * "topic_double_down", "topic_pivot").
 *
 * 4-surface dispatch (same pattern as Cron/Webhooks/Health):
 *   - wp-admin form (Insights tab → Run Analysis button)
 *   - REST POST  /signal-noise/v1/insights/run
 *   - REST GET   /signal-noise/v1/insights/last
 *   - Abilities API: signal-noise/run-insights-scan + get-insights
 *   - desktop-mode ⌘K: sn-cmd-insights (aiCallable)
 *
 * All four converge on the snt_insights_*_impl() pure functions below.
 *
 * @package SignalNoiseTools
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_INSIGHTS_CACHE_KEY',         'sn_insights_last_scan' );
define( 'SN_INSIGHTS_CACHE_TTL',         7 * DAY_IN_SECONDS );
define( 'SN_INSIGHTS_STATE_OPT',         'sn_insights_state' );
define( 'SN_INSIGHTS_CRON_HOOK',         'sn_insights_weekly_scan' );
define( 'SN_INSIGHTS_POST_CAP',          100 );
define( 'SN_INSIGHTS_POST_MAX_AGE_DAYS', 730 );  // 2 years
define( 'SN_INSIGHTS_STATE_LIST_CAP',    200 );  // FIFO cap per state list
define( 'SN_INSIGHTS_SNOOZE_DAYS',       30 );
define( 'SN_INSIGHTS_MIN_VALID_RECS',    3 );    // below this → scan failed
