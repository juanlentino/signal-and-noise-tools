<?php
/**
 * Signal & Noise — admin menu registration + asset enqueue.
 *
 * Registers the top-level "Signal & Noise" menu and one submenu entry per
 * sn_admin_top_tabs() tab — the count is derived, never hardcoded, because
 * desktop-mode renders these entries as a horizontal top nav that must
 * mirror the in-page tab strip 1:1 (the v3.8.0 duplicate-nav lesson).
 * Caches the resulting hook suffixes (sn_admin_page_hooks), and
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
	// Widget tokenization: the shared D4 --sn-an-* token layer (formerly declared
	// inline in analytics-admin.css's own :root block) now lives in its own
	// stylesheet so analytics-widget.css can read the same palette on the
	// Dashboard home screen. Registered as a dependency below so WP's dep graph —
	// not enqueue call order — guarantees it loads first.
	wp_enqueue_style(
		'snt-analytics-tokens',
		SNT_URL . 'assets/analytics/analytics-tokens.css',
		array(),
		SNT_VERSION
	);
	// v6.5.1: the dense Analytics dashboard layout (formerly an inline <style>
	// echoed mid-body by snt_analytics_styles(), which could render unstyled on the
	// live page — a body-injected <style> is subject to edge/cache HTML rewriting and
	// a strict CSP, and the old once-guard was fragile). Loaded as a proper external,
	// cache-busted stylesheet in <head>. Depends on sn-admin so it cascades after it,
	// and on snt-analytics-tokens for the shared token layer. Scoped to
	// .sn-an-*/.sn-kpi-*/.sn-geo-* classes that only appear on the analytics
	// surfaces, so loading it on every SN admin page is harmless.
	wp_enqueue_style(
		'sn-analytics-admin',
		SNT_URL . 'assets/analytics/analytics-admin.css',
		array( 'sn-admin', 'snt-analytics-tokens' ),
		SNT_VERSION
	);
	// v9.34.0 (maturity I5): brush-to-select on the trend chart (the chart becomes
	// the range control). Self-gating: no-op unless a [data-brush-from] chart exists.
	wp_enqueue_script(
		'sn-analytics-brush',
		SNT_URL . 'assets/analytics/analytics-brush.js',
		array(),
		SNT_VERSION,
		true
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

	// v10.33.0: Resume structured-editor repeatable rows. Self-gating (no-op
	// unless a [data-rsm-add] button exists), so loading it on every SN admin
	// page is harmless — same precedent as sn-analytics-brush above.
	wp_enqueue_script(
		'sn-resume-admin',
		SNT_URL . 'assets/resume-admin.js',
		array(),
		SNT_VERSION,
		true
	);

	// Health/Tools Suggest+Apply JS (assets/health-suggest-actions.js) — enqueued
	// UNCONDITIONALLY (no AI gate) on exactly the two leaves that render
	// data-snt-suggest buttons: Monitoring → Health (the AI alt/drift/orphan column
	// self-gates on snt_ai_is_available() at RENDER time in health-checks-admin.php,
	// but the Opportunities pattern-adoption sub-section renders its Suggest/Dismiss
	// buttons with NO AI gate) and Tools → Block Migrations (pure structural
	// detection, no AI). The JS is inert when no buttons are present, so loading it
	// on those leaves regardless of AI is correct and safe.
	//
	// v6.47.2 (the dead-Suggest-button fix): resolve the active top-tab + sub-tab
	// the SAME way the page dispatcher does (sn_theme_options_page() via
	// sn_admin_page_tab_for_slug() + sn_admin_resolve_active_sub()) instead of the
	// old hard-coded `'health' === $_GET['tab']` / `'tools' === $_GET['tab']`
	// guards. The v6.x IA moved Health UNDER Monitoring (tab=monitoring &
	// sub=health) and Block Migrations under Tools (tab=tools & sub=block-migrations);
	// the old Health guard checked the wrong query var (the real Health URL has
	// tab=monitoring, with `health` in $_GET['sub']), so the script was NEVER
	// enqueued and EVERY Suggest button was dead with no console error — on any
	// site, AI configured or not. Mirroring the dispatcher keeps this guard correct
	// as the IA evolves. (Tools kept working pre-fix only because `tab=tools` still
	// happened to match.)
	if ( function_exists( 'sn_admin_resolve_active_sub' ) ) {
		if ( isset( $_GET['tab'] ) ) {
			$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		} elseif ( function_exists( 'sn_admin_page_tab_for_slug' ) ) {
			$current_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
			$active_tab   = sn_admin_page_tab_for_slug( $current_slug );
		} else {
			$active_tab = '';
		}
		$active_sub = sn_admin_resolve_active_sub( $active_tab );

		$needs_suggest_js = ( 'monitoring' === $active_tab && 'health' === $active_sub )
			|| ( 'tools' === $active_tab && 'block-migrations' === $active_sub );

		if ( $needs_suggest_js ) {
			wp_enqueue_script(
				'snt-health-suggest-actions',
				plugins_url( 'assets/health-suggest-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
				// v4.1.6 (U-15): snt-status provides window.sntSetStatus (replaces local setStatus copy).
				// v7.7.2: snt-ability-run provides window.sntAbilityRun (annotation-derived verbs).
				array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ),
				SNT_VERSION,
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'snt-health-suggest-actions', 'signal-and-noise-tools' );
			}
		}

		// v8.2.0: Better Stack status panel on Connections → Webhooks (the rail
		// mount). Same dispatcher-mirroring guard as above — never a raw
		// $_GET['tab'] check. The dashboard-widget surface enqueues these same
		// handles from inc/uptime-status-widget.php (index.php gate).
		if ( 'connections' === $active_tab && 'webhooks' === $active_sub
			&& function_exists( 'sn_uptime_status_enqueue_assets' )
			&& function_exists( 'sn_uptime_status_configured' ) && sn_uptime_status_configured() ) {
			sn_uptime_status_enqueue_assets();
		}
	}
} );
