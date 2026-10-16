<?php
/**
 * Signal & Noise Tools — the HOST: an admin page painted inside an
 * OpenStation app window, unchanged.
 *
 * WHY THIS EXISTS. The classic Signal & Noise admin page is 8 top tabs over
 * ~35 leaves, 57 forms, one flash table and one handler table. Rewriting any
 * of that for the App Framework would be a redesign, and a redesign is not a
 * port. So nothing is rewritten: the SAME render callables paint the SAME
 * HTML, and this file is the four seams that let that HTML live in a window
 * instead of a document.
 *
 *   1. CAPTURE. A leaf reads `$_GET` and echoes. A dispatch is a REST request
 *      whose superglobals belong to the dispatch, not to the leaf. So the
 *      capture lends the leaf the query it would have had, runs it under
 *      `ob_start()`, and puts the superglobals back — in a `finally`, because
 *      a leaf that throws must not leave the request wearing someone else's
 *      `$_GET`.
 *   2. REWRITE. Two seams in that HTML would leave the window: a `<form>`
 *      submit and an `<a href>` click (the runtime preventDefaults `submit`
 *      but NOT `click`, so a rewritten link must LOSE its href — measured in
 *      assets/js/app-runtime.min.js). A third would never run: an inline
 *      `<script>` painted by innerHTML. One pass over WP_HTML_Tag_Processor
 *      touches exactly those and nothing else.
 *   3. REPLAY. A handler ends in `header()`/`wp_safe_redirect()` + `exit`,
 *      which a window cannot do, so none of them can be called normally.
 *      Everything BEFORE that exit is reproduced exactly — capability, nonce,
 *      page, the handler table, the flash code, the redirect target — and the
 *      three pipelines that do it live in inc/openstation-host-pipelines.php,
 *      required below. The two pure resolvers
 *      (`sn_admin_post_redirect_target()`, `sn_admin_flash_to_notice()`) are
 *      CALLED, never copied.
 *   4. ASSETS. Every leaf behaviour is a script that self-gates on a DOM
 *      marker, enqueued today on the classic hook suffixes. The window-args
 *      seam registers the same handles, with the same data, from the same
 *      builders; it lives in inc/openstation-host-assets.php.
 *
 * Shared by both hosts (S&N Dashboard, S&N Analytics); nothing here knows
 * which page it is painting. Spec: docs/proposals/2026-09-06-openstation-hosts.md.
 *
 * @package SignalNoiseTools
 * @since 13.104.0
 */

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

// 14.7.5: the URL helpers (admin, same-origin, download) live beside the kit,
// which needs them to decide door-or-tab without loading this whole host.
require_once __DIR__ . '/openstation-host-urls.php';

// The WRITE half: the three pipelines a submitted form can belong to, the
// FormData expansion they all start with, and the redirect/die interceptor two
// of them need. Required HERE rather than from the plugin's manifest so every
// entry point that has the paint also has the write — the app file, the suites
// and a standalone host all require only this file.
require_once __DIR__ . '/openstation-host-pipelines.php';
// The ASSET seam: which handles a host window carries, and their
// registration from the same builders their own pages use.
require_once __DIR__ . '/openstation-host-assets.php';
// v13.106.0: the kit vocabulary the native windows paint their bodies from.
require_once __DIR__ . '/openstation-kit.php';
require_once __DIR__ . '/openstation-kit-display.php';
require_once __DIR__ . '/openstation-kit-data.php';
require_once __DIR__ . '/openstation-kit-forms.php';
require_once __DIR__ . '/openstation-kit-triggers.php';

/** Ceiling on the `sn_*` query params a window carries in its state (they ride every dispatch). */
if ( ! defined( 'SNT_OS_HOST_PARAM_CAP' ) ) {
	define( 'SNT_OS_HOST_PARAM_CAP', 20 );
}

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order it always ran so every hook keeps its place.
require_once __DIR__ . '/openstation-host-rewrite-parts.php';
require_once __DIR__ . '/openstation-host-tags-parts.php';
require_once __DIR__ . '/openstation-host-params-parts.php';
