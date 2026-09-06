<?php
/**
 * Signal & Noise app — the Pages section: the pages opted into signing.
 *
 * A page is a provenance subject ONLY when its author opted it in (the
 * `SN_PROV_SIGN_META` per-page meta, inc/provenance-core.php). The ledger is
 * public, append-only and Bitcoin-anchored, so signing pages wholesale would
 * ledger /verify and /stats -- surfaces whose text changes because a number
 * moved. This section lists exactly the opted-in set, whether or not a
 * version has been signed yet: a page is signed on publish, so an opted-in
 * draft honestly shows no chain rather than being hidden.
 *
 * Everything else -- the items, the dossier, the menu, the drag-out -- is
 * parts/post-items.php's, shared with Notes. The two differences are the
 * query predicate (the opt-in meta, never a category: `category` is a
 * post-only taxonomy) and the public verifier, which is not offered here.
 *
 * @package SignalNoiseTools
 * @since 13.102.0
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
function pages_cfg() {
	return array(
		'post_type'   => 'page',
		'statuses'    => POST_SECTION_STATUSES,
		'meta_key'    => defined( 'SN_PROV_SIGN_META' ) ? SN_PROV_SIGN_META : '_sn_prov_sign',
		'meta_value'  => '1',
		'verify_link' => false,
	);
}

/**
 * Every signed page, newest first, each with its dossier inline.
 *
 * @return array<int,array<string,mixed>>
 */
function pages_items() {
	return post_items( pages_cfg() );
}

/**
 * How many signed pages there are, for the root folder tile.
 *
 * @return int
 */
function pages_count() {
	return post_count( pages_cfg() );
}

/**
 * The editor URL for a signed page, or '' when the user may not edit it.
 *
 * @param string $id Post id.
 * @return string
 */
function pages_edit_url( $id ) {
	return post_edit_url( $id, 'page' );
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$sections[] = array(
			'id'             => 'pages',
			'label'          => __( 'Pages', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-admin-page',
			'kind'           => 'post',
			'post_type'      => 'page',
			'capability'     => 'edit_pages',
			// A row dragged out becomes a `shortcut` the shell resolves through
			// the REST API -- the same path WP Explorer's Pages section lifts
			// with, so the Trash target already knows it.
			'restPath'       => 'wp/v2/pages',
			// The dossier is offered by DESCRIPTOR, never by section id: the
			// client asks `section.hasDossier`, so a section that has one says so.
			'hasDossier'     => true,
			'position'       => 12,
			'statuses'       => post_section_status_pills(),
			'default_status' => 'publish',
			'columns'        => post_section_columns(),
			'count'          => __NAMESPACE__ . '\pages_count',
			'items'          => __NAMESPACE__ . '\pages_items',
			'edit_url'       => __NAMESPACE__ . '\pages_edit_url',
		);
		return $sections;
	}
);
