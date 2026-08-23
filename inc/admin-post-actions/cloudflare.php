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
	return 'cf_saved';
}

function sn_handle_cf_purge_now( $post ) {
	return sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
}
