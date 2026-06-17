<?php
/**
 * Signal & Noise — registry-driven admin render dispatcher (admin refactor Phase 1).
 *
 * Replaces the hand-written switch that used to live in sn_theme_options_page().
 * Each tab/sub-tab declares its render fn in sn_admin_top_tabs() (data) +
 * inc/admin-render-sections.php (the named wrappers); this file reads the active
 * (tab, sub) and invokes the right render — rendering the sub-tab nav and the
 * in-page TOC exactly as before. Kept in its own file (not admin-tabs.php) so
 * the contract test can stub the render helpers without a redeclare collision.
 *
 * @package SignalNoiseTools
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registry entry (top-tab) for a tab slug, or null.
 *
 * @param string $tab_slug
 * @return array<string,mixed>|null
 */
function sn_admin_tab_entry( $tab_slug ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] === $tab_slug ) {
			return $top;
		}
	}
	return null;
}

/**
 * Generic, registry-driven render dispatcher. Renders the sub-tab nav, the
 * in-page TOC when the active sub-tab has sub_sections, and the active leaf's
 * declared render fn — wrapping function/do_action leaves in
 * sn_admin_render_section(), but NOT the composite identity-and-seo leaf (which
 * owns its TOC + form, matching pre-refactor behaviour). A landing tab with no
 * sub_tabs (Dashboard) calls its tab-level render directly. An unknown sub-tab
 * falls back to the first leaf (parity with the old switch defaults).
 *
 * @param string $active_tab Resolved top-tab slug.
 * @param string $active_sub Resolved sub-tab slug ('' for landing tabs).
 * @return void
 */
function sn_admin_render_active_tab( $active_tab, $active_sub ) {
	$tab = sn_admin_tab_entry( $active_tab );
	if ( null === $tab ) {
		return;
	}
	$sub_tabs = is_array( $tab['sub_tabs'] ?? null ) ? $tab['sub_tabs'] : array();

	// Landing tab (Dashboard) — tab-level render, no sub-tab nav.
	if ( empty( $sub_tabs ) ) {
		if ( isset( $tab['render'] ) && is_callable( $tab['render'] ) ) {
			call_user_func( $tab['render'] );
		}
		return;
	}

	sn_admin_render_sub_tabs( $active_tab, $active_sub );

	$leaf_slug = isset( $sub_tabs[ $active_sub ] ) ? $active_sub : (string) array_key_first( $sub_tabs );
	$leaf      = $sub_tabs[ $leaf_slug ];
	$render    = $leaf['render'] ?? null;
	if ( ! is_callable( $render ) ) {
		return;
	}

	// Composite leaf (its own TOC + form, e.g. identity-and-seo): render the TOC
	// then call the leaf render bare — no section wrapper (pre-refactor parity).
	if ( ! empty( $leaf['sub_sections'] ) ) {
		sn_admin_render_toc( $active_tab, $leaf_slug );
		call_user_func( $render );
		return;
	}

	// Normal leaf: wrap in the section container, keyed by the sub-tab slug.
	sn_admin_render_section( $leaf_slug, $render );
}
