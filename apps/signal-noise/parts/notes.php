<?php
/**
 * Signal & Noise app — the Notes section.
 *
 * The provenance-signed editorial surface: every post in the Notes category
 * (the same `sn_prov_note_category` filter provenance-core.php reads), any
 * editable status, newest first. A row wears its anchor status as a chip;
 * the dossier lists the signed commit chain (version, status, date, short
 * hash) and the ledger UID, and offers the editor as a window.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

use OpenStation\App\State;
use function OpenStation\App\Html\esc;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

const NOTES_PER_PAGE = 30;

/**
 * One list page of Notes.
 *
 * @param State $state Session state.
 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
 */
function notes_rows( State $state ) {
	$page  = max( 1, (int) $state->get( 'page' ) );
	$query = new \WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'category_name'  => (string) apply_filters( 'sn_prov_note_category', 'notes' ),
			's'              => (string) $state->get( 'query' ),
			'posts_per_page' => NOTES_PER_PAGE,
			'paged'          => $page,
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ), // ID tiebreak: equal dates must not reshuffle between pages.
		)
	);
	$items = array();
	foreach ( (array) $query->posts as $post ) {
		$prov = \snt_os_app_note_provenance( (int) $post->ID );
		$chip = null;
		if ( $prov ) {
			$chip          = \snt_os_app_anchor_chip( $prov['status'] );
			$chip['label'] = 'v' . (int) $prov['versions'] . ' · ' . $chip['label'];
		}
		$items[] = array(
			'id'        => (string) $post->ID,
			'title'     => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'signal-and-noise-tools' ),
			'subtitle'  => notes_subtitle( $post ),
			'thumbnail' => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
			'icon'      => 'dashicons-edit-page',
			'status'    => (string) $post->post_status,
			'chip'      => $chip,
		);
	}
	return array(
		'items'    => $items,
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => NOTES_PER_PAGE,
	);
}

/**
 * "Published · 14 Aug 2026" / "Draft · …".
 *
 * @param \WP_Post $post Post.
 * @return string
 */
function notes_subtitle( $post ) {
	$status = get_post_status_object( (string) $post->post_status );
	$label  = $status && ! empty( $status->label ) ? (string) $status->label : (string) $post->post_status;
	return $label . ' · ' . get_the_date( '', $post );
}

/**
 * A Note's dossier: the chain and the UID.
 *
 * @param string $id Post id.
 * @return array<string,mixed>|null
 */
function notes_dossier( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return null;
	}
	$prov   = \snt_os_app_note_provenance( (int) $post->ID );
	$blocks = array();
	if ( $prov ) {
		$rows = '';
		foreach ( array_reverse( $prov['commits'] ) as $commit ) {
			$c     = \snt_os_app_anchor_chip( $commit['status'] );
			$rows .= '<li class="snt-os__commit">'
				. '<span class="snt-os__commit-v">v' . (int) $commit['version'] . '</span>'
				. chip( $c )
				. '<span class="snt-os__commit-date">' . esc( substr( (string) $commit['committed_at'], 0, 10 ) ) . '</span>'
				. ( '' !== $commit['content_hash'] ? '<os-code class="snt-os__commit-hash" title="' . esc( $commit['content_hash'] ) . '">' . esc( substr( $commit['content_hash'], 0, 12 ) ) . '</os-code>' : '' )
				. '</li>';
		}
		$blocks[] = array(
			'heading' => sprintf( /* translators: %s: a count. */ _n( '%s signed version', '%s signed versions', (int) $prov['versions'], 'signal-and-noise-tools' ), number_format_i18n( (int) $prov['versions'] ) ),
			'html'    => '<ol class="snt-os__chain">' . $rows . '</ol>',
		);
		if ( ! empty( $prov['uid'] ) ) {
			$blocks[] = array(
				'heading' => __( 'Ledger UID', 'signal-and-noise-tools' ),
				'html'    => '<os-code wrap>' . esc( (string) $prov['uid'] ) . '</os-code>',
			);
		}
	} else {
		$blocks[] = array(
			'heading' => __( 'Provenance', 'signal-and-noise-tools' ),
			'html'    => '<p class="snt-os__muted">' . esc( __( 'No signed chain yet. The first publish creates it.', 'signal-and-noise-tools' ) ) . '</p>',
		);
	}
	$links = array();
	if ( 'publish' === $post->post_status ) {
		$links[] = array( 'label' => __( 'View on site', 'signal-and-noise-tools' ), 'url' => get_permalink( $post ) );
	}
	$edit = array();
	if ( current_user_can( 'edit_post', $post->ID ) ) {
		$edit = array(
			'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'title' => (string) $post->post_title,
			'label' => __( 'Edit note', 'signal-and-noise-tools' ),
		);
	}
	return array(
		'title'     => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'signal-and-noise-tools' ),
		'subtitle'  => notes_subtitle( $post ),
		'thumbnail' => '',
		'chips'     => $prov ? array( \snt_os_app_anchor_chip( $prov['status'] ) ) : array(),
		'blocks'    => $blocks,
		'links'     => $links,
		'edit'      => $edit,
	);
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$sections[] = array(
			'id'         => 'notes',
			'label'      => __( 'Notes', 'signal-and-noise-tools' ),
			'icon'       => 'dashicons-edit-page',
			'capability' => 'edit_posts',
			'position'   => 10,
			'rows'       => __NAMESPACE__ . '\notes_rows',
			'dossier'    => __NAMESPACE__ . '\notes_dossier',
			'empty'      => array(
				'heading'     => __( 'No notes match', 'signal-and-noise-tools' ),
				'description' => __( 'Notes are posts in the Notes category.', 'signal-and-noise-tools' ),
			),
		);
		return $sections;
	}
);
