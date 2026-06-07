<?php
/**
 * Signal & Noise Tools — WP 7.0 native Command Palette registration.
 *
 * Surfaces 5 SN actions in WordPress 7.0's built-in ⌘K/Ctrl+K command
 * palette via the @wordpress/commands package. WP 7.0 ships the commands
 * subsystem as JS-only (no PHP wrapper for registerCommand) — this file
 * just enqueues the JS that calls
 *   wp.data.dispatch( 'core/commands' ).registerCommand( ... )
 * with each command's metadata + REST callback.
 *
 * The 5 commands all hit existing REST endpoints under
 * signal-noise/v1/cmd/* registered by inc/desktop-mode-integration.php.
 * One endpoint set, three callers: admin UI, desktop-mode palette,
 * WP-native palette. Avoids duplicating business logic across surfaces.
 *
 * Coexistence with the desktop-mode plugin's own palette:
 *   - desktop-mode's palette runs only when desktop-mode is the active
 *     experience (i.e. in the desktop-mode UI shell).
 *   - WP 7.0's palette runs in vanilla wp-admin everywhere.
 *   - Both can register the same actions without conflict — each addresses
 *     a different surface. We deliberately mirror the most-used 5 of the
 *     13 desktop-mode commands here; full parity isn't the goal.
 *
 * Gated on:
 *   - is_admin() via the admin_enqueue_scripts hook context
 *   - current_user_can( 'manage_options' ) — palette commands are
 *     destructive maintenance ops, admin-only
 *   - wp-commands script handle existing (added in 7.0; the dep array
 *     causes a silent enqueue skip on 6.x without erroring)
 *
 * @package SignalNoiseTools
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_register_script(
		'snt-command-palette',
		plugins_url( 'assets/command-palette.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-commands', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-element', 'wp-html-entities' ),
		SNT_VERSION,
		true
	);

	// v4.11.0: editor-flavored navigation entries.
	//
	// Tabs are built from sn_admin_top_tabs() (the single source of truth in
	// inc/admin-tabs-data.php) so the palette's "Go to <Tab>" commands stay in
	// lockstep with the admin IA. Each tab page is a standard admin.php?page=
	// menu screen.
	$tabs = array();
	if ( function_exists( 'sn_admin_top_tabs' ) ) {
		foreach ( sn_admin_top_tabs() as $tab ) {
			if ( empty( $tab['slug'] ) ) {
				continue;
			}
			$tabs[] = array(
				'label' => isset( $tab['label'] ) ? (string) $tab['label'] : (string) $tab['slug'],
				'url'   => admin_url( 'admin.php?page=' . $tab['slug'] ),
			);
		}
	}

	// Resolve the Notes category id so the JS can fetch the 5 most-recent Notes
	// in ONE apiFetch. 0 when the category is unseeded (fresh install) — the JS
	// then skips the recent-Notes commands entirely.
	$notes_category_id = 0;
	if ( defined( 'SN_NOTES_CATEGORY_SLUG' ) && function_exists( 'get_term_by' ) ) {
		$term = get_term_by( 'slug', SN_NOTES_CATEGORY_SLUG, 'category' );
		if ( $term && isset( $term->term_id ) ) {
			$notes_category_id = (int) $term->term_id;
		}
	}

	wp_localize_script( 'snt-command-palette', 'sntCommandPalette', array(
		'restNamespace'   => 'signal-noise/v1',
		'dashboardUrl'    => admin_url( 'admin.php?page=sn-theme-options' ),
		'newNoteUrl'      => admin_url( 'post-new.php' ),
		'tabs'            => $tabs,
		'notesCategoryId' => $notes_category_id,
	) );

	wp_enqueue_script( 'snt-command-palette' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-command-palette', 'signal-noise-tools' );
	}
} );
