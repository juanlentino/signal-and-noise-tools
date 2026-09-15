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

function sn_handle_cf_save( $post ) {
	$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

	if ( ! $token_const ) {
		$new_token = isset( $post['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			delete_option( SN_CF_TOKEN_OPT );
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
		}
	}
	if ( ! $zone_const ) {
		$new_zone = isset( $post['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_zone'] ) ) : '';
		if ( 'clear' === $new_zone ) {
			delete_option( SN_CF_ZONE_OPT );
		} elseif ( '' !== $new_zone ) {
			update_option( SN_CF_ZONE_OPT, $new_zone, true );
		}
	}
	// 14.10.0: the account id lives here too (same option the Analytics tab
	// used to write, so nothing moves in the database).
	$acct_const = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' );
	$acct_opt   = defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? SN_CF_ACCOUNT_ID_OPT : 'sn_cf_account_id';
	if ( ! $acct_const && isset( $post['sn_cf_account_id'] ) ) {
		$new_acct = sanitize_text_field( wp_unslash( $post['sn_cf_account_id'] ) );
		if ( 'clear' === $new_acct ) {
			delete_option( $acct_opt );
		} elseif ( '' !== $new_acct ) {
			update_option( $acct_opt, $new_acct, false );
		}
	}
	return 'cf_saved';
}

function sn_handle_cf_purge_now( $post ) {
	return sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
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
	return ! empty( $r['configured'] ) ? 'cf_monitor_refreshed' : 'cf_purged_unconfigured';
}

/**
 * 14.10.0: drop the separate analytics token so Analytics reads with the
 * central one. The constant, when set, cannot be dropped from here.
 *
 * @param array<string,mixed> $post
 * @return string Flash code.
 */
function sn_handle_analytics_use_central_token( $post ) {
	unset( $post );
	if ( defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) constant( 'SN_CF_ANALYTICS_TOKEN' ) ) {
		return 'analytics_locked';
	}
	delete_option( defined( 'SN_CF_ANALYTICS_TOKEN_OPT' ) ? SN_CF_ANALYTICS_TOKEN_OPT : 'sn_cf_analytics_token' );
	return 'analytics_central_token';
}
