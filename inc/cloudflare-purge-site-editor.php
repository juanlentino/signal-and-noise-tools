<?php
/**
 * Signal & Noise Tools: a Site Editor save refreshes every page (2026-10-13).
 *
 * Only post and page saves (cloudflare-purge.php) and Styles saves (the
 * theme's sn_auto_purge_on_styles_save) purged the edge. A navigation menu, a
 * template or a template part is in every page that uses it, yet its save
 * purged nothing: the edge served the old menu or footer until the 2-hour
 * edge lifetime ran out. Checked in the theme at origin/main: nothing hooks
 * these post types.
 *
 * One purge of the theme's whole-site cache tag covers every page, feed and
 * machine file; images, styles and scripts stay cached. A theme that does not
 * tag gets nothing here (this site's theme tags since 15.2.0). The ledger row
 * says trigger 'site-editor', scope 'tag'.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Site Editor post types whose save changes pages other than their own. */
const SN_CF_SITE_EDITOR_TYPES = array( 'wp_navigation', 'wp_template', 'wp_template_part' );

/**
 * Purge the whole-site tag when a Site Editor entity that pages show changes:
 * a published one is saved, leaves publish (trash, draft), or is deleted
 * (the Site Editor's "Clear customizations" deletes the wp_template row).
 * Once per request: a request that changes several entities sends one purge.
 * The Site Editor saves each entity in its own REST request, so each of those
 * purges.
 *
 * @param int    $post_id Post ID.
 * @param object $post    The post.
 * @param bool   $was_pub Whether it was published before this change.
 * @return bool Whether a purge was sent.
 */
function sn_cf_site_editor_purge( $post_id, $post, $was_pub = false ) {
	if ( ! empty( $GLOBALS['sn_cf_site_editor_purged'] ) || ! is_object( $post ) ) {
		return false;
	}
	if ( ! in_array( (string) ( $post->post_type ?? '' ), SN_CF_SITE_EDITOR_TYPES, true ) ) {
		return false;
	}
	if ( 'publish' !== (string) ( $post->post_status ?? '' ) && ! $was_pub ) {
		return false;
	}
	if ( ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) || ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) ) {
		return false;
	}
	if ( ! sn_cf_is_configured() || ! sn_cf_tagged() ) {
		return false;
	}
	$GLOBALS['sn_cf_site_editor_purged'] = true;
	sn_cf_purge_tag( 'site-editor' );
	return true;
}

/**
 * save_post_{type}: a published entity was saved.
 *
 * @param int    $post_id Post ID.
 * @param object $post    The saved post.
 * @return bool
 */
function sn_cf_purge_on_site_editor_save( $post_id, $post ) {
	return sn_cf_site_editor_purge( $post_id, $post );
}

/**
 * transition_post_status: a published entity left publish (trashed, drafted).
 *
 * @param string $new_status New status.
 * @param string $old_status Old status.
 * @param object $post       The post.
 * @return bool
 */
function sn_cf_purge_on_site_editor_unpublish( $new_status, $old_status, $post ) {
	if ( 'publish' !== (string) $old_status || 'publish' === (string) $new_status || ! is_object( $post ) ) {
		return false;
	}
	return sn_cf_site_editor_purge( (int) ( $post->ID ?? 0 ), $post, true );
}

/**
 * before_delete_post: a published entity is being deleted ("Clear customizations").
 *
 * @param int         $post_id Post ID.
 * @param object|null $post    The post (WordPress 5.5+).
 * @return bool
 */
function sn_cf_purge_on_site_editor_delete( $post_id, $post = null ) {
	return is_object( $post ) && 'publish' === (string) ( $post->post_status ?? '' ) ? sn_cf_site_editor_purge( (int) $post_id, $post ) : false;
}

foreach ( SN_CF_SITE_EDITOR_TYPES as $sn_cf_type ) {
	add_action( 'save_post_' . $sn_cf_type, 'sn_cf_purge_on_site_editor_save', 30, 2 );
}
unset( $sn_cf_type );
add_action( 'transition_post_status', 'sn_cf_purge_on_site_editor_unpublish', 30, 3 );
add_action( 'before_delete_post', 'sn_cf_purge_on_site_editor_delete', 30, 2 );
