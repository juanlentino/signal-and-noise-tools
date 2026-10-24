<?php
/**
 * Signal & Noise Tools: a 301 for every page path a rename or re-parent retires
 * (2026-10-24).
 *
 * Core keeps a renamed post reachable (wp_check_for_changed_slugs() on
 * post_updated stores _wp_old_slug) but returns early for hierarchical types,
 * so a renamed page, and every page under it, 404s at its old path. Page 404s
 * from Search Console are what grew inc/legacy-page-redirects.php. This hooks
 * the same action core uses and records each retired path in the owner's
 * redirect store (inc/redirects-store.php), where Site > Redirects lists,
 * edits and removes it like any other entry.
 *
 * The guards, in the order they run:
 *   - Published before AND after. A draft's path was never public, and a page
 *     going to draft or trash is not a move (trash renames to slug__trashed).
 *   - Each published descendant moves with its ancestor, at its own path.
 *   - A redirect sitting on a path a moved page now holds is removed: it would
 *     hide the live page, and after a rename back it is half of a loop.
 *   - An old path that another published page now holds is never recorded.
 *   - Entries that pointed at an old path are repointed at the new one, since
 *     the store resolves one hop per request (the tag map's rule, in
 *     inc/tag-consolidation-redirects.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The paths a page update retired, old => new, for the page and each published
 * descendant. Empty unless a published page kept its status and changed slug
 * or parent.
 *
 * @param object $after  The page after the update.
 * @param object $before The page before it.
 * @return array<string,string>
 */
function sn_page_rename_moves( $after, $before ) {
	if ( 'page' !== ( $after->post_type ?? '' ) || 'publish' !== ( $after->post_status ?? '' ) || 'publish' !== ( $before->post_status ?? '' ) ) {
		return array();
	}
	if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
		return array();
	}
	$old = sn_redirects_normalize_path( (string) get_page_uri( $before ) );
	$new = sn_redirects_normalize_path( (string) get_page_uri( $after ) );
	if ( '/' === $old || $old === $new ) {
		return array();
	}
	$moves = array( $old => $new );
	// Every status, so a published page under a draft parent is still found.
	$tree = get_pages(
		array(
			'child_of'    => (int) $after->ID,
			'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
		)
	);
	foreach ( (array) $tree as $child ) {
		$path = sn_redirects_normalize_path( (string) get_page_uri( $child ) );
		if ( 'publish' === $child->post_status && 0 === strpos( $path, $new . '/' ) ) {
			$moves[ $old . substr( $path, strlen( $new ) ) ] = $path;
		}
	}
	return $moves;
}

/**
 * post_updated: record the retired paths in the redirect store.
 *
 * @param int    $post_id     Post ID.
 * @param object $post_after  The post after the update.
 * @param object $post_before The post before it.
 * @return void
 */
function sn_page_rename_record( $post_id, $post_after, $post_before ) {
	if ( ! is_object( $post_after ) || ! is_object( $post_before ) ) {
		return;
	}
	$moves = sn_page_rename_moves( $post_after, $post_before );
	foreach ( $moves as $new ) {
		sn_redirect_delete( $new );
	}
	foreach ( $moves as $old => $new ) {
		$holder = get_page_by_path( ltrim( $old, '/' ) );
		if ( $holder && 'publish' === get_post_status( $holder ) ) {
			continue;
		}
		foreach ( sn_redirects_all() as $from => $row ) {
			$to = (string) ( $row['to'] ?? '' );
			if ( ! preg_match( '#^https?://#i', $to ) && sn_redirects_normalize_path( $to ) === $old ) {
				sn_redirect_save( $from, $new, (int) ( $row['status'] ?? 301 ) );
			}
		}
		sn_redirect_save( $old, $new, 301 );
	}
}
add_action( 'post_updated', 'sn_page_rename_record', 10, 3 );
