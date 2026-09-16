<?php
/**
 * Signal & Noise — admin POST handlers: Cloudflare credentials and manual purge.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: cf_save, cf_purge_now
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_cf_purge_now( $post ) {
	unset( $post );
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		return 'cf_purged_unconfigured';
	}
	// 15.1.0: the full chain, verified, the same as purge_caches. Cloudflare
	// alone left Varnish holding the stale copy the edge then refilled from.
	apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false, 'verified' => true ) );
	return 'purged';
}

/**
 * 14.9.0: run the Cloudflare monitor now (token verify, zone, firewall).
 * Read-only against Cloudflare.
 *
 * @param array<string,mixed> $post
 * @return string Flash code.
 */
function sn_handle_cf_monitor_refresh( $post ) {
	unset( $post );
	if ( ! function_exists( 'sn_cf_monitor_refresh' ) ) {
		return 'cf_monitor_unavailable';
	}
	$r = sn_cf_monitor_refresh();
	if ( function_exists( 'sn_cf_firewall_events_refresh' ) ) {
		sn_cf_firewall_events_refresh(); // 15.1.0: the event log rides the same button.
	}
	if ( function_exists( 'sn_cf_posture_refresh' ) ) {
		sn_cf_posture_refresh(); // 15.4.0: settings, DNSSEC and the custom rules too.
	}
	return ! empty( $r['configured'] ) ? 'cf_monitor_refreshed' : 'cf_purged_unconfigured';
}

