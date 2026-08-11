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
 * v10.43.0: post-#475 OpenStation renames the JS filter to
 * `os.drop.files-detected` (src/os-file-drop/hooks.ts:19,
 * `FILE_DROP_HOOKS.FILES_DETECTED`) — assets/desktop-dropzone.js registers
 * its handler under BOTH names, with a WeakSet guard against a hypothetical
 * future double-fire delivering the same files array to both. PHP's gate
 * (below) is unaffected — it only decides whether to enqueue the script at
 * all.
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
 * Gates: snt_os_is_enabled() (per-user opt-in — mirrors the installed-view
 * patch's v9.75.0 lesson: never run shell-only JS for a non-shell session)
 * and edit_posts (the drop creates draft posts).
 *
 * @since 9.77.0
 * @return bool Whether the script was enqueued.
 */
function snt_desktop_dropzone_enqueue() {
	// v10.43.0: snt_os_is_enabled() dispatches to openstation_is_enabled()
	// post-#475 or desktop_mode_is_enabled() pre-rename — see
	// inc/openstation-compat.php.
	if ( ! snt_os_is_enabled() ) {
		return false;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	wp_enqueue_script(
		'sn-desktop-dropzone',
		plugins_url( 'assets/desktop-dropzone.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		// v10.43.0: sn-desktop-mode-os-compat aliases window.wp.desktop ↔
		// window.wp.os before this file's notify() reads window.wp.desktop —
		// registered on init:5 in inc/desktop-mode-assets.php, so the
		// handle exists by the time this admin_enqueue_scripts callback runs.
		array( 'sn-desktop-mode-os-compat', 'wp-hooks', 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);
	return true;
}
add_action( 'admin_enqueue_scripts', 'snt_desktop_dropzone_enqueue' );
