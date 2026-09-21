<?php
/**
 * Signal & Noise Tools — WP 7.0 native Command Palette registration.
 *
 * Surfaces the core SN actions in WordPress 7.0's built-in ⌘K/Ctrl+K command
 * palette via the @wordpress/commands package. WP 7.0 ships the commands
 * subsystem as JS-only (no PHP wrapper for registerCommand) — this file
 * just enqueues the JS that calls
 *   wp.data.dispatch( 'core/commands' ).registerCommand( ... )
 * with each command's metadata + REST callback.
 *
 * The commands run through window.sntAbilityRun (the shared Abilities
 * transport) — the signal-noise/v1/cmd/* REST routes this file once relied
 * on were removed in v7.0.0. One ability set, three callers (see assets/command-palette.js for the current roster — counts here went stale twice, so the property, not the number, is documented): admin UI,
 * desktop-mode palette, WP-native palette — no duplicated business logic.
 *
 * Coexistence with the OpenStation shell palette (#1626): one registration
 * per document.
 *   - On an OpenStation document (the shell, or a chromeless window) this
 *     contributor is NOT enqueued. The shell's copy of the maintenance
 *     commands is the sn-cmd-* registration in inc/desktop-mode-commands.php
 *     and assets/desktop-mode.js, through wp.os.registerCommand.
 *   - In vanilla wp-admin outside the station, this contributor loads and
 *     registers into core/commands, and its feedback stays the wp-admin
 *     notice strip.
 *   - Why a request predicate and not the hoist: since OpenStation 1.1.4 the
 *     shell hoists every wp-commands contributor into its deferred manifest
 *     and its harvester republishes each core/commands entry into the
 *     OpenStation registry, so both registrations landed in the same
 *     document and Cmd+K listed each label twice (measured 2026-09-20 on
 *     1.1.10). The hoist and harvest are Experimental seams; the predicate
 *     is the documented gate ("Gate shell-only enqueues and output on this,
 *     never on a screen id").
 *
 * Gated on:
 *   - not an OpenStation document: openstation_is_shell_request() (Stable)
 *     and openstation_is_chromeless_request() (includes/core/routing.php,
 *     listed in docs/architecture.md, not marked Stable), both behind
 *     function_exists
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
	// #1626: never on an OpenStation document. The shell carries the sn-cmd-*
	// registration; a chromeless window's iframe bridge would republish this
	// contributor's commands as a third, eager copy. Both predicates are
	// guarded because the plugin runs on classic installs too.
	if ( function_exists( 'openstation_is_shell_request' ) && openstation_is_shell_request() ) {
		return;
	}
	if ( function_exists( 'openstation_is_chromeless_request' ) && openstation_is_chromeless_request() ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_register_script(
		'snt-command-palette',
		plugins_url( 'assets/command-palette.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-commands', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-element', 'wp-html-entities', 'snt-ability-run' ),
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
		wp_set_script_translations( 'snt-command-palette', 'signal-and-noise-tools' );
	}
} );
