<?php
/**
 * Signal & Noise Tools — the dock entry, its badge, and the desktop icons.
 *
 * The single "Signal & Noise" dock item (submenu derived from
 * sn_admin_top_tabs(), never a hardcoded count), suppression of the shell's
 * automatic dock import, the update-count badge, the two desktop icons, and
 * snt_desktop_admin_url() — the slug resolver every SN link goes through.
 *
 * snt_desktop_admin_url() is called from the assets localize too; both files
 * load together from the loader, so the cross-module call is fine.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dock item — single entry "Signal & Noise" with submenu of all 8 tabs.
 *
 * Filter shape per desktop-mode docs/getting-started.md:
 *   slug, title, icon (dashicons-*), url, badge?, submenu? (array of items
 *   with the same shape, recursively)
 */
/**
 * Suppress desktop-mode's automatic dock import of our menu page.
 *
 * Per WordPress/desktop-mode core/payload.php, every entry registered via
 * add_menu_page() / add_submenu_page() is auto-imported as a dock item
 * by default. Our admin-page.php registers "Signal & Noise" as a top-
 * level menu, so without this filter we end up with TWO dock entries:
 *
 *   1. Auto-imported "Signal & Noise" from add_menu_page (generic icon
 *      because desktop-mode falls back when the menu doesn't specify a
 *      dashicon explicitly — looks like a megaphone glyph on small
 *      screens, which is what surfaced the bug).
 *   2. Our explicit "Signal & Noise" with shield icon registered in the
 *      desktop_mode_dock_items filter below (richer: 8-tab submenu +
 *      update-available badge).
 *
 * Returning 'hidden' for the SN menu slug suppresses the auto-import.
 * Our explicit entry remains. Single dock item, shield icon, full
 * submenu.
 *
 * Verified against WordPress/desktop-mode includes/core/payload.php:
 *   apply_filters( 'desktop_mode_dock_placement', 'dock', $menu_slug );
 * Post-#475 OpenStation renames this to `openstation_dock_placement`
 * (includes/core/payload.php:1137, same 2-arg shape) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (pure function of $menu_slug), no
 * double-fire guard needed.
 *
 * Added in v2.0.1 (post-v1.15.0 desktop-mode bug fix).
 */
snt_os_compat_add_filter( 'desktop_mode_dock_placement', 'openstation_dock_placement', function( $placement, $menu_slug ) {
	if ( 'sn-theme-options' === $menu_slug ) {
		return 'hidden';
	}
	return $placement;
}, 10, 2 );

// Post-#475 OpenStation renames this to `openstation_dock_items`
// (includes/core/payload.php:212) — dual-registered, idempotent (rebuilds
// $items from sn_admin_top_tabs() every call), no double-fire guard needed.
snt_os_compat_add_filter( 'desktop_mode_dock_items', 'openstation_dock_items', function( $items ) {
	if ( ! is_array( $items ) ) {
		$items = array();
	}

	/**
	 * v2.1.0 dock fix:
	 *   - Key is 'id' not 'slug' (the desktop-mode docs/hooks-reference.md
	 *     says 'slug' but the actual code at includes/core/payload.php:163
	 *     uses 'id'. Verified against test fixture at tests/phpunit/tests/
	 *     desktopModeBuildDockItems.php:394 which uses 'id' => 'replaced').
	 *     Wrong key meant item.id was undefined in JS, crashing dock.ts:1711
	 *     with TypeError on every click of the SN tile — silent breakage
	 *     since v1.15.0, only surfaced post-Phase-13 when our auto-import
	 *     suppression removed the parallel working entry.
	 *   - Submenu entries only honor 'title' + 'url' per src/dock.ts:89
	 *     SubmenuItem type — 'icon' and 'slug' on submenu items are
	 *     silently dropped. Removed the noise.
	 *   - Icon is dashicons-megaphone (matches the icon passed to
	 *     add_menu_page() in admin-page.php:121, which is what was
	 *     rendering on the auto-imported entry before suppression).
	 *
	 * Click behavior (verified via src/dock.ts:911-913 + 1703-1765):
	 *   - Single click on parent tile → window opens to item.url
	 *   - Submenu rides into the opened window as an in-window tab strip
	 *     (the "submenu chevron" on the dock tile is documented future work)
	 */
	// v3.8.4: derive submenu from sn_admin_top_tabs() instead of hardcoding
	// the legacy 8-entry list. Was a single-source-of-truth violation: when
	// v3.8.1 reduced the wp-admin sidebar submenu to 6 entries to match the
	// new in-page tab IA, THIS filter was missed — so desktop-mode portal
	// continued rendering the OLD 8 entries as a horizontal top-nav row.
	// That re-created the "duplicate nav appearance" that v3.8.1 was meant
	// to fix (see memory feedback_desktop_mode_horizontal_submenu_warning).
	$dock_submenu = array();
	foreach ( sn_admin_top_tabs() as $top_tab ) {
		// Direct-to-canonical URLs (no redirect round-trip): page=sn-theme-options&tab=<top>.
		// Dashboard tab omits the &tab= param since it's the default.
		$url = 'dashboard' === $top_tab['tab']
			? admin_url( 'admin.php?page=sn-theme-options' )
			: admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $top_tab['tab'] ) );
		$dock_submenu[] = array(
			'title' => $top_tab['label'],
			'url'   => $url,
		);
	}

	$items[] = array(
		'id'      => 'signal-noise',
		'title'   => 'Signal & Noise',
		'icon'    => 'dashicons-megaphone',
		'url'     => admin_url( 'admin.php?page=sn-theme-options' ),
		'badge'   => snt_desktop_dock_badge(),
		'submenu' => $dock_submenu,
	);

	return $items;
} );

/**
 * Badge count for the dock — total "update available" count for theme +
 * plugin. 0 = no badge (desktop-mode convention).
 */
function snt_desktop_dock_badge() {
	$badge = 0;
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		if ( 'available' === ( snt_deploy_status_for( 'theme' )['state']  ?? '' ) ) { $badge++; }
		if ( 'available' === ( snt_deploy_status_for( 'plugin' )['state'] ?? '' ) ) { $badge++; }
	}
	return $badge;
}

/**
 * Desktop icons — Dashboard + Identity (the two most-frequent surfaces).
 */
/**
 * Resolve any SN admin page slug — current OR retired — to a URL that actually
 * loads.
 *
 * WHY THIS EXISTS (v9.55.0, owner-found by clicking)
 *
 * Opening most SN windows in Desktop Mode showed WP core's "Sorry, you are not
 * allowed to access this page." EIGHT of our NINE admin links were dead.
 *
 * v3.8.1 cut the wp-admin submenu from the 12 legacy slugs to 6 top tabs
 * (inc/admin-menu.php registers add_submenu_page over sn_admin_top_tabs()).
 * The icons and the Cmd+K nav map kept hardcoding the RETIRED slugs —
 * sn-identity, sn-login, sn-cron, sn-rss, sn-insights, sn-analytics,
 * sn-cloudflare, sn-reading-time. admin.php looks each up in
 * $_registered_pages, doesn't find it, and wp_die()s. The message is WP CORE's
 * — not desktop-mode's, not ours — which is exactly why no surface here ever
 * noticed, and why CI stayed green for releases on end.
 *
 * The legacy redirect could not rescue them, though it looks like it should:
 * sn_admin_maybe_redirect_legacy() is called from INSIDE sn_theme_options_page(),
 * the render callback of a page that no longer exists. A legacy URL only ever
 * 301s if its slug is still registered. These are not. The rescue lived in the
 * room that burned down.
 *
 * So route through the same canonical resolver the redirect itself uses. It
 * always lands on the registered parent (page=sn-theme-options&tab=…), so a
 * future IA change cannot re-rot these links: they follow the tab data.
 *
 * @since 9.55.0
 * @param string $slug Any SN admin page slug, current or retired.
 * @return string An admin URL whose `page=` is always a registered page.
 */
function snt_desktop_admin_url( $slug, $sub = '' ) {
	// SPECIAL CASE, and the one the resolver alone gets wrong: the analytics
	// screen is its OWN top-level menu (v12.10.0, add_menu_page; it was a
	// Dashboard submenu from v5.4.0). It is not an SN tab, and
	// sn_admin_page_tab_for_slug() has no entry for it, so it would fall through
	// to the 'dashboard' default and land the user on the SN Dashboard: a link
	// that loads perfectly and goes to the wrong place. The URL comes from the
	// accessor rather than a literal so this cannot drift the next time the page
	// moves — which is exactly how the pre-v12.10.0 literal here went stale.
	if ( 'sn-analytics' === $slug ) {
		return snt_analytics_page_url();
	}

	// Guarded because this file is loaded on every admin request and the tab
	// data lives in a sibling module; a missing resolver must degrade to the
	// one slug that is always registered, never to a fatal.
	if ( ! function_exists( 'sn_admin_page_tab_for_slug' ) || ! function_exists( 'sn_admin_canonical_destination' ) ) {
		return admin_url( 'admin.php?page=sn-theme-options' );
	}

	$tab  = sn_admin_page_tab_for_slug( $slug );
	// null = the tab is already canonical; otherwise it maps a legacy tab to
	// its post-v3.8 home (and may carry a sub-leaf or an anchor).
	$dest = sn_admin_canonical_destination( $tab );

	$url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $dest ? $dest['tab'] : $tab ) );
	if ( $dest && ! empty( $dest['sub'] ) ) {
		$url .= '&sub=' . rawurlencode( $dest['sub'] );
	}
	if ( $dest && ! empty( $dest['anchor'] ) ) {
		$url .= '#sn-sec-' . rawurlencode( $dest['anchor'] );
	}
	// v10.46.0: an explicit leaf, for callers that want a sub-tab the slug
	// resolver cannot express. Passing a query string as $slug (the previous
	// Machine Readers bug) matches no slug, so the resolver fell through to
	// 'dashboard' — a link that loads perfectly and goes to the wrong place,
	// the exact failure the sn-analytics special case above was written for.
	if ( '' !== $sub && ! ( $dest && ! empty( $dest['sub'] ) ) ) {
		$url .= '&sub=' . rawurlencode( $sub );
	}
	return $url;
}

add_action( 'init', function() {
	if ( ! snt_os_register_icon_available() ) {
		return;
	}

	// v13.99.2: "S&N Dashboard", to match the S&N Analytics icon beside it. The
	// id and URL are unchanged, so the owner's placement keeps its spot.
	snt_os_register_icon( 'sn-icon-dashboard', array(
		'title' => 'S&N Dashboard',
		'icon'  => 'dashicons-shield-alt',
		'url'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );

	snt_os_register_icon( 'sn-icon-identity', array(
		'title' => 'SN Identity',
		'icon'  => 'dashicons-id',
		'url'   => snt_desktop_admin_url( 'sn-identity' ),
	) );
} );
