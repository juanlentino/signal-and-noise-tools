<?php
/**
 * Signal & Noise — admin POST action handlers.
 *
 * One small function per form action, each fn( array $post ): string that
 * performs the action's side effects (option writes, filter dispatch, module
 * calls) and returns a ?sn_flash=… code. Dispatched by sn_handle_admin_post()
 * (inc/admin-post-handler.php) via the sn_admin_post_handlers() map. Extracted
 * verbatim from the 270-line if/elseif in inc/admin-page.php in v4.5.4.
 *
 * Handlers receive the RAW $_POST and unslash per-field exactly as the original
 * arms did. save_identity still passes the raw array straight to
 * sn_settings_save(), which now wp_unslash()es the whole payload itself
 * (v9.36.1 — the old pass-through-without-unslash behavior added one
 * backslash layer to every apostrophe per save).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The handlers live one directory down, one file per domain. This file stays:
// two consumers require it BY PATH (the plugin bootstrap and the test suite),
// and the dispatch map binds function NAMES, not paths, so nothing downstream
// needs to know where a handler moved to.
//
// __DIR__ rather than SNT_PATH: the test suite requires this file without the
// plugin bootstrap, so that constant is not guaranteed to be defined.
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
























































// v9.0.0 (D1): sn_handle_analytics_import() (the Plausible-CSV upload handler) was
// removed with the rest of the importer. The analytics_export handler above stays —
// export is a live first-party feature, unrelated to the retired Plausible path.
