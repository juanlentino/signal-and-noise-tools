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
