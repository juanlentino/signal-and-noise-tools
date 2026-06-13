<?php
/**
 * Signal & Noise — admin menu registration + asset enqueue.
 *
 * Registers the top-level "Signal & Noise" menu and its 6 submenu entries
 * (admin_menu), caches the resulting hook suffixes (sn_admin_page_hooks), and
 * enqueues admin.css/admin.js + the per-tab Suggest+Apply scripts
 * (admin_enqueue_scripts). Extracted from inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Signal & Noise — top-level menu (v1.8.1+).
 *
 * Lives at admin.php?page=sn-theme-options (was previously under
 * Appearance via add_theme_page; URL slug unchanged so all existing
 * ?tab=… deep links remain valid). The hook suffix returned by
 * add_menu_page() is cached so the stylesheet enqueue can guard on
 * it without re-deriving it from the slug.
 *
 * The auto-generated first submenu would otherwise duplicate the
 * parent label ("Signal & Noise / Signal & Noise"); add_submenu_page
 * with the same slug overrides the auto entry's label to "Dashboard".
 */

/**
 * Cache of all registered hook suffixes for the SN admin pages.
 * Used by the enqueue guard to load the stylesheet on any of our
 * pages without re-deriving hook names from slugs.
 *
 * add_menu_page() always returns a string; add_submenu_page() returns
 * false when the user lacks the required capability (gotcha #15), so
 * we filter the array before comparing.
 */
function sn_admin_page_hooks( $set = null ) {
	static $hooks = array();
	if ( is_array( $set ) ) {
		$hooks = array_values( array_filter( $set, 'is_string' ) );
	}
	return $hooks;
}

add_action( 'admin_menu', function() {
	$hooks = array();

	$hooks[] = add_menu_page(
		'Signal & Noise',
		'Signal & Noise',
		'manage_options',
		'sn-theme-options',
		'sn_theme_options_page',
		'dashicons-megaphone',
		81
	);

	// v3.8.1+: register 6 submenu entries (matching the new top-tab IA) instead
	// of the 12 legacy entries from sn_admin_pages(). Legacy entries' URLs still
	// resolve via the redirect map in sn_admin_maybe_redirect_legacy(). The 12-
	// entry sidebar was creating a duplicate-nav appearance in desktop-mode
	// (where the WP submenu renders as horizontal top nav instead of left sidebar).
	foreach ( sn_admin_top_tabs() as $page ) {
		$hooks[] = add_submenu_page(
			'sn-theme-options',
			$page['title'],
			$page['label'],
			'manage_options',
			$page['slug'],
			'sn_theme_options_page'
		);
	}

	sn_admin_page_hooks( $hooks );
} );

/**
 * Enqueue the SN admin stylesheet on any of our 8 pages.
 *
 * Guards via in_array() against the collected hook list so a slug
 * rename in sn_admin_pages() won't silently break the guard. Cache-
 * busted by SNT_VERSION.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! in_array( $hook, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_style(
		'sn-admin',
		SNT_URL . 'assets/admin.css',
		array(),
		SNT_VERSION
	);
	// v6.5.1: the dense Analytics dashboard layout (formerly an inline <style>
	// echoed mid-body by snt_analytics_styles(), which could render unstyled on the
	// live page — a body-injected <style> is subject to edge/cache HTML rewriting and
	// a strict CSP, and the old once-guard was fragile). Loaded as a proper external,
	// cache-busted stylesheet in <head>. Depends on sn-admin so it cascades after it.
	// Scoped to .sn-an-*/.sn-kpi-*/.sn-geo-* classes that only appear on the analytics
	// surfaces, so loading it on every SN admin page is harmless.
	wp_enqueue_style(
		'sn-analytics-admin',
		SNT_URL . 'assets/analytics/analytics-admin.css',
		array( 'sn-admin' ),
		SNT_VERSION
	);
	wp_enqueue_script(
		'sn-admin',
		SNT_URL . 'assets/admin.js',
		array(),
		SNT_VERSION,
		true // load in footer, after DOM is parsed
	);

	// v4.1.1 (U-01): shared confirm-dialog utility. Replaces 7 legacy
	// `window.confirm()` / `onclick="return confirm(...)"` call sites with
	// an in-page modal that works inside the desktop-mode portal iframe
	// (native confirm() is blocked there by the chrome-extension boundary).
	wp_enqueue_script(
		'snt-confirm',
		SNT_URL . 'assets/snt-confirm.js',
		array( 'wp-i18n' ),
		SNT_VERSION,
		true
	);

	// Health Suggest+Apply JS — enqueued on the Health tab UNCONDITIONALLY as
	// of v4.5.2. The AI-fix column (missing_alt / drift / orphan Suggest
	// buttons) self-gates on snt_ai_is_available() at RENDER time in
	// inc/health-checks-admin.php — but the Opportunities sub-section
	// (pattern-adoption) renders its Suggest/Dismiss buttons with NO AI gate
	// (pure structural detection), and they use this same shared JS. Gating the
	// ENQUEUE on snt_ai_is_available() therefore left those buttons DEAD whenever
	// no AI provider was configured — the same dead-button class v4.5.1 fixed for
	// the Tools tab below. The JS is inert when no buttons are present, so loading
	// it unconditionally is safe.
	// Tab param is canonical post-redirect: ?page=sn-monitoring&tab=health.
	if ( isset( $_GET['tab'] ) && 'health' === $_GET['tab'] ) {
		wp_enqueue_script(
			'snt-health-suggest-actions',
			plugins_url( 'assets/health-suggest-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
			// v4.1.6 (U-15): snt-status provides window.sntSetStatus (replaces local setStatus copy).
			array( 'wp-api-fetch', 'wp-i18n', 'snt-status' ),
			SNT_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'snt-health-suggest-actions', 'signal-noise-tools' );
		}
	}

	// v4.5.1: enqueue health-suggest-actions.js on the Tools tab too. The
	// Block Migrations sub-tab (introduced in v4.5.0) reuses the same
	// shared Suggest+Apply JS. No AI gate — block-migrations is pure
	// structural detection (no AI calls anywhere in the impl).
	// Tab param is canonical post-redirect: ?page=sn-monitoring&tab=tools.
	if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) {
		wp_enqueue_script(
			'snt-health-suggest-actions',
			plugins_url( 'assets/health-suggest-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
			// v4.1.6 (U-15): snt-status provides window.sntSetStatus (replaces local setStatus copy).
			array( 'wp-api-fetch', 'wp-i18n', 'snt-status' ),
			SNT_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'snt-health-suggest-actions', 'signal-noise-tools' );
		}
	}
} );
