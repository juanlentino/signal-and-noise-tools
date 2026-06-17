<?php
/**
 * Signal & Noise — Theme options admin page (render orchestrator).
 *
 * Holds sn_theme_options_page(), the render callback for the Signal & Noise
 * admin screens: it resolves the active tab, emits the page shell + top-tab
 * nav, and dispatches each tab to its renderer — most via do_action() hooks
 * (`sn_admin_cloudflare_tab`, `sn_admin_reading_time_tab`, …) so each subsystem
 * keeps its UI colocated with its logic, plus the Identity & SEO / Login /
 * Links sections via inc/admin-forms/*.php.
 *
 * The surrounding concerns were split out of this file in v4.5.4 (it had grown
 * to ~1,468 lines) into sibling modules, all loaded via the flat require_once
 * manifest in signal-and-noise-tools.php:
 *   - inc/admin-tabs-data.php       — the 6-tab IA data (sn_admin_top_tabs).
 *   - inc/admin-tabs.php            — tab accessors + nav/section renderers.
 *   - inc/admin-legacy-redirect.php — legacy ?page=/?tab= → canonical 301s.
 *   - inc/admin-menu.php            — menu registration + asset enqueue.
 *   - inc/admin-flash-messages.php  — ?sn_flash= → admin-notice resolver.
 *   - inc/admin-post-handler.php    — admin_init form dispatcher (PRG).
 *   - inc/admin-post-actions.php    — the per-action handler functions.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_theme_options_page() {
	// Defense-in-depth capability check. WordPress's add_theme_page()
	// already gates access to the admin URL itself, but re-checking here
	// matches WPCS convention for any handler that mutates state and
	// keeps this function safe if it's ever invoked from another context
	// (e.g. a future shortcode, AJAX dispatcher, or REST callback).
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
	}

	// v3.8.0+: 301-redirect legacy tab/page slugs to canonical destinations.
	// Must run BEFORE any output so headers can still be sent.
	sn_admin_maybe_redirect_legacy();

	$theme         = wp_get_theme( 'signal-and-noise' );
	$local_version = $theme->get( 'Version' );
	$notices       = array();
	$valid_tabs = sn_admin_page_valid_tabs();

	// Dispatch order: (1) explicit ?tab=… in URL (v1.8.x legacy deep links;
	// must keep working); (2) derive from the current ?page=… slug (v1.9.0
	// path — each sidebar submenu has a unique slug). Default to dashboard
	// if neither resolves.
	if ( isset( $_GET['tab'] ) ) {
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	} else {
		$current_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
		$active_tab   = sn_admin_page_tab_for_slug( $current_slug );
	}

	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'dashboard';
	}

	// Form processing happens in sn_handle_admin_post() on admin_init —
	// before any output. This block just translates ?sn_flash=… into a
	// notice for the post-redirect GET request, via the shared flash
	// registry (inc/admin-flash-messages.php) — the single source of truth
	// consumed by both the dispatcher (which emits the codes) and here.
	if ( isset( $_GET['sn_flash'] ) ) {
		$notice = sn_admin_flash_to_notice( sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) ) );
		if ( $notice ) {
			$notices[] = $notice;
		}
	}

	// Extract the new/rotated webhook id from the flash so the Webhooks
	// renderer can highlight the affected row + show the secret once.
	if ( ! isset( $_GET['new_id'] ) && isset( $_GET['sn_flash'] ) ) {
		$flash_now = sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) );
		if ( 0 === strpos( $flash_now, 'wh_added_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_added_' ) );
		} elseif ( 0 === strpos( $flash_now, 'wh_rotated_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_rotated_' ) );
		}
	}

	// v4.1.1 (X-03): removed dead `$local_sha = get_option('sn_github_local_sha', '')`.
	// The option was written by the legacy updater (inc/updater.php) retired in
	// theme v8.3.0 — the variable was never read after fetch and the option is
	// always empty string on current installs. Existing leftover DB data is
	// harmless; no migration needed.

	$overrides = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part', 'wp_navigation' ), 'posts_per_page' => -1, 'post_status' => 'any' ) );
	$base_url  = admin_url( 'admin.php?page=sn-theme-options' );

	// ── PAGE SHELL ──
	echo '<div class="wrap">';
	echo '<h1 class="sn-page-h1">Signal &amp; Noise</h1>';
	$subtitle = sn_admin_page_subtitle_for_tab( $active_tab );
	if ( $subtitle ) {
		echo '<p class="sn-page-subtitle">' . esc_html( $subtitle ) . '</p>';
	}

	// Notices. Severity is escaped as an attribute; bodies are run
	// through wp_kses_post because some entries deliberately ship
	// inline markup (<a>, <code>) — esc_html would mangle those.
	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
	}

	// ── TABS ──
	$tab_labels = sn_admin_page_tab_labels();
	echo '<nav class="nav-tab-wrapper sn-nav-tabs">';
	foreach ( $tab_labels as $slug => $label ) {
		$is_active = ( $slug === $active_tab );
		echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	// v3.8.1+: resolve the active sub-tab for the current top tab. Used by
	// every dispatch arm below to render only the active sub-tab's content
	// instead of all sub-sections (fixes the v3.8.0 long-scroll-per-tab issue).
	// Returns '' for Dashboard (which has no sub_tabs).
	$active_sub = sn_admin_resolve_active_sub( $active_tab );

	// v6.17.x (admin refactor Phase 1): registry-driven dispatch. Each tab/
	// sub-tab declares its render fn in sn_admin_top_tabs(); the dispatcher
	// renders the sub-tab nav + (TOC when applicable) + the active leaf.
	sn_admin_render_active_tab( $active_tab, $active_sub );

	echo '</div>'; // wrap
}
