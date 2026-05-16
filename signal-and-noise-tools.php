<?php
/**
 * Plugin Name: Signal & Noise Tools
 * Plugin URI:  https://github.com/juanlentino/signal-and-noise-tools
 * Description: Companion plugin for the Signal & Noise theme. Operational tooling: REST surface, Plausible integration, security headers, Cloudflare purge, admin UI. Self-updater migrates in Phase 2.
 * Version:     1.0.0
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

define( 'SNT_VERSION', '1.0.0' );
define( 'SNT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SNT_URL', plugin_dir_url( __FILE__ ) );

// Module includes — extended in Task 8 once modules land.
// Each include path is relative to SNT_PATH.
require_once SNT_PATH . 'inc/seo.php';
require_once SNT_PATH . 'inc/security-headers.php';
require_once SNT_PATH . 'inc/cloudflare-purge.php';
require_once SNT_PATH . 'inc/plausible-api.php';
require_once SNT_PATH . 'inc/plausible-admin.php';
require_once SNT_PATH . 'inc/plausible-widget.php';
require_once SNT_PATH . 'inc/admin-bar.php';
require_once SNT_PATH . 'inc/admin-page.php';
require_once SNT_PATH . 'inc/rest-api.php';
