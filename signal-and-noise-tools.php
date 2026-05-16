<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. Operational tooling: REST surface, Plausible integration, security headers, Cloudflare purge, admin UI. Self-updater migrates in Phase 2.
 * Version:     1.0.1
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

define( 'SNT_VERSION', '1.0.1' );
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
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/plausible-api.php';
require_once SNT_PATH . 'inc/plausible-admin.php';
require_once SNT_PATH . 'inc/plausible-widget.php';
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
require_once SNT_PATH . 'inc/rest-api.php';
