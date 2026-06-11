<?php
/**
 * Signal & Noise Tools — one-time orphan-option cleanup.
 *
 * Removes options left behind by features that have since changed shape.
 * Sentinel-gated on admin_init so each orphan is deleted exactly once.
 * (Install-time hooks run inside the OLD code's request and can't self-observe;
 * admin_init + a sentinel option is the safe pattern — mirrors
 * sn_webhooks_migrate_autoload in inc/webhooks.php.)
 *
 * @package SignalNoiseTools
 * @since 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete orphaned options once.
 *
 * v5.0.0 removes `sn_login_rewrites_flushed`, orphaned since v4.2.1 when login
 * routing moved off add_rewrite_rule() to the plugins_loaded intercept — the
 * option was a rewrite-flush sentinel for a code path that no longer exists.
 *
 * @since 5.0.0
 */
function sn_migrate_remove_orphan_options() {
	if ( get_option( 'sn_orphan_options_removed_v5' ) ) {
		return;
	}
	delete_option( 'sn_login_rewrites_flushed' );
	update_option( 'sn_orphan_options_removed_v5', 1, false );
}
add_action( 'admin_init', 'sn_migrate_remove_orphan_options' );
