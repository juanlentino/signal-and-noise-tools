<?php
/**
 * Signal & Noise — admin tab framework.
 *
 * Derived accessors over sn_admin_top_tabs() (valid tabs, labels, subtitle,
 * sub-tab resolution) plus the nav/section renderers (top-tab TOC, sub-tab
 * nav, section wrapper). No data tables here — those live in
 * inc/admin-tabs-data.php. Extracted from inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the in-form section tabs for a multi-section sub-tab (e.g., Identity &
 * SEO with its 4 inner sections: Identity / Social / Open Graph / SEO Copy).
 *
 * Reads sub-sections from sn_admin_top_tabs()'s nested
 * sub_tabs[<sub>]['sub_sections']. No-op if the sub-tab has no inner
 * sub_sections defined.
 *
 * Renders the SAME pill nav as the cross-page sub-tab row (.sn-sub-tabs) so a
 * composite leaf reads identically to the other top tabs — but the links are
 * in-page anchors, not page navigations, because the 4 sections share one
 * <form> and one Save button. assets/admin.js initSectionTabs() progressively
 * enhances this into a panel switcher (show one section at a time, ARIA
 * tablist, arrow-key nav); without JS the anchors degrade to jump links with
 * every section visible (the pre-v6.19.4 behaviour). The first tab carries
 * is-active to match the JS default-open panel.
 *
 * Generates: <nav class="sn-sub-tabs sn-section-tabs" aria-label="...">
 *              <a class="sn-sub-tab is-active" href="#sn-sec-X">…</a>…</nav>
 *
 * v6.19.4 change: was sn_admin_render_toc() emitting a "Jump to" .sn-toc list;
 * restyled to in-form tabs for visual parity with the other tabs' sub-tab nav.
 *
 * @since 3.8.0  (3.8.1 added $sub_tab_slug; 6.19.4 renamed + restyled to tabs)
 * @param string $tab_slug      The top-tab slug (e.g., 'site').
 * @param string $sub_tab_slug  The sub-tab slug (e.g., 'identity-and-seo').
 */
function sn_admin_render_section_tabs( $tab_slug, $sub_tab_slug ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] !== $tab_slug ) {
			continue;
		}
		$sub_tab = $top['sub_tabs'][ $sub_tab_slug ] ?? null;
		if ( ! is_array( $sub_tab ) || empty( $sub_tab['sub_sections'] ) ) {
			return;
		}
		echo '<nav class="sn-sub-tabs sn-section-tabs" aria-label="' . esc_attr( $sub_tab['label'] . ' sections' ) . '">';
		$first = true;
		foreach ( $sub_tab['sub_sections'] as $sub_slug => $sub ) {
			$class = 'sn-sub-tab' . ( $first ? ' is-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="#sn-sec-' . esc_attr( $sub_slug ) . '">' . esc_html( $sub['label'] ) . '</a>';
			$first = false;
		}
		echo '</nav>';
		return;
	}
}

/**
 * Render the sub-tab nav for a top tab. Reads sub_tabs from
 * sn_admin_top_tabs() — single source of truth for both display order
 * and labels.
 *
 * Generates: <nav class="sn-sub-tabs"><a href="?tab=...&sub=...">…</a></nav>
 *
 * Hidden (returns without echoing) when:
 * - Top tab has 0 sub_tabs (Dashboard — landing page)
 * - Top tab has only 1 sub_tab (Security at v3.8.1 — single-item nav is noise)
 *
 * @since 3.8.1
 * @param string $tab_slug     The top-tab slug.
 * @param string $active_sub   The currently-active sub-tab slug (for is-active class).
 */
function sn_admin_render_sub_tabs( $tab_slug, $active_sub ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] !== $tab_slug ) {
			continue;
		}
		$sub_tabs = is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
		if ( count( $sub_tabs ) < 2 ) {
			// 0 sub_tabs (Dashboard) or 1 sub_tab (Security at v3.8.1) → no nav.
			return;
		}
		$base_url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $tab_slug ) );
		echo '<nav class="sn-sub-tabs" aria-label="' . esc_attr( $top['label'] . ' sub-tabs' ) . '">';
		foreach ( $sub_tabs as $sub_slug => $sub ) {
			$is_active = ( $sub_slug === $active_sub );
			$class     = 'sn-sub-tab' . ( $is_active ? ' is-active' : '' );
			$url       = $base_url . '&sub=' . rawurlencode( $sub_slug );
			$aria      = $is_active ? ' aria-current="page"' : '';
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '"' . $aria . '>' . esc_html( $sub['label'] ) . '</a>';
		}
		echo '</nav>';
		return;
	}
}

/**
 * Helper: get the configured sub_tabs array for a top tab.
 * Returns empty array if the tab has no sub_tabs.
 *
 * @since 3.8.1
 * @param string $tab_slug
 * @return array<string,array<string,mixed>>
 */
function sn_admin_get_sub_tabs( $tab_slug ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] === $tab_slug ) {
			return is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
		}
	}
	return array();
}

/**
 * Helper: resolve the active sub-tab for a top tab from $_GET['sub'].
 * Falls back to the first configured sub-tab. Returns empty string if
 * the top tab has no sub_tabs (Dashboard).
 *
 * @since 3.8.1
 * @param string $tab_slug
 * @return string The active sub-tab slug (or '' if no sub_tabs configured).
 */
function sn_admin_resolve_active_sub( $tab_slug ) {
	$sub_tabs = sn_admin_get_sub_tabs( $tab_slug );
	if ( empty( $sub_tabs ) ) {
		return '';
	}
	$requested = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : '';
	if ( $requested && isset( $sub_tabs[ $requested ] ) ) {
		return $requested;
	}
	// Default: first sub-tab in display order.
	return (string) array_key_first( $sub_tabs );
}

/**
 * Render a sub-section wrapper with anchor target. The callback emits
 * the section's actual content (form fields, hook invocation, etc.).
 *
 * Wraps with .sn-fieldset (matching the existing Identity tab pattern)
 * so existing CSS at admin.css applies without changes. The anchor ID
 * is the structural commitment for the TOC links.
 *
 * For module-hook sub-sections (e.g., Cloudflare), the callback should
 * just `do_action('sn_admin_<slug>_tab')` — the hook listener will
 * emit its own heading + form inside this wrapper.
 *
 * @since 3.8.0
 * @param string   $section_slug Anchor target (e.g., 'identity', 'cloudflare').
 * @param callable $callback     Emits the section body.
 */
function sn_admin_render_section( $section_slug, $callback ) {
	echo '<div class="sn-fieldset" id="sn-sec-' . esc_attr( $section_slug ) . '">';
	call_user_func( $callback );
	echo '</div>';
}

/**
 * Look up the subtitle for the active tab. Used by the page header.
 *
 * v3.8.0+: reads from sn_admin_top_tabs() (the new 6-tab structure).
 * The legacy sn_admin_pages() still drives the WP submenu sidebar
 * (preserves all 12 deep-link shortcuts) but the page header reflects
 * the new top-tab IA the user is actually navigating.
 */
function sn_admin_page_subtitle_for_tab( $tab ) {
	foreach ( sn_admin_top_tabs() as $page ) {
		if ( $page['tab'] === $tab ) {
			return $page['subtitle'];
		}
	}
	return '';
}

/**
 * Single source of truth: every tab slug registered in sn_admin_pages().
 *
 * Derived (not duplicated) so adding a new tab is a one-line edit in
 * sn_admin_pages(). v3.0.0 shipped a regression where Task 10 added the
 * page entry + dispatch case but missed two inline whitelists 200 lines
 * away (CHANGELOG v3.0.2). Encoding this as a derived helper makes the
 * coordination constraint impossible to violate.
 *
 * @since 3.0.2
 */
function sn_admin_page_valid_tabs() {
	// v3.8.0+: derive from the 6 NEW top tabs. Legacy tab slugs are
	// handled by sn_admin_maybe_redirect_legacy() (301-redirected before
	// dispatch ever reaches the valid-tabs check).
	return array_column( sn_admin_top_tabs(), 'tab' );
}

/**
 * Single source of truth: tab → label map, keyed by tab slug.
 *
 * @since 3.0.2
 */
function sn_admin_page_tab_labels() {
	// v3.8.0+: derive from the 6 NEW top tabs (drives the in-page
	// .nav-tab-wrapper). The WP submenu sidebar still uses
	// sn_admin_pages() for its 12 entries.
	return array_column( sn_admin_top_tabs(), 'label', 'tab' );
}
