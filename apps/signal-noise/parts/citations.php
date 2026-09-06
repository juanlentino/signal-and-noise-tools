<?php
/**
 * Signal & Noise app — the Citations section.
 *
 * The verified citation graph (inc/citations-store.php): one entry per
 * (source, target) pair, newest claim first, wearing its tier as the status
 * the pills filter on and as a badge whose tone comes from the admin kit's
 * own pill vocabulary through sn_note_dossier_tone().
 *
 * READ-ONLY BY CONSTRUCTION. The graph is measured by the verifier, never
 * edited by hand, so the descriptor declares `kind: entry` and carries no
 * `restPath`, no `edit_url` and no `hasDossier` — the client's three opt-ins,
 * all declined. That is what keeps the selection actions, the drag lift and
 * the dossier fetch off this section: not a special case in the client, the
 * absence of the fields each of them reads.
 *
 * It registers ALWAYS, unlike Discography. A citation table with nothing in it
 * is a measured zero — nobody has cited the site yet — and that is a reading
 * worth a folder, not an empty shelf to hide.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationApp;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The ladder, or an empty list when the citations half is not loaded.
 *
 * @return array<int,string>
 */
function citation_tiers() {
	return defined( 'SN_CIT_TIERS' ) ? array_values( (array) \SN_CIT_TIERS ) : array();
}

/**
 * A tier as a pill reads it: the ladder's own word, capitalised. One helper so
 * the descriptor's pills and an item's statusLabel can never disagree.
 *
 * @param string $tier One of SN_CIT_TIERS.
 * @return string
 */
function citation_tier_label( $tier ) {
	return ucfirst( (string) $tier );
}

/**
 * The door to Integrity → Citations, or '' when the admin dock is not loaded.
 *
 * @return string
 */
function citations_door() {
	return function_exists( 'snt_desktop_admin_url' ) ? (string) \snt_desktop_admin_url( 'sn-tools', 'citations' ) : '';
}

/**
 * Every citation the section shows, newest claim first.
 *
 * @return array<int,array<string,mixed>>
 */
function citations_items() {
	if ( ! function_exists( 'sn_cit_all' ) ) {
		return array();
	}
	$items = array();
	foreach ( (array) \sn_cit_all( (int) SN_OS_APP_ITEM_CAP ) as $row ) {
		if ( is_object( $row ) ) {
			$items[] = citation_item( $row );
		}
	}
	return $items;
}

/**
 * How many claims there are, for the root folder tile.
 *
 * The four tiers, and only the four: sn_cit_counts() also reports
 * `never_checked`, which is a second reading of rows already counted in a
 * tier, not a fifth bucket. Summing it would count part of the table twice.
 *
 * @return int
 */
function citations_count() {
	if ( ! function_exists( 'sn_cit_counts' ) ) {
		return 0;
	}
	$counts = (array) \sn_cit_counts();
	$total  = 0;
	foreach ( citation_tiers() as $tier ) {
		$total += (int) ( $counts[ $tier ] ?? 0 );
	}
	return $total;
}

/**
 * One citation as the client sees it.
 *
 * @param object $row A row of the citations table.
 * @return array<string,mixed>
 */
function citation_item( $row ) {
	$tier    = (string) ( $row->tier ?? '' );
	$source  = (string) ( $row->source_url ?? '' );
	$host    = (string) wp_parse_url( $source, PHP_URL_HOST );
	$title   = (string) ( $row->source_title ?? '' );
	if ( '' === $title ) {
		// The host, then the raw URL: sn_cit_render_row()'s ladder, so a row
		// reads the same in the window as it does in the leaf.
		$title = '' !== $host ? $host : $source;
	}
	$target_id  = (int) ( $row->target_post_id ?? 0 );
	$target_url = (string) ( $row->target_url ?? '' );
	$target     = $target_id > 0 && function_exists( 'get_the_title' ) ? (string) get_the_title( $target_id ) : '';
	$checked    = (string) ( $row->last_checked_gmt ?? '' );
	$ago        = function_exists( 'sn_cit_ago_label' ) ? (string) \sn_cit_ago_label( '' !== $checked ? $checked : null ) : $checked;
	// 0 means no response arrived at all, which is not the same answer as a 404.
	$code = (int) ( $row->last_status ?? 0 ) > 0 ? (string) (int) $row->last_status : __( 'no response', 'signal-and-noise-tools' );
	$kind = function_exists( 'sn_cit_tier_pill_kind' ) ? (string) \sn_cit_tier_pill_kind( $tier ) : '';
	$tone = function_exists( 'sn_note_dossier_tone' ) ? (string) \sn_note_dossier_tone( $kind ) : 'neutral';

	$actions = array();
	$door    = citations_door();
	if ( '' !== $door ) {
		$actions[] = array( 'label' => __( 'Open Citations in S&N Dashboard', 'signal-and-noise-tools' ), 'url' => $door );
	}
	if ( $target_id > 0 ) {
		$actions[] = array( 'label' => __( 'View the note', 'signal-and-noise-tools' ), 'url' => (string) get_permalink( $target_id ) );
	}

	return array(
		'id'          => 'c' . (string) ( $row->id ?? '' ),
		'title'       => $title,
		'subtitle'    => '' !== $target ? $target : __( 'unresolved target', 'signal-and-noise-tools' ),
		'thumbnail'   => '',
		'icon'        => 'dashicons-admin-links',
		'status'      => $tier,
		'statusLabel' => citation_tier_label( $tier ),
		'date'        => $checked,
		'dateLabel'   => $ago,
		'badge'       => array(
			'text'  => $tier,
			'tone'  => $tone,
			'title' => function_exists( 'sn_cit_tier_gloss' ) ? (string) \sn_cit_tier_gloss( $tier ) : '',
		),
		'columns'     => array(
			'tier'    => $tier,
			'target'  => '' !== $target ? $target : $target_url,
			'checked' => $ago,
			'status'  => $code,
		),
		'detail'      => array(
			// No hero: a citation is a pair of URLs, and `hero` is an <img src>.
			'hero'    => '',
			'facts'   => array(
				array( __( 'Source', 'signal-and-noise-tools' ), $source ),
				array( __( 'Target', 'signal-and-noise-tools' ), '' !== $target ? $target : $target_url ),
				array( __( 'Tier', 'signal-and-noise-tools' ), function_exists( 'sn_cit_tier_sentence' ) ? (string) \sn_cit_tier_sentence( $tier ) : $tier ),
				array( __( 'Last checked', 'signal-and-noise-tools' ), $ago ),
				array( __( 'Last status', 'signal-and-noise-tools' ), $code ),
			),
			'blocks'  => array(),
			'actions' => $actions,
		),
	);
}

add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		$statuses = array();
		foreach ( citation_tiers() as $tier ) {
			$statuses[] = array( 'value' => $tier, 'label' => citation_tier_label( $tier ) );
		}
		$sections[] = array(
			'id'             => 'citations',
			'label'          => __( 'Citations', 'signal-and-noise-tools' ),
			'icon'           => 'dashicons-admin-links',
			'kind'           => 'entry',
			'capability'     => 'manage_options',
			'position'       => 30,
			'statuses'       => $statuses,
			'default_status' => '',
			'columns'        => array(
				array( 'key' => 'tier', 'label' => __( 'Tier', 'signal-and-noise-tools' ) ),
				array( 'key' => 'target', 'label' => __( 'Target', 'signal-and-noise-tools' ) ),
				array( 'key' => 'checked', 'label' => __( 'Last checked', 'signal-and-noise-tools' ) ),
			),
			'count'          => __NAMESPACE__ . '\citations_count',
			'items'          => __NAMESPACE__ . '\citations_items',
		);
		return $sections;
	}
);
