<?php
/**
 * Signal & Noise Tools — the dock entry, its badge, and the desktop icons.
 *
 * Suppression of the shell's automatic dock import, the update-count badge,
 * the two desktop icons, and snt_desktop_admin_url() — the slug resolver every
 * SN link goes through.
 *
 * The dock ITEM left in #1074: apps/sn-dashboard/sn-dashboard.os.php registers
 * the tile under the same id now. See the note above the placement filter.
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
 * THE DOCK ITEM IS GONE — the app registers it (#1074).
 *
 * From v1.15.0 to v13.103.0 this file put "S&N Dashboard" on the dock through
 * `desktop_mode_dock_items`: a URL tile pointing at the classic admin page,
 * with an 8-tab submenu derived from `sn_admin_top_tabs()` and the update
 * badge from `snt_desktop_dock_badge()`. All three now belong to
 * apps/sn-dashboard/sn-dashboard.os.php, which declares the SAME id, the same
 * shield and the same title, so the tile is unchanged to look at — it just
 * opens the window instead of an admin URL. Two registrations under one id
 * would be one id naming two things, which is the trap v13.100.0 fixed.
 *
 * What deliberately STAYS here: the `dock_placement` filter (the shell would
 * otherwise auto-import our `add_menu_page()` entry as a SECOND tile),
 * `snt_desktop_dock_badge()` — the app reads it, unchanged — the two desktop
 * icons, and `snt_desktop_admin_url()`, the resolver every SN link goes
 * through. The classic page keeps every door it has today; a removal would
 * not be a port.
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
 *   2. Our explicit "Signal & Noise" with shield icon — registered in the
 *      desktop_mode_dock_items filter until #1074, and by the App Framework
 *      app since (richer: 8-tab menu + update-available badge).
 *
 * Returning 'hidden' for the SN menu slug suppresses the auto-import.
 * The app's own tile remains. Single dock item, shield icon, full
 * menu.
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
/**
 * v13.105.0 (#1075): `sn-analytics` joins it, for the same reason and with the
 * same measurement. The Analytics screen is its OWN top-level menu
 * (add_menu_page, v12.10.0), so the shell auto-imports it — as a URL tile whose
 * id is `sanitize_key( $item[5] )`, i.e. `toplevel_page_sn-analytics`
 * (OpenStation includes/core/payload.php:404). That is a DIFFERENT id from the
 * app's, so nothing collides and nothing errors: the desktop simply grows a
 * second S&N Analytics tile, one opening the app window and one opening the
 * classic page in an admin window. One surface, one tile. The placement filter
 * keys on the MENU SLUG (`$identity_slug`, payload.php:416), which is why the
 * app id being identical to the slug is irrelevant here.
 *
 * The classic page keeps every door it has: `snt_desktop_admin_url()` still
 * resolves `sn-analytics` to `snt_analytics_page_url()`, and hiding a dock tile
 * hides nothing else.
 */
snt_os_compat_add_filter( 'desktop_mode_dock_placement', 'openstation_dock_placement', function( $placement, $menu_slug ) {
	if ( in_array( $menu_slug, array( 'sn-theme-options', 'sn-analytics' ), true ) ) {
		return 'hidden';
	}
	return $placement;
}, 10, 2 );

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
	// v13.105.1: the icon opens the HOST window (`window` is the shell's own
	// icon target -- the framework registers an app's desktop_icon with it),
	// not the classic page in a chromeless frame: one surface per id, and the
	// icon keeps its id so its position and the attention badge survive.
	snt_os_register_icon( 'sn-icon-dashboard', array(
		'title'  => 'S&N Dashboard',
		'icon'   => 'dashicons-shield-alt',
		'window' => 'sn-dashboard',
	) );

	snt_os_register_icon( 'sn-icon-identity', array(
		'title' => 'SN Identity',
		'icon'  => 'dashicons-id',
		'url'   => snt_desktop_admin_url( 'sn-identity' ),
	) );
} );
