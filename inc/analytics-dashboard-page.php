<?php
/**
 * Signal & Noise — the Analytics screen, on its own top-level admin menu.
 *
 * WHERE THIS LIVES, AND WHY IT MOVED (v12.10.0).
 *
 * v5.4.0 put this under the WordPress Dashboard menu (`add_dashboard_page()`,
 * sidebar Home · Updates · Analytics), reverting v5.3.0's placement on the
 * plugin's own Dashboard tab. That move carried TWO decisions welded together:
 * the read/settings SPLIT (this screen has no form; credentials stay in
 * Measurement → Analytics) and the RELOCATION onto core's Dashboard menu.
 *
 * The split was right and is kept. The relocation was not, for three reasons:
 *
 *   1. It is a REPORT, not a dashboard. Eight edge dimensions, an hour-of-day
 *      heatmap, scroll/time distributions, referrer categories, period deltas
 *      and a bot breakdown are an exploratory analysis surface. Few's rule —
 *      a dashboard is the most important information on a single screen,
 *      monitored at a glance — excludes reports by construction. A report is a
 *      destination you navigate TO; it does not belong wedged into a glance
 *      surface, and the plugin's own Dashboard tab (operational mission
 *      control) is a different artifact that stays untouched.
 *   2. Metrics deserve to be at hand. WooCommerce ships Analytics as its own
 *      top-level menu (`woocommerce-analytics`), a sibling of the settings
 *      menu rather than a child of it; Jetpack Stats does the same. One click
 *      from anywhere, never buried inside configuration.
 *   3. `index.php` is not ours to rely on. OpenStation v1.1.2's Station Home
 *      claims that path for its own native window — its URL matcher tests the
 *      pathname alone, so `index.php?page=sn-analytics` was swallowed and this
 *      screen became unreachable inside that shell (reported upstream as
 *      WordPress/openstation#475's sibling issue #650). A top-level menu is
 *      `admin.php?page=…`, which no Dashboard remap can claim.
 *
 * `add_menu_page()`'s capability gates MENU VISIBILITY only; the callback URL
 * stays directly reachable, so the render callback re-checks
 * current_user_can() itself.
 *
 * THE URL AND THE HOOK ARE ACCESSORS, NOT LITERALS. Twelve call sites built
 * `snt_analytics_page_url()` by hand and one pinned the
 * `dashboard_page_` hook suffix; moving the page would have broken each of
 * them silently and separately. snt_analytics_page_url() and
 * snt_analytics_page_hook() are the single source of truth, and
 * tests/analytics-dashboard-page.php fails the build if a raw legacy URL
 * reappears anywhere in inc/.
 *
 * @package SignalNoiseTools
 * @since 5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page slug. One literal, one place. */
const SNT_ANALYTICS_PAGE_SLUG = 'sn-analytics';

/**
 * The canonical Analytics URL.
 *
 * Every link to this screen goes through here. When the page moved off
 * `index.php` in v12.10.0 there were twelve hand-built copies of the old URL
 * across inc/, each of which would have kept pointing at a page that no longer
 * existed — the drift this accessor exists to make impossible.
 *
 * @param array $args Extra query args (e.g. array( 'sn_view' => 'posts' )).
 * @return string
 */
function snt_analytics_page_url( $args = array() ) {
	$url = admin_url( 'admin.php?page=' . SNT_ANALYTICS_PAGE_SLUG );
	return ( is_array( $args ) && array() !== $args ) ? add_query_arg( $args, $url ) : $url;
}

/**
 * The admin hook suffix for this page.
 *
 * A top-level menu produces `toplevel_page_<slug>`; the Dashboard submenu
 * produced `dashboard_page_<slug>`. inc/uptime-status-widget.php gates its
 * asset enqueue on this value, and hardcoding it is how that widget would have
 * gone quietly assetless after the move.
 *
 * @return string
 */
function snt_analytics_page_hook() {
	return 'toplevel_page_' . SNT_ANALYTICS_PAGE_SLUG;
}

/**
 * Register the top-level Analytics menu and record its hook suffix.
 *
 * Hooked at admin_menu priority 11 — AFTER the main SN menu (default priority
 * 10) has populated sn_admin_page_hooks() — so this APPENDS its hook rather than
 * clobbering the collected list (sn_admin_page_hooks() replaces on set).
 *
 * Position 81.1 seats it directly beneath the Signal & Noise menu (81) rather
 * than at an arbitrary index: the two are read together, and a float keeps it
 * out of the integer slots core reserves.
 */
function snt_analytics_register_dashboard_page() {
	$hook = add_menu_page(
		__( 'S&N Analytics', 'signal-and-noise-tools' ),
		__( 'S&N Analytics', 'signal-and-noise-tools' ),
		'manage_options',
		SNT_ANALYTICS_PAGE_SLUG,
		'snt_analytics_dashboard_page',
		'dashicons-chart-area',
		81.1
	);

	if ( $hook && function_exists( 'sn_admin_page_hooks' ) ) {
		sn_admin_page_hooks( array_merge( sn_admin_page_hooks(), array( $hook ) ) );
	}
}
add_action( 'admin_menu', 'snt_analytics_register_dashboard_page', 11 );

/**
 * 301 the pre-v12.10.0 Dashboard URL to the new top-level one.
 *
 * `index.php?page=sn-analytics` is in bookmarks, in the browser history, and
 * (until they are updated) in anything that linked here. Without this it lands
 * on the Dashboard with an unknown page slug, which renders a permissions
 * error rather than anything that explains itself.
 */
function snt_analytics_redirect_legacy_url() {
	if ( ! isset( $GLOBALS['pagenow'] ) || 'index.php' !== $GLOBALS['pagenow'] ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL shape check on a GET, no state changes.
	if ( ! isset( $_GET['page'] ) || SNT_ANALYTICS_PAGE_SLUG !== $_GET['page'] ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	$args = array_diff_key( (array) $_GET, array( 'page' => 1 ) );
	$args = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $args ) );
	wp_safe_redirect( snt_analytics_page_url( $args ), 301 );
	exit;
}
add_action( 'admin_init', 'snt_analytics_redirect_legacy_url' );

/**
 * Render callback for Dashboard → Analytics: the read-only dashboard wrapped in
 * the standard admin page chrome. Re-checks the capability (menu visibility
 * isn't access control), wraps in .wrap + an <h1>, resolves any ?sn_flash notice
 * (so a save on the settings page can redirect here with feedback), then
 * delegates the body to snt_analytics_render_dashboard().
 */
function snt_analytics_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'signal-and-noise-tools' ) );
	}

	echo '<div class="wrap">';
	echo '<h1>Analytics</h1>';

	if ( isset( $_GET['sn_flash'] ) && function_exists( 'sn_admin_flash_to_notice' ) ) {
		$notice = sn_admin_flash_to_notice( sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) ) );
		if ( is_array( $notice ) && isset( $notice[0], $notice[1] ) ) {
			// nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- the request value is sanitize_text_field'd, mapped through sn_admin_flash_to_notice()'s fixed table, and both halves are escaped at the sink (esc_attr / wp_kses_post). The rule cannot see the sink-side escaping.
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . wp_kses_post( $notice[1] ) . '</p></div>';
		}
	}

	if ( function_exists( 'snt_analytics_render_dashboard' ) ) {
		snt_analytics_render_dashboard();
	}

	// v8.4.2: the Better Stack uptime monitor moved OFF this tail append —
	// it renders as a postbox on the snt_analytics_after_overview seam
	// inside the dashboard body (inc/uptime-status-widget.php), so it sits
	// under Overview instead of below the fold.

	echo '</div>';
}
