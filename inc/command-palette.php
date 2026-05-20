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
		array( 'wp-commands', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-element' ),
		SNT_VERSION,
		true
	);

	wp_localize_script( 'snt-command-palette', 'sntCommandPalette', array(
		'restNamespace' => 'signal-noise/v1',
		'dashboardUrl'  => admin_url( 'admin.php?page=sn-theme-options' ),
	) );

	wp_enqueue_script( 'snt-command-palette' );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-command-palette', 'signal-noise-tools' );
	}
} );
