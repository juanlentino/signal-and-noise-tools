<?php
/**
 * Signal & Noise — admin POST handlers: outbound webhook rows.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: webhook_add, webhook_update, webhook_delete
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_webhook_add( $post ) {
	if ( function_exists( 'sn_webhook_create' ) ) {
		$result = sn_webhook_create( wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_invalid';
		}
		// Encode new id in the flash so the renderer can show the secret once.
		return 'wh_added_' . $result['id'];
	}
	return 'wh_invalid';
}

function sn_handle_webhook_update( $post ) {
	if ( function_exists( 'sn_webhook_update' ) ) {
		$id     = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		$rotate = ! empty( $post['rotate_secret'] );
		$result = sn_webhook_update( $id, wp_unslash( $post ) );
		if ( is_wp_error( $result ) ) {
			return 'wh_not_found';
		}
		return $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
	}
	return 'wh_not_found';
}

function sn_handle_webhook_delete( $post ) {
	if ( function_exists( 'sn_webhook_delete' ) ) {
		$id = isset( $post['webhook_id'] ) ? sanitize_text_field( wp_unslash( $post['webhook_id'] ) ) : '';
		sn_webhook_delete( $id );
	}
	return 'wh_deleted';
}
