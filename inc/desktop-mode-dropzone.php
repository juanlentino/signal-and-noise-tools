<?php
/**
 * Signal & Noise Tools — Desktop Mode drop-to-draft.
 *
 * Hooks the shell's OS-file-drop pipeline (upstream
 * WordPress/desktop-mode trunk @ 0.9.5, docs/examples/os-file-drop.md):
 * the `desktop-mode.drop.files-detected` JS filter runs BEFORE the
 * shell's MIME/size gate, so markdown/plain-text files can be routed to
 * a drafted Note instead of the Media Library. Images and everything
 * else keep the shell's own upload dialog untouched.
 *
 * PHP's whole job is shipping assets/desktop-dropzone.js onto shell
 * pages for users who can create posts. Everything else is client-side
 * against core REST (wp/v2/posts) — no new server surface.
 *
 * @since 9.77.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the drop handler on Desktop Mode shell pages.
 *
 * Gates: desktop_mode_is_enabled() (per-user opt-in — mirrors the
 * installed-view patch's v9.75.0 lesson: never run shell-only JS for a
 * non-DM session) and edit_posts (the drop creates draft posts).
 *
 * @since 9.77.0
 * @return bool Whether the script was enqueued.
 */
function snt_desktop_dropzone_enqueue() {
	if ( ! function_exists( 'desktop_mode_is_enabled' ) || ! desktop_mode_is_enabled() ) {
		return false;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	wp_enqueue_script(
		'sn-desktop-dropzone',
		plugins_url( 'assets/desktop-dropzone.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-hooks', 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);
	return true;
}
add_action( 'admin_enqueue_scripts', 'snt_desktop_dropzone_enqueue' );
