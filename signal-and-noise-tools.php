<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. Operational tooling: REST surface, first-party edge analytics, security headers, Cloudflare purge, admin UI, RSS subscriber tracker. Self-updater migrates in Phase 2.
 * Version:     6.16.0
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Author:      Juan Lentino
 * Author URI:  https://juanlentino.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// v3.8.2: derive SNT_VERSION from the docblock Version header above so future
// version bumps need only edit the docblock (single source of truth). Previously
// hardcoded as a literal string, which drifted: bumping the docblock to 3.8.0 then
// 3.8.1 left this constant at '3.7.6', causing (1) wrong version on the dashboard
// widget that reads SNT_VERSION directly, and (2) stale `?ver=…` cache-buster on
// admin.css so browsers served the OLD admin.css that lacked `.sn-sub-tabs` rules
// (file on disk was updated but URL cache key didn't change → browser served cached
// content). Reading from the docblock at load eliminates the two-sources-of-truth bug.
$snt_plugin_data = function_exists( 'get_file_data' )
	? get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' )
	: array( 'Version' => '0.0.0' );
define( 'SNT_VERSION', $snt_plugin_data['Version'] ?: '0.0.0' );
unset( $snt_plugin_data );
define( 'SNT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Pre-flight: detect a legacy-theme conflict before loading any modules.
 *
 * Phase 1 of the theme/plugin split shipped as a coordinated release —
 * plugin v1.0.0 first, then theme v8.2.0. Activating the plugin while
 * the theme is still at v8.1.x leaves the 9 legacy module files on disk
 * inside the theme. PHP would then fatal with "Cannot redeclare function
 * sn_*" at theme-load time, the moment both packages try to declare the
 * same function names on the same request.
 *
 * The fix: if any of the legacy theme files is still present, skip the
 * entire require_once chain below and surface an admin notice asking the
 * maintainer to update the theme first. Detection runs against one
 * canonical file (inc/admin-page.php) — the theme either has all 9 of
 * the legacy modules or none of them; checking one is sufficient.
 *
 * @since 1.0.1
 */
$snt_stylesheet = get_option( 'stylesheet', '' );
if ( 'signal-and-noise' === $snt_stylesheet
	&& file_exists( WP_CONTENT_DIR . '/themes/' . $snt_stylesheet . '/inc/admin-page.php' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p><strong>Signal &amp; Noise Tools:</strong> The Signal &amp; Noise theme is still at v8.1.x (the legacy <code>inc/</code> modules that were supposed to migrate to this plugin are still on disk inside the theme). <strong>Please update the theme to v8.2.0+ before this plugin can load</strong> — otherwise PHP would fatal on duplicate function declarations.</p></div>';
	} );
	return; // Skip the entire require chain; the theme owns the modules right now.
}

// Module includes.
require_once SNT_PATH . 'inc/settings.php';
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/analytics-widget.php';
// First-party edge analytics (P2 data layer). analytics-api.php is the AE SQL
// read-client; analytics-rollup.php (its first consumer) must load after it.
require_once SNT_PATH . 'inc/analytics-api.php';
require_once SNT_PATH . 'inc/analytics-rollup.php';
require_once SNT_PATH . 'inc/analytics-realtime.php';
require_once SNT_PATH . 'inc/analytics-read.php';   // path read accessors (dashboard + widgets)
require_once SNT_PATH . 'inc/analytics-dims.php';   // referrer/country/device + edge dimension breakdowns
require_once SNT_PATH . 'inc/analytics-events.php'; // v6.2.0: custom-events table install + read accessors
require_once SNT_PATH . 'inc/analytics-events-rollup.php'; // v6.10.0: live ce/cp rollups feeding the events tables
require_once SNT_PATH . 'inc/analytics-buckets.php'; // derived: hour-of-day heatmap + scroll/time distributions
require_once SNT_PATH . 'inc/analytics-percentiles.php'; // on-demand scroll/time percentiles (p50/p75/p90)
require_once SNT_PATH . 'inc/analytics-drilldown.php'; // on-demand cross-tab dimension drill-down
require_once SNT_PATH . 'inc/analytics-pageroles.php'; // v6.10.0: durable entry/exit page-roles table + entry rollup
require_once SNT_PATH . 'inc/analytics-derived.php'; // PHP-only derived: referrer categories, deltas, bot breakdown
require_once SNT_PATH . 'inc/analytics-import.php'; // one-time Plausible-CSV → first-party rollup back-fill
require_once SNT_PATH . 'inc/analytics-admin-render.php'; // page partials (loaded before the orchestrator)
require_once SNT_PATH . 'inc/analytics-admin.php';  // dashboard renderer + Monitoring → Analytics settings
require_once SNT_PATH . 'inc/analytics-dashboard-page.php'; // WP Dashboard → Analytics read-only page
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
// Admin UI — split out of the former 1,468-line inc/admin-page.php in v4.5.4.
// Order is irrelevant: every cross-call between these modules happens at
// runtime (inside admin_init / admin_menu / render hooks), never at load.
require_once SNT_PATH . 'inc/admin-tabs-data.php';
require_once SNT_PATH . 'inc/admin-tabs.php';
require_once SNT_PATH . 'inc/admin-legacy-redirect.php';
require_once SNT_PATH . 'inc/admin-menu.php';
require_once SNT_PATH . 'inc/admin-flash-messages.php';
require_once SNT_PATH . 'inc/admin-post-handler.php';
require_once SNT_PATH . 'inc/admin-post-actions.php';
require_once SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
require_once SNT_PATH . 'inc/admin-forms/login.php';
require_once SNT_PATH . 'inc/admin-forms/links.php';
require_once SNT_PATH . 'inc/admin-forms/performance.php'; // v4.10.0: Tools → Performance (Speculation Rules toggle)
require_once SNT_PATH . 'inc/admin-forms/release-notes.php'; // v4.11.0 (T4): Tools → Release Notes (AI drafter)
require_once SNT_PATH . 'inc/admin-forms/front-end.php';     // v4.12.0: Tools → Front-End (theme render knobs)
require_once SNT_PATH . 'inc/admin-forms/music.php';         // v4.13.0: Monitoring → Music (Spotify creds + Muso profile + Sync now)
require_once SNT_PATH . 'inc/admin-forms/indexnow.php';     // v5.1.0: Automation → IndexNow (enable toggle + key URL + backfill)
require_once SNT_PATH . 'inc/theme-filters.php';             // v4.12.0: supply configured theme.* values to theme/plugin filters (front-end)
require_once SNT_PATH . 'inc/rest-api.php';
require_once SNT_PATH . 'inc/analytics-rest.php'; // v6.1.0: read-only /analytics REST routes

// Shared outbound SSRF host-guard (resolve-then-range-check; blocks encoded-IP
// metadata bypasses). Pure functions, no hooks — load it BEFORE every consumer:
// rss-plausible-tracker (just below), webhooks, uptime-heartbeat, and
// health-external-links all call sn_ssrf_host_blocked(). (v6.13.2: moved up from
// the webhooks group so the earliest consumer, rss-plausible-tracker, is covered.)
require_once SNT_PATH . 'inc/ssrf-guard.php';

/**
 * Pre-flight guard #2: detect the MU-plugin twin of the RSS tracker.
 *
 * The `inc/rss-plausible-tracker.php` module was originally shipped as
 * `wp-content/mu-plugins/rss-plausible-tracker.php` (auto-loaded by WP
 * regardless of theme/plugin state). signal-and-noise-tools v1.1.0
 * migrates it into this plugin as an early slice of the Phase 4
 * mu-plugins cleanup. If the maintainer hasn't yet deleted the MU file
 * from the live server, both copies would declare the same functions
 * (`sn_rss_tracker_*`) — same redeclare-fatal shape as guard #1 above.
 *
 * The fix: detect the MU twin via `file_exists()` and skip loading our
 * copy if it's present. MU plugin continues serving tracking via its
 * always-active auto-load; admin notice asks the maintainer to delete
 * the MU file at their leisure. Once deleted, the next admin pageview's
 * guard check passes and the plugin's module takes over seamlessly.
 *
 * Same option keys, same table, same cron hook — there's no data
 * migration involved. Hand-off is transparent.
 *
 * @since 1.1.0
 */
if ( defined( 'WPMU_PLUGIN_DIR' )
	&& file_exists( WPMU_PLUGIN_DIR . '/rss-plausible-tracker.php' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-warning"><p><strong>Signal &amp; Noise Tools:</strong> The legacy <code>rss-plausible-tracker.php</code> MU plugin is still active under <code>wp-content/mu-plugins/</code>. Tracking continues to work via the MU plugin for now. <strong>Delete <code>wp-content/mu-plugins/rss-plausible-tracker.php</code> via SFTP</strong> and the next admin pageview will hand tracking over to this plugin (same database, same options — seamless).</p></div>';
	} );
} else {
	require_once SNT_PATH . 'inc/rss-plausible-tracker.php';
}

// ── Guard #3 (v1.3.0): function-redeclare defense ──────────────────
//
// Phase 3 moved og-image.php, reading-time.php, and notes-and-provenance.php
// from theme to plugin. If the theme still ships those files (i.e. user
// installed plugin v1.3.0 before theme v8.4.0), our require_once chain
// would PHP-fatal at parse time with "Cannot redeclare function." Bail
// with a clear admin notice instead of a white-screen-of-death.
$sn_phase3_theme_dir = get_template_directory();
$sn_phase3_retired   = array(
	$sn_phase3_theme_dir . '/inc/og-image.php',
	$sn_phase3_theme_dir . '/inc/reading-time.php',
	$sn_phase3_theme_dir . '/inc/notes-and-provenance.php',
);
foreach ( $sn_phase3_retired as $sn_phase3_legacy_file ) {
	if ( file_exists( $sn_phase3_legacy_file ) ) {
		add_action( 'admin_notices', function() use ( $sn_phase3_legacy_file ) {
			$rel = str_replace( ABSPATH, '', $sn_phase3_legacy_file );
			echo '<div class="notice notice-error"><p><strong>Signal &amp; Noise Tools v1.3.0:</strong> theme still ships <code>' . esc_html( $rel ) . '</code>. Update theme to v8.4.0+ first to avoid function-redeclare fatals. Plugin require chain skipped.</p></div>';
		} );
		return; // Skip the require_once chain entirely.
	}
}
unset( $sn_phase3_theme_dir, $sn_phase3_retired, $sn_phase3_legacy_file );

require_once __DIR__ . '/inc/content-rendering-helpers.php';
require_once __DIR__ . '/inc/content-surfaces.php';
require_once __DIR__ . '/inc/content-migrations.php';
require_once __DIR__ . '/inc/og-card-generator.php';
require_once __DIR__ . '/inc/reading-time.php';
require_once __DIR__ . '/inc/wp-update-integration.php';
require_once __DIR__ . '/inc/wp-update-git-preservation.php';
require_once __DIR__ . '/inc/github-actions-api.php';
require_once __DIR__ . '/inc/deploy-history.php';
require_once __DIR__ . '/inc/api-rate-monitor.php';
require_once __DIR__ . '/inc/admin-tab-dashboard.php';
require_once __DIR__ . '/inc/desktop-mode-integration.php';
require_once __DIR__ . '/inc/ai-bootstrap.php';
require_once __DIR__ . '/inc/ai-alt-inline-suggest.php';
require_once __DIR__ . '/inc/ai-alt-text-suggest.php';
require_once __DIR__ . '/inc/ai-drift-phrase-suggest.php';
require_once __DIR__ . '/inc/ai-orphan-suggest.php';
require_once __DIR__ . '/inc/ai-excerpt.php';
require_once __DIR__ . '/inc/ai-meta-description.php';
require_once __DIR__ . '/inc/ai-og-card-title.php';
require_once __DIR__ . '/inc/ai-ai-dedupe.php';
require_once __DIR__ . '/inc/ai-prepopulate.php';
require_once __DIR__ . '/inc/ai-prepopulate-notice.php';
require_once __DIR__ . '/inc/release-notes-draft.php'; // v4.11.0 (T4): AI release-notes drafter impl + ability wrapper
require_once __DIR__ . '/inc/pattern-adoption-detect.php';
require_once __DIR__ . '/inc/pattern-adoption-suggest.php';
require_once __DIR__ . '/inc/pattern-adoption-apply.php';
require_once __DIR__ . '/inc/pattern-adoption-admin.php';
require_once __DIR__ . '/inc/block-migrations-detect.php';
require_once __DIR__ . '/inc/block-migrations-suggest.php';
require_once __DIR__ . '/inc/block-migrations-apply.php';
require_once __DIR__ . '/inc/block-migrations-admin.php';
require_once __DIR__ . '/inc/abilities-block-migrations.php';
require_once __DIR__ . '/inc/login-hide.php';
require_once __DIR__ . '/inc/seo-schema.php';
require_once __DIR__ . '/inc/discography-store.php';   // v4.13.0: Music Identity — normalized release store (cron is sole writer)
require_once __DIR__ . '/inc/muso-api.php';            // v4.13.0: Music Identity — Muso public credits client + album grouper
require_once __DIR__ . '/inc/spotify-api.php';         // v4.13.0: Music Identity — Spotify album resolver (track id → album)
require_once __DIR__ . '/inc/discography-sync.php';    // v4.13.0: Music Identity — cron sync orchestrator + sn_discography_entries filter
require_once __DIR__ . '/inc/seo-schema-music.php';    // v4.13.0: Music Identity — MusicAlbum/MusicRecording JSON-LD on /music
require_once __DIR__ . '/inc/music-featured.php';      // v4.14.0: settings-driven featured release (sn_music_featured filter)
require_once __DIR__ . '/inc/post-settings.php';
require_once __DIR__ . '/inc/sitemap.php';
require_once __DIR__ . '/inc/sitemap-redirect.php';
require_once __DIR__ . '/inc/indexnow.php';
require_once __DIR__ . '/inc/abilities-registration.php';
require_once SNT_PATH . 'inc/abilities-analytics.php';  // v6.1.0: read-only analytics Abilities
require_once __DIR__ . '/inc/migrate-orphan-options.php';  // v5.0.0: one-time orphan-option cleanup
require_once __DIR__ . '/inc/command-palette.php';
require_once __DIR__ . '/inc/pre-publish-gate.php';      // v4.11.0: editor pre-publish advisory gate (client-side, no AI)
require_once SNT_PATH . 'inc/cron-dashboard.php';
require_once SNT_PATH . 'inc/cron-history.php';
require_once SNT_PATH . 'inc/cron-dashboard-admin.php';
require_once SNT_PATH . 'inc/webhooks.php';
require_once SNT_PATH . 'inc/webhooks-admin.php';
require_once SNT_PATH . 'inc/uptime-heartbeat.php';
require_once SNT_PATH . 'inc/admin-heartbeat.php';
require_once SNT_PATH . 'inc/insights.php';
require_once SNT_PATH . 'inc/insights-admin.php';
require_once SNT_PATH . 'inc/health-checks.php';
require_once SNT_PATH . 'inc/health-external-links.php'; // D1 (v6.13.0): 7th check — external link-rot (off-host cited sources)
require_once SNT_PATH . 'inc/health-checks-admin.php';
require_once SNT_PATH . 'inc/audit-log.php';
require_once SNT_PATH . 'inc/audit-log-admin.php';
require_once SNT_PATH . 'inc/audit-log-export.php';  // v4.10.0: CSV/JSON export (download + ability impl)
require_once SNT_PATH . 'inc/privacy-exporters.php'; // v4.10.0: GDPR exporter/eraser + suggested privacy policy text
require_once SNT_PATH . 'inc/speculation-rules.php'; // v4.10.0: opt-in Speculation Rules tuning (prerender/moderate)

// Settings migration: seed legacy values once per environment.
// register_activation_hook fires only on WP-upgrader-driven activations;
// the admin_init handler covers SSH-based git-checkout deploys.
register_activation_hook( __FILE__, 'sn_settings_seed_legacy_values' );
add_action( 'admin_init', 'sn_settings_lazy_migration_check' );
