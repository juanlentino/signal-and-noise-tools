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
 *   - Abilities API: signal-noise/list-cron-events + get-cron-event
 *   - desktop-mode ⌘K: sn-cmd-cron-health + sn-cmd-cron-list (read-only)
 *
 * All 4 surfaces converge on the snt_cron_*_impl() pure functions below.
 * Run-now is NOT exposed to AI per spec § 6 / Q4 decision.
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
 * Hook names owned by this plugin. Used by snt_cron_is_sn_owned() to
 * pin SN-owned events at the top of the dashboard table.
 *
 * Kept as a string array (not constants) because the constants live in
 * the modules that schedule them and we want to avoid a require_once
 * cycle. If the list grows, consider exposing via a filter.
 */
function snt_cron_sn_owned_hooks() {
	return array(
		'sn_plausible_refresh_dashboard',
		'sn_plausible_refresh_realtime',
		'sn_rss_tracker_daily_prune',
	);
}

function snt_cron_is_sn_owned( $hook ) {
	return in_array( $hook, snt_cron_sn_owned_hooks(), true );
}
