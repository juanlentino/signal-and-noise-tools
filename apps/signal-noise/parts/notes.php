<?php
/**
 * Signal & Noise app — the Notes section.
 *
 * The provenance-signed editorial surface: every post in the Notes category
 * (the `sn_prov_note_category` filter provenance-core.php reads), every
 * editable status, newest first. A tile carries the anchor status as a
 * badge; the dossier carries the signed commit chain, the ledger UID, the
 * editor as a window, the note on the site and the public verifier.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Every Note, newest first, each with its dossier inline.
 *
 * @return array<int,array<string,mixed>>
 */
function notes_items() {
	$query = new \WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'category_name'  => (string) apply_filters( 'sn_prov_note_category', 'notes' ),
			'posts_per_page' => (int) SN_OS_APP_ITEM_CAP,
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			'no_found_rows'  => true,
		)
	);
	$items = array();
	foreach ( (array) $query->posts as $post ) {
		$items[] = notes_item( $post );
	}
	return $items;
}

/**
 * How many Notes there are, for the root folder tile.
 *
 * @return int
 */
function notes_count() {
	$query = new \WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'category_name'  => (string) apply_filters( 'sn_prov_note_category', 'notes' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	return (int) $query->found_posts;
}

/**
 * One Note as the client sees it.
 *
 * @param \WP_Post $post Post.
 * @return array<string,mixed>
 */
function notes_item( $post ) {
	$id     = (int) $post->ID;
	$title  = '' !== $post->post_title ? (string) $post->post_title : __( '(no title)', 'signal-and-noise-tools' );
	$status = get_post_status_object( (string) $post->post_status );
	$slabel = $status && ! empty( $status->label ) ? (string) $status->label : (string) $post->post_status;
	$prov   = \snt_os_app_note_provenance( $id );
	$badge  = null;
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
			$blocks[]  = array( 'heading' => __( 'Ledger UID', 'signal-and-noise-tools' ), 'kind' => 'code', 'text' => (string) $prov['uid'] );
			$actions[] = array(
				'label' => __( 'Verify', 'signal-and-noise-tools' ),
				'url'   => add_query_arg( array( 'note' => (string) $prov['uid'], 'v' => (int) $prov['versions'] ), home_url( '/verify/' ) ),
			);
			if ( current_user_can( 'edit_post', $id ) ) {
				$actions[] = array( 'label' => __( 'Re-check now', 'signal-and-noise-tools' ), 'dispatch' => 'verify', 'args' => array( 'item' => (string) $id ) );
			}
		}
	} else {
		$blocks[] = array(
			'heading' => __( 'Provenance', 'signal-and-noise-tools' ),
			'kind'    => 'text',
			'text'    => 'publish' === $post->post_status
				? __( 'No signed chain yet.', 'signal-and-noise-tools' )
				: __( 'A note is signed when it is published.', 'signal-and-noise-tools' ),
		);
	}
	if ( current_user_can( 'edit_post', $id ) ) {
		array_unshift( $actions, array( 'label' => __( 'Open in editor', 'signal-and-noise-tools' ), 'variant' => 'primary', 'dispatch' => 'edit', 'args' => array( 'item' => (string) $id, 'title' => $title ) ) );
	}
	if ( 'publish' === $post->post_status ) {
		$actions[] = array( 'label' => __( 'View on site', 'signal-and-noise-tools' ), 'url' => get_permalink( $post ) );
	}
	return array(
		'id'          => (string) $id,
		'title'       => $title,
		'subtitle'    => $slabel . ' · ' . get_the_date( '', $post ),
		'thumbnail'   => (string) get_the_post_thumbnail_url( $post, 'medium' ),
		'icon'        => 'dashicons-edit-page',
		'status'      => (string) $post->post_status,
		'statusLabel' => $slabel,
		'date'        => (string) get_post_time( 'c', true, $post ),
		'dateLabel'   => get_the_date( '', $post ),
		'badge'       => $badge,
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
 * The editor URL for a Note, or '' when the user may not edit it.
 *
 * @param string $id Post id.
 * @return string
 */
function notes_edit_url( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
		return '';
	}
	return (string) get_edit_post_link( $post->ID, 'raw' );
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$sections[] = array(
			'id'             => 'notes',
			'label'          => __( 'Notes', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-edit-page',
			'kind'           => 'post',
			'capability'     => 'edit_posts',
			'position'       => 10,
			'statuses'       => array(
				array( 'value' => 'publish', 'label' => __( 'Published', 'signal-and-noise-tools' ) ),
				array( 'value' => 'future', 'label' => __( 'Scheduled', 'signal-and-noise-tools' ) ),
				array( 'value' => 'draft', 'label' => __( 'Drafts', 'signal-and-noise-tools' ) ),
				array( 'value' => 'pending', 'label' => __( 'Pending', 'signal-and-noise-tools' ) ),
				array( 'value' => 'private', 'label' => __( 'Private', 'signal-and-noise-tools' ) ),
			),
			'default_status' => 'publish',
			'columns'        => array(
				array( 'key' => 'versions', 'label' => __( 'Versions', 'signal-and-noise-tools' ) ),
				array( 'key' => 'anchor', 'label' => __( 'Anchor', 'signal-and-noise-tools' ) ),
			),
			'count'          => __NAMESPACE__ . '\notes_count',
			'items'          => __NAMESPACE__ . '\notes_items',
			'edit_url'       => __NAMESPACE__ . '\notes_edit_url',
		);
		return $sections;
	}
);
