<?php
/**
 * Signal & Noise — admin POST handlers: IndexNow key, regeneration and manual ping.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: indexnow_save, indexnow_regenerate, indexnow_ping_now
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v5.1.0: save the IndexNow enable toggle. Enabling mints a key on first use
 * (so /<key>.txt resolves immediately). The key lives in its own non-autoloaded
 * option; the toggle in sn_settings.indexnow.enabled.
 */
function sn_handle_indexnow_save( $post ) {
	$enabled = ! empty( $post['indexnow_enabled'] );
	sn_setting_update( 'indexnow.enabled', $enabled );
	if ( $enabled ) {
		sn_indexnow_ensure_key();
	}
	return 'indexnow_saved';
}

/** v5.1.0: regenerate the IndexNow key (invalidates the old /<key>.txt). */
function sn_handle_indexnow_regenerate( $post ) {
	sn_indexnow_regenerate_key();
	return 'indexnow_key_regenerated';
}

/**
 * v5.1.0: one-shot backfill — submit the most-recent published posts so
 * IndexNow learns about content that predates enabling. Bounded to 100.
 */
function sn_handle_indexnow_ping_now( $post ) {
	if ( ! sn_indexnow_is_enabled() || '' === sn_indexnow_get_key() ) {
		return 'indexnow_disabled';
	}
	$ids = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$urls = array_map( 'get_permalink', $ids );
	$urls[] = home_url( '/notes/' );
	sn_indexnow_enqueue( $urls );
	return 'indexnow_pinged';
}
