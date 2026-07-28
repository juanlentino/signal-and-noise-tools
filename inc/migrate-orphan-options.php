<?php
/**
 * Signal & Noise Tools — one-time orphan-option cleanup.
 *
 * Removes options left behind by features that have since changed shape.
 * Sentinel-gated on admin_init so each orphan is deleted exactly once.
 * (Install-time hooks run inside the OLD code's request and can't self-observe;
 * admin_init + a sentinel option is the safe pattern — mirrors
 * sn_webhooks_migrate_autoload in inc/webhooks.php.)
 *
 * @package SignalNoiseTools
 * @since 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete orphaned options once.
 *
 * v5.0.0 removes `sn_login_rewrites_flushed`, orphaned since v4.2.1 when login
 * routing moved off add_rewrite_rule() to the plugins_loaded intercept — the
 * option was a rewrite-flush sentinel for a code path that no longer exists.
 *
 * @since 5.0.0
 */
function sn_migrate_remove_orphan_options() {
	if ( get_option( 'sn_orphan_options_removed_v5' ) ) {
		return;
	}
	delete_option( 'sn_login_rewrites_flushed' );
	update_option( 'sn_orphan_options_removed_v5', 1, false );
}
add_action( 'admin_init', 'sn_migrate_remove_orphan_options' );

/**
 * Delete the Plausible Stats-API orphans once (v6.0.0).
 *
 * v6.0.0 retires the Plausible Stats-API integration (first-party edge
 * analytics replaced it as the stats source since v5.2.0). The retired
 * Plausible module (v6.0.0) left behind:
 *   - option    `sn_plausible_stats_token`  (admin-saved Stats API token)
 *   - transient `sn_plausible_dashboard_v4` (7-day batched SWR cache)
 *   - transient `sn_plausible_realtime_v3`  (realtime visitor SWR cache)
 *   - transient `sn_plausible_last_error`   (last API error diagnostic)
 *   - scheduled hooks `sn_plausible_refresh_dashboard` + `_realtime`
 *     (now have no registered handler — their callbacks are gone).
 *
 * Keys are hardcoded here on purpose: the constants that named them
 * (SN_PLAUSIBLE_*) were defined in the now-deleted module, so they no
 * longer exist at runtime. Verified against the deleted plausible-api.php.
 *
 * Operators with `SN_PLAUSIBLE_STATS_TOKEN` defined in wp-config.php can
 * remove that constant — nothing reads it anymore.
 *
 * @since 6.0.0
 */
function sn_migrate_remove_plausible_orphans() {
	if ( get_option( 'sn_plausible_orphans_removed_v6' ) ) {
		return;
	}
	delete_option( 'sn_plausible_stats_token' );
	delete_transient( 'sn_plausible_dashboard_v4' );
	delete_transient( 'sn_plausible_realtime_v3' );
	delete_transient( 'sn_plausible_last_error' );
	wp_clear_scheduled_hook( 'sn_plausible_refresh_dashboard' );
	wp_clear_scheduled_hook( 'sn_plausible_refresh_realtime' );
	update_option( 'sn_plausible_orphans_removed_v6', 1, false );
}
add_action( 'admin_init', 'sn_migrate_remove_plausible_orphans' );

/**
 * Delete the retired Overview landing-preview flag once (v9.68.0).
 *
 * v9.67.0 shipped the flag-gated "Overview (preview)" tab behind the
 * `sn_analytics_landing_preview` option; v9.68.0 graduated the tab to the
 * permanent default landing and removed the whole flag machinery (the option
 * reader, its filter seam, the settings fold, and the save handler). A site
 * that had the preview ON keeps a dormant option row nothing reads anymore —
 * the save handler deleted the row only when toggled OFF, so upgrading with
 * the flag still on strands it. Deleted here exactly once.
 *
 * @since 9.68.0
 */
function sn_migrate_remove_landing_preview_orphan() {
	if ( get_option( 'sn_orphan_options_removed_v968' ) ) {
		return;
	}
	delete_option( 'sn_analytics_landing_preview' );
	update_option( 'sn_orphan_options_removed_v968', 1, false );
}
add_action( 'admin_init', 'sn_migrate_remove_landing_preview_orphan' );

/**
 * v10.0.0: the Machine Readers preview flag graduated to the permanent tab, so
 * `sn_machine_readers_preview` is a dormant row nothing reads. Deleted here
 * exactly once — the same stranded-flag class as v9.68.0's landing preview
 * (which is why that lesson is applied at GA rather than discovered later).
 *
 * @since 10.0.0
 */
function sn_migrate_remove_machine_readers_preview_orphan() {
	if ( get_option( 'sn_orphan_options_removed_v1000' ) ) {
		return;
	}
	delete_option( 'sn_machine_readers_preview' );
	update_option( 'sn_orphan_options_removed_v1000', 1, false );
}
add_action( 'admin_init', 'sn_migrate_remove_machine_readers_preview_orphan' );
