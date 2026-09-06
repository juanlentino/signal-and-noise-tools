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
 * The items themselves are built by parts/post-items.php, shared with the
 * Pages section: this file is the section's QUERY and its descriptor.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The section's config for the shared post builder.
 *
 * @return array<string,mixed>
 */
function notes_cfg() {
	return array(
		'post_type'   => 'post',
		'statuses'    => POST_SECTION_STATUSES,
		'kind_filter' => (string) apply_filters( 'sn_prov_note_category', 'notes' ),
		'verify_link' => true,
	);
}

/**
 * Every Note, newest first, each with its dossier inline.
 *
 * @return array<int,array<string,mixed>>
 */
function notes_items() {
	return post_items( notes_cfg() );
}

/**
 * How many Notes there are, for the root folder tile.
 *
 * @return int
 */
function notes_count() {
	return post_count( notes_cfg() );
}

/**
 * The editor URL for a Note, or '' when the user may not edit it.
 *
 * @param string $id Post id.
 * @return string
 */
function notes_edit_url( $id ) {
	return post_edit_url( $id, 'post' );
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$sections[] = array(
			'id'             => 'notes',
			'label'          => __( 'Notes', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-edit-page',
			'kind'           => 'post',
			'post_type'      => 'post',
			'capability'     => 'edit_posts',
			// A row dragged out of this section becomes a `shortcut` the shell
			// resolves through the REST API -- the same path WP Explorer's
			// Posts section lifts with, so the Trash target already knows it.
			'restPath'       => 'wp/v2/posts',
			// The dossier is offered by DESCRIPTOR, never by section id: the
			// client asks `section.hasDossier`, so a section that has one says so.
			'hasDossier'     => true,
			'position'       => 10,
			'statuses'       => post_section_status_pills(),
			'default_status' => 'publish',
			'columns'        => post_section_columns(),
			'count'          => __NAMESPACE__ . '\notes_count',
			'items'          => __NAMESPACE__ . '\notes_items',
			'edit_url'       => __NAMESPACE__ . '\notes_edit_url',
		);
		return $sections;
	}
);
