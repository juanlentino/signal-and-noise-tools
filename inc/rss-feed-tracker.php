<?php
/**
 * RSS feed-request tracker — companion plugin module.
 *
 * Logs every non-bot RSS feed request to `wp_rss_feed_log` (the source of
 * truth for the widget + activity tab) and fires a first-party "RSS Feed
 * Request" custom event to the SN collector (the Cloudflare Worker's
 * `/_sn/px`) so feed traffic surfaces in Analytics → Events alongside the
 * rest of the first-party analytics. Renders a 30-day count widget on the WP
 * admin dashboard and a full settings/stats tab under
 * Appearance → Signal & Noise → RSS.
 *
 * v6.20.0: repointed from Plausible (`/api/event`) to the first-party
 * collector, finishing the Plausible retirement begun in v6.0.0. The event
 * keeps the name "RSS Feed Request" so it continues the series imported from
 * Plausible. v6.20.1: file renamed `rss-plausible-tracker.php` →
 * `rss-feed-tracker.php` and the dead v1.1.0 MU-twin migration guard removed
 * (the migration completed at theme v8.2.1). Function names, option keys, the
 * `rss_feed_log` table, and the cron hook are unchanged.
 *
 * Migration history: originally shipped as `mu-plugins/rss-plausible-tracker.php`
 * in the Signal & Noise theme repo (v1.2.0 of the MU plugin). Moved to the
 * companion plugin in signal-and-noise-tools v1.1.0; the theme dropped the MU
 * file at v8.2.1. Same DB table, same option keys, same cron hook — only the
 * file location changed (now part of the plugin's `require_once` chain instead
 * of being auto-loaded by WordPress as an MU plugin).
 *
 * "Survives theme switches" property is preserved as long as this plugin
 * stays active.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RSS_TRACKER_DB_VERSION_OPT = 'sn_rss_tracker_db_version';
const SN_RSS_TRACKER_DB_VERSION     = '1.0.0';
const SN_RSS_TRACKER_TABLE          = 'rss_feed_log';
const SN_RSS_TRACKER_SETTINGS_OPT   = 'sn_rss_tracker_settings';
const SN_RSS_TRACKER_NONCE          = 'sn_rss_tracker_action';
const SN_RSS_TRACKER_CRON_HOOK      = 'sn_rss_tracker_daily_prune';
const SN_RSS_TRACKER_ACTION_SAVE    = 'save_settings';
const SN_RSS_TRACKER_ACTION_PURGE   = 'purge_log';
const SN_RSS_TRACKER_ACTION_RESET   = 'reset_defaults';

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/rss-feed-tracker-capture-parts.php';
require_once __DIR__ . '/rss-feed-tracker-form-parts.php';
require_once __DIR__ . '/rss-feed-tracker-render-parts.php';
