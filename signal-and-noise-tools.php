<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. Operational tooling: REST surface, Plausible integration, security headers, Cloudflare purge, admin UI, RSS Plausible tracker. Self-updater migrates in Phase 2.
 * Version:     1.10.0
 * Requires at least: 6.4
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

define( 'SNT_VERSION', '1.10.0' );
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
require_once SNT_PATH . 'inc/plausible-api.php';
require_once SNT_PATH . 'inc/plausible-admin.php';
require_once SNT_PATH . 'inc/plausible-widget.php';
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
require_once SNT_PATH . 'inc/rest-api.php';

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
require_once __DIR__ . '/inc/login-hide.php';
require_once __DIR__ . '/inc/seo-schema.php';
require_once __DIR__ . '/inc/post-settings.php';

// Settings migration: seed legacy values once per environment.
// register_activation_hook fires only on WP-upgrader-driven activations;
// the admin_init handler covers SSH-based git-checkout deploys.
register_activation_hook( __FILE__, 'sn_settings_seed_legacy_values' );
add_action( 'admin_init', 'sn_settings_lazy_migration_check' );
