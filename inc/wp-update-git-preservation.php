<?php
/**
 * Signal & Noise Tools — Preserve .git through WordPress UI installs.
 *
 * Companion to inc/wp-update-integration.php. Closes the footgun where
 * clicking "Update Now" in wp-admin would destroy the plugin's .git
 * directory (via WP_Upgrader::clear_destination()'s recursive delete)
 * and break the canonical `gh workflow run deploy.yml --ref vX.Y.Z`
 * install path on the next deploy.
 *
 * How it works (added v1.11.2, 2026-05-16):
 *
 *   1. `upgrader_pre_install` fires BEFORE WP's clear_destination().
 *      We atomically `rename()` `.git/` → `wp-content/upgrade/<backup>/`.
 *      If the rename fails, we abort the install with WP_Error so the
 *      destruction-by-clear_destination never happens.
 *
 *   2. WP runs its normal install (clear_destination + move_dir from
 *      the renamed source dir — see inc/wp-update-integration.php for
 *      the upgrader_source_selection rename that handles GitHub's
 *      `signal-and-noise-tools-X.Y.Z/` → `signal-and-noise-tools/`).
 *
 *   3. `upgrader_post_install` fires AFTER WP's install completes.
 *      We atomically `rename()` the backup back into the (now newly
 *      installed) destination dir. On WP-side install failure, we
 *      restore to the original location so the OLD code's .git is
 *      intact for the next workflow_dispatch.
 *
 *   4. `admin_init` self-recovery — on every admin pageview, if an
 *      orphaned backup is detected (post_install never fired, e.g.
 *      PHP timeout mid-install), restore it intelligently. Idempotent.
 *
 * Why same-filesystem `rename()`: atomic at the kernel level — no
 * window where `.git` exists in both places or neither. Cross-FS
 * rename silently falls back to copy+delete (NOT atomic). That's why
 * the backup lives under `wp-content/upgrade/` — same mount as
 * `wp-content/plugins/` in every standard WP install incl. Cloudways.
 *
 * Reference: WP_Upgrader::install_package() in
 * wp-admin/includes/class-wp-upgrader.php — verified order is
 * pre_install → source_selection → clear_destination → move_dir →
 * post_install (2026-05-16).
 *
 * Mirrors theme inc/wp-update-git-preservation.php from theme v8.5.2.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the .git backup lives during an install. Under wp-content/upgrade/
 * to guarantee same-filesystem atomic rename(). WP routinely creates +
 * cleans this dir during upgrades — our backup is just another transient.
 */
function snt_plugin_git_backup_path() {
	return trailingslashit( WP_CONTENT_DIR ) . 'upgrade/sn-' . SN_GH_PLUGIN_SLUG . '-git-backup';
}

/**
 * Move .git out of the plugin dir before WP's clear_destination() can
 * destroy it. Atomic same-FS rename().
 *
 * Returning WP_Error from upgrader_pre_install aborts the install
 * entirely (verified against WP source). That's the right safety
 * behavior: better to refuse the install than silently destroy the
 * .git checkout that the canonical deploy path depends on.
 */
add_filter( 'upgrader_pre_install', function( $response, $hook_extra ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
	if ( $plugin !== SN_GH_PLUGIN_BASENAME ) {
		return $response;
	}

	$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . SN_GH_PLUGIN_SLUG;
	$git_dir    = trailingslashit( $plugin_dir ) . '.git';
	$backup     = snt_plugin_git_backup_path();

	// No .git to preserve — already installed via WP UI in the past, or fresh install.
	if ( ! is_dir( $git_dir ) ) {
		return $response;
	}

	// Clean any stale backup from a previously-interrupted run.
	if ( is_dir( $backup ) ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $backup, true );
		}
	}

	// Ensure parent upgrade dir exists (WP normally creates it, but defensive).
	$upgrade_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade';
	if ( ! is_dir( $upgrade_dir ) ) {
		wp_mkdir_p( $upgrade_dir );
	}

	if ( ! @rename( $git_dir, $backup ) ) {
		return new WP_Error(
			'snt_git_backup_failed',
			sprintf(
				'Could not back up the plugin\'s .git directory before WP install. Install aborted to prevent .git destruction. Manual install path: <code>gh workflow run deploy.yml --ref vX.Y.Z --repo %s/%s</code>',
				esc_html( SN_GH_PLUGIN_OWNER ),
				esc_html( SN_GH_PLUGIN_REPO )
			)
		);
	}

	return $response;
}, 10, 2 );

/**
 * Move .git back into the destination after WP's install completes.
 * On WP-side install failure (response is WP_Error), restore to the
 * original plugin dir instead.
 *
 * Never returns WP_Error — the WP install itself succeeded; a failed
 * .git restoration is post-hoc and shouldn't fail the install. Failed
 * restores leave the backup at a known path for manual recovery and
 * are logged + retried by the admin_init self-recovery below.
 */
add_filter( 'upgrader_post_install', function( $response, $hook_extra, $result ) {
	$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
	if ( $plugin !== SN_GH_PLUGIN_BASENAME ) {
		return $response;
	}

	$backup = snt_plugin_git_backup_path();
	if ( ! is_dir( $backup ) ) {
		return $response;
	}

	// Determine where .git should go.
	$dest = '';
	if ( is_array( $result ) && ! empty( $result['destination'] ) ) {
		$dest = (string) $result['destination'];
	} elseif ( is_wp_error( $response ) ) {
		$dest = trailingslashit( WP_PLUGIN_DIR ) . SN_GH_PLUGIN_SLUG;
	}

	if ( ! $dest || ! is_dir( $dest ) ) {
		// Can't determine destination — admin_init recovery will handle it.
		return $response;
	}

	snt_plugin_restore_git_backup( $dest, $backup );

	return $response;
}, 10, 3 );

/**
 * Self-recovery: if a backup exists at the start of any admin pageview,
 * attempt to restore it. Handles the "post_install never fired" cases
 * (PHP timeout mid-install, fatal in another plugin's update hook, etc.).
 *
 * Idempotent — the next pageview after a successful restore sees no
 * backup and skips.
 */
add_action( 'admin_init', function() {
	$backup = snt_plugin_git_backup_path();
	if ( ! is_dir( $backup ) ) {
		return;
	}

	$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . SN_GH_PLUGIN_SLUG;
	if ( ! is_dir( $plugin_dir ) ) {
		return;
	}

	snt_plugin_restore_git_backup( $plugin_dir, $backup );
} );

/**
 * Shared restore primitive. Atomic same-FS rename().
 *
 * - If destination already has .git, sideline the backup (don't
 *   overwrite — could mean a separate workflow_dispatch deploy
 *   already restored git state).
 * - On rename failure, log + leave backup in place for manual recovery.
 */
function snt_plugin_restore_git_backup( $dest, $backup ) {
	$git_dir = trailingslashit( $dest ) . '.git';

	if ( is_dir( $git_dir ) ) {
		// Destination already has .git — sideline the backup with a timestamp
		// so it doesn't accumulate forever; manual cleanup via SFTP if needed.
		$sidelined = $backup . '-sidelined-' . time();
		@rename( $backup, $sidelined );
		error_log( sprintf( '[Signal & Noise Tools] .git restoration skipped: destination already has .git. Backup sidelined at %s', $sidelined ) );
		return;
	}

	if ( ! @rename( $backup, $git_dir ) ) {
		error_log( sprintf( '[Signal & Noise Tools] Could not restore .git from %s to %s. Manual recovery: mv %s %s', $backup, $git_dir, $backup, $git_dir ) );
	}
}
