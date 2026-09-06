<?php
/**
 * Signal & Noise app — the item builder every POST section paints from.
 *
 * Notes and Pages are one surface over two post types: the same five editable
 * statuses, the same provenance badge and signed-chain table, the same rights
 * riding the item, the same dossier. What differs is the QUERY -- a category
 * for Notes, the signing opt-in meta for Pages -- and two words. So the
 * builder takes a config instead of a post type sprinkled through fourteen
 * places; a third post section is a descriptor, not a fork of this file.
 *
 * The `$cfg` a section passes:
 *
 *   post_type    string   'post' | 'page'; the query's post type AND the type
 *                         whose object answers the publish capability.
 *   statuses     string[] the post statuses the section lists.
 *   kind_filter  string   optional category slug (Notes). `category` is a
 *                         POST-ONLY taxonomy, so a page section leaves it ''.
 *   meta_key     string   optional meta predicate (Pages: the signing opt-in).
 *   meta_value   string   the value that predicate must equal.
 *   verify_link  bool     whether the public verifier may be offered at all.
 *
 * BOTH QUERIES CARRY `'perm' => 'readable'`. These sections list draft,
 * pending and private, and `edit_posts` / `edit_pages` are Author-level
 * capabilities: without `perm` an Author would read other people's
 * unpublished work through this window. With it, WordPress restricts the
 * unpublished half of the result to what the CURRENT user may read.
 *
 * THE PUBLISH CAP COMES FROM THE POST TYPE OBJECT, never the literal
 * `publish_posts`: that is the POST cap, and a page needs `publish_pages`.
 * `edit_post` and `delete_post` are meta caps and already map per type.
 *
 * THE VERIFIER IS FOR NOTES. The public verifier takes `?note=<uid>`; whether
 * it accepts a page's `pages/` ledger record is a fact about the sibling
 * theme, not about this plugin. So the Verify action is built only when the
 * subject kind is `note`, and a signed page's ledger record is reached
 * through the dossier's anchor-proof door instead.
 *
 * @package SignalNoiseTools
 * @since 13.102.0
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** The editable statuses a post section lists, newest first, in pill order. */
const POST_SECTION_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

/**
 * The five status pills, as the descriptor declares them. One list, so two
 * sections can never drift into offering different filters over one query.
 *
 * @return array<int,array{value:string,label:string}>
 */
function post_section_status_pills() {
	return array(
		array( 'value' => 'publish', 'label' => __( 'Published', 'signal-and-noise-tools' ) ),
		array( 'value' => 'future', 'label' => __( 'Scheduled', 'signal-and-noise-tools' ) ),
		array( 'value' => 'draft', 'label' => __( 'Drafts', 'signal-and-noise-tools' ) ),
		array( 'value' => 'pending', 'label' => __( 'Pending', 'signal-and-noise-tools' ) ),
		array( 'value' => 'private', 'label' => __( 'Private', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The list-view columns a post section carries. Both post sections read the
 * same two facts off the chain, so both declare the same pair.
 *
 * @return array<int,array{key:string,label:string}>
 */
function post_section_columns() {
	return array(
		array( 'key' => 'versions', 'label' => __( 'Versions', 'signal-and-noise-tools' ) ),
		array( 'key' => 'anchor', 'label' => __( 'Anchor', 'signal-and-noise-tools' ) ),
	);
}

/**
 * The WP_Query arguments a post section reads with.
 *
 * @param array<string,mixed> $cfg      Section config (see the file docblock).
 * @param int                 $per_page Posts per page.
 * @param bool                $ids_only Count query: ids and found_posts only.
 * @return array<string,mixed>
 */
function post_query_args( array $cfg, $per_page, $ids_only ) {
	$statuses = array_values( array_map( 'strval', (array) ( $cfg['statuses'] ?? POST_SECTION_STATUSES ) ) );
	$args     = array(
		'post_type'      => (string) ( $cfg['post_type'] ?? 'post' ),
		'post_status'    => $statuses,
		'posts_per_page' => (int) $per_page,
		// An Author holds edit_posts/edit_pages. Without this, the draft,
		// pending and private half of the list would be everyone's.
		'perm'           => 'readable',
	);
	if ( $ids_only ) {
		$args['fields'] = 'ids';
	} else {
		$args['orderby']       = array( 'date' => 'DESC', 'ID' => 'DESC' );
		$args['no_found_rows'] = true;
	}
	$kind_filter = (string) ( $cfg['kind_filter'] ?? '' );
	if ( '' !== $kind_filter ) {
		$args['category_name'] = $kind_filter;
	}
	$meta_key = (string) ( $cfg['meta_key'] ?? '' );
	if ( '' !== $meta_key ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded corpus (dozens of signed pages), mirrors inc/provenance-integrity.php.
		$args['meta_key'] = $meta_key;
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the opt-in is a single stored value; a meta_query for one pair reads no faster.
		$args['meta_value'] = (string) ( $cfg['meta_value'] ?? '' );
	}
	return $args;
}

/**
 * Every post the section holds, newest first, each with its dossier inline.
 *
 * @param array<string,mixed> $cfg Section config.
 * @return array<int,array<string,mixed>>
 */
function post_items( array $cfg ) {
	$query = new \WP_Query( post_query_args( $cfg, (int) SN_OS_APP_ITEM_CAP, false ) );
	$items = array();
	foreach ( (array) $query->posts as $post ) {
		$items[] = post_item( $post, $cfg );
	}
	return $items;
}

/**
 * How many the section holds, for the root folder tile.
 *
 * @param array<string,mixed> $cfg Section config.
 * @return int
 */
function post_count( array $cfg ) {
	$query = new \WP_Query( post_query_args( $cfg, 1, true ) );
	return (int) $query->found_posts;
}

/**
 * The tile icon for a post type.
 *
 * @param string $post_type Post type.
 * @return string Dashicons class.
 */
function post_item_icon( $post_type ) {
	return 'page' === (string) $post_type ? 'dashicons-admin-page' : 'dashicons-edit-page';
}

/**
 * The capability that publishes this post type, from the type object.
 *
 * `publish_posts` is the POST capability. A page needs `publish_pages`, and
 * only the registered type object knows which. A type that is not registered
 * (or a stripped install) answers nothing, and an unknown right is a refusal.
 *
 * @param string $post_type Post type.
 * @return string Capability name, or '' when the type is unknown.
 */
function post_publish_cap( $post_type ) {
	$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( (string) $post_type ) : null;
	if ( ! is_object( $object ) || ! isset( $object->cap ) || ! is_object( $object->cap ) || empty( $object->cap->publish_posts ) ) {
		return '';
	}
	return (string) $object->cap->publish_posts;
}

/**
 * The provenance subject kind for a post: 'note', 'page' or '' when the
 * resolver is not loaded. Never guessed from the post type: a post outside
 * the Notes category and a page that never opted in are both not subjects.
 *
 * @param \WP_Post|object $post Post.
 * @return string
 */
function post_subject_kind( $post ) {
	return function_exists( 'sn_prov_subject_kind' ) ? (string) \sn_prov_subject_kind( $post ) : '';
}

/**
 * One post as the client sees it.
 *
 * @param \WP_Post|object     $post Post.
 * @param array<string,mixed> $cfg  Section config.
 * @return array<string,mixed>
 */
function post_item( $post, array $cfg ) {
	$id     = (int) $post->ID;
	$title  = '' !== $post->post_title ? (string) $post->post_title : __( '(no title)', 'signal-and-noise-tools' );
	$status = get_post_status_object( (string) $post->post_status );
	$slabel = $status && ! empty( $status->label ) ? (string) $status->label : (string) $post->post_status;
	$prov   = \snt_os_app_note_provenance( $id );
	$kind   = post_subject_kind( $post );
	$badge  = null;
	// Read off the chain phase one already loaded for the badge -- a second
	// read per item would be one query per note for a boolean.
	$unanchored = false;
	foreach ( (array) ( $prov['commits'] ?? array() ) as $commit ) {
		if ( 'unanchored' === (string) ( $commit['status'] ?? '' ) ) {
			$unanchored = true;
			break;
		}
	}
	$facts  = array(
		array( __( 'Status', 'signal-and-noise-tools' ), $slabel ),
		array( 'future' === $post->post_status ? __( 'Scheduled for', 'signal-and-noise-tools' ) : __( 'Date', 'signal-and-noise-tools' ), get_the_date( '', $post ) ),
	);
	$blocks  = array();
	$actions = array();

	if ( $prov ) {
		$anchor  = \snt_os_app_anchor_badge( $prov['status'] );
		$badge   = array( 'text' => 'v' . (int) $prov['versions'], 'tone' => $anchor['tone'], 'title' => $anchor['label'] );
		$facts[] = array( __( 'Signed versions', 'signal-and-noise-tools' ), (string) (int) $prov['versions'] );
		$facts[] = array( __( 'Anchor', 'signal-and-noise-tools' ), $anchor['label'] );
		$rows    = array();
		foreach ( array_reverse( $prov['commits'] ) as $commit ) {
			$a      = \snt_os_app_anchor_badge( $commit['status'] );
			$rows[] = array(
				'version' => 'v' . (int) $commit['version'],
				'anchor'  => array( 'text' => $a['label'], 'tone' => $a['tone'] ),
				'date'    => substr( (string) $commit['committed_at'], 0, 10 ),
				'hash'    => array( 'code' => substr( (string) $commit['content_hash'], 0, 12 ), 'title' => (string) $commit['content_hash'] ),
			);
		}
		$blocks[] = array(
			'heading' => __( 'Signed chain', 'signal-and-noise-tools' ),
			'kind'    => 'table',
			'columns' => array(
				array( 'key' => 'version', 'label' => __( 'Version', 'signal-and-noise-tools' ) ),
				array( 'key' => 'anchor', 'label' => __( 'Anchor', 'signal-and-noise-tools' ) ),
				array( 'key' => 'date', 'label' => __( 'Committed', 'signal-and-noise-tools' ) ),
				array( 'key' => 'hash', 'label' => __( 'Hash', 'signal-and-noise-tools' ) ),
			),
			'rows'    => $rows,
		);
		if ( ! empty( $prov['uid'] ) ) {
			$blocks[] = array( 'heading' => __( 'Ledger UID', 'signal-and-noise-tools' ), 'kind' => 'code', 'text' => (string) $prov['uid'] );
			// The verifier's parameter is literally `note`, and the sibling
			// theme owns it. Offered for a note only; a page's record is
			// reached through the dossier's anchor-proof door.
			if ( ! empty( $cfg['verify_link'] ) && 'note' === $kind ) {
				$actions[] = array(
					'label' => __( 'Verify', 'signal-and-noise-tools' ),
					'url'   => add_query_arg( array( 'note' => (string) $prov['uid'], 'v' => (int) $prov['versions'] ), home_url( '/verify/' ) ),
				);
			}
			if ( current_user_can( 'edit_post', $id ) ) {
				$actions[] = array( 'label' => __( 'Re-check now', 'signal-and-noise-tools' ), 'dispatch' => 'verify', 'args' => array( 'item' => (string) $id ) );
			}
		}
	} else {
		$blocks[] = array(
			'heading' => __( 'Provenance', 'signal-and-noise-tools' ),
			'kind'    => 'text',
			'text'    => post_unsigned_sentence( $post ),
		);
	}
	if ( current_user_can( 'edit_post', $id ) ) {
		array_unshift( $actions, array( 'label' => __( 'Open in editor', 'signal-and-noise-tools' ), 'variant' => 'primary', 'dispatch' => 'edit', 'args' => array( 'item' => (string) $id, 'title' => $title ) ) );
	}
	if ( 'publish' === $post->post_status ) {
		$actions[] = array( 'label' => __( 'View on site', 'signal-and-noise-tools' ), 'url' => get_permalink( $post ) );
	}
	$publish_cap = post_publish_cap( (string) $post->post_type );
	return array(
		'id'          => (string) $id,
		'title'       => $title,
		'subtitle'    => $slabel . ' · ' . get_the_date( '', $post ),
		'thumbnail'   => (string) get_the_post_thumbnail_url( $post, 'medium' ),
		'icon'        => post_item_icon( (string) $post->post_type ),
		'status'      => (string) $post->post_status,
		'statusLabel' => $slabel,
		'date'        => (string) get_post_time( 'c', true, $post ),
		'dateLabel'   => get_the_date( '', $post ),
		'badge'       => $badge,
		// The rights ride the ITEM, the way WP Explorer's do: the menu greys a
		// row it may not run, and the server re-checks every one of them.
		'canEdit'     => current_user_can( 'edit_post', $id ),
		'canDelete'   => current_user_can( 'delete_post', $id ),
		'canPublish'  => in_array( (string) $post->post_status, array( 'draft', 'pending' ), true )
			&& '' !== $publish_cap
			&& current_user_can( $publish_cap )
			&& current_user_can( 'edit_post', $id ),
		'unanchored'  => $unanchored,
		'link'        => (string) get_permalink( $post ),
		'columns'     => array(
			'versions' => $prov ? (string) (int) $prov['versions'] : '',
			'anchor'   => $prov ? \snt_os_app_anchor_badge( $prov['status'] )['label'] : '',
		),
		'detail'      => array(
			'hero'    => (string) get_the_post_thumbnail_url( $post, 'large' ),
			'facts'   => $facts,
			'blocks'  => $blocks,
			'actions' => $actions,
		),
	);
}

/**
 * Why this post has no signed chain, in its own type's words.
 *
 * @param \WP_Post|object $post Post.
 * @return string
 */
function post_unsigned_sentence( $post ) {
	if ( 'publish' === (string) $post->post_status ) {
		return __( 'No signed chain yet.', 'signal-and-noise-tools' );
	}
	return 'page' === (string) $post->post_type
		? __( 'A page is signed when it is published.', 'signal-and-noise-tools' )
		: __( 'A note is signed when it is published.', 'signal-and-noise-tools' );
}

/**
 * The editor URL for one of this section's posts, or '' when the user may not
 * edit it or the id belongs to another post type.
 *
 * @param string $id        Post id.
 * @param string $post_type The section's post type.
 * @return string
 */
function post_edit_url( $id, $post_type ) {
	$post = get_post( (int) $id );
	if ( ! $post || (string) $post_type !== (string) $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
		return '';
	}
	return (string) get_edit_post_link( $post->ID, 'raw' );
}
