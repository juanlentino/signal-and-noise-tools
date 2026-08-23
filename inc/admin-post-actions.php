<?php
/**
 * Signal & Noise — admin POST action handlers (loader).
 *
 * The handlers live in inc/admin-post-actions/, one file per domain. This file
 * held all 63 of them — 1,682 lines — until the v12.21.2 split
 * (docs/REFACTOR-admin-post-actions.md).
 *
 * The contract did not change. Every handler is still fn( array $post ): string
 * that performs the action's side effects (option writes, filter dispatch,
 * module calls) and returns a ?sn_flash=… code, and sn_handle_admin_post()
 * (inc/admin-post-handler.php) still routes to it through the
 * sn_admin_post_handlers() map. That map binds an action name to a function
 * NAME, never to a file — which is what made the split safe, and why no
 * consumer had to learn where a handler moved to.
 *
 * Handlers receive the RAW $_POST and unslash per-field exactly as the original
 * arms did in the 270-line if/elseif in inc/admin-page.php, extracted verbatim
 * in v4.5.4. save_identity still passes the raw array straight to
 * sn_settings_save(), which now wp_unslash()es the whole payload itself
 * (v9.36.1 — the old pass-through-without-unslash behavior added one backslash
 * layer to every apostrophe per save).
 *
 * This file is required BY PATH from signal-and-noise-tools.php and from the
 * test suite, so it stays even though it now declares nothing itself.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// __DIR__ rather than SNT_PATH: the test suite requires this file without the
// plugin bootstrap, so that constant is not guaranteed to be defined. Order
// matches the order the functions had in the pre-split file; the contract makes
// it irrelevant, but preserving it removes a variable.
require_once __DIR__ . '/admin-post-actions/system.php';
require_once __DIR__ . '/admin-post-actions/cloudflare.php';
require_once __DIR__ . '/admin-post-actions/health-insights.php';
require_once __DIR__ . '/admin-post-actions/webhooks.php';
require_once __DIR__ . '/admin-post-actions/reports.php';
require_once __DIR__ . '/admin-post-actions/content.php';
require_once __DIR__ . '/admin-post-actions/scans.php';
require_once __DIR__ . '/admin-post-actions/monitoring.php';
require_once __DIR__ . '/admin-post-actions/theme-ai.php';
require_once __DIR__ . '/admin-post-actions/music.php';
require_once __DIR__ . '/admin-post-actions/tags.php';
require_once __DIR__ . '/admin-post-actions/indexnow.php';
require_once __DIR__ . '/admin-post-actions/analytics.php';
require_once __DIR__ . '/admin-post-actions/gsc.php';
require_once __DIR__ . '/admin-post-actions/mcp.php';
