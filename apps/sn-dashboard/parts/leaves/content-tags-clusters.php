<?php
/**
 * S&N Dashboard: Content > Tags, the duplicate clusters. Split from
 * content-tags-parts.php (2026-10-12) so each file stays near the house size.
 *
 * 2026-10-12 audit: N clusters were N "Possible duplicates" boxes handed to
 * snt_kit_grid() as ONE item, so the pair grid got N + 1 children on two
 * tracks and "Merge any two tags" sat beside a hole. Now there is one
 * "Duplicate tags" box holding every cluster under its own subheading, paired
 * with the picker; the classic twin paints the same box.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The one Duplicate tags box: every cluster, or the empty state.
 *
 * @param array $clusters From sn_tag_find_duplicate_clusters().
 * @return string
 */
function tags_duplicates_html( array $clusters ) {
	$inner = '';
	foreach ( $clusters as $c ) {
		$inner .= tags_cluster_html( (array) $c );
	}
	return \snt_kit_section(
		__( 'Duplicate tags', 'signal-and-noise-tools' ),
		'' !== $inner ? $inner : \snt_kit_empty( __( 'No duplicate tags detected.', 'signal-and-noise-tools' ) )
	);
}

/**
 * One cluster under its subheading (the suggested tag and how many fold into
 * it): canonical radio (defaulted to the suggested term), include checkbox,
 * name + slug, post count, and the GET "Preview merge" form.
 *
 * @param array $c Cluster: { key, terms:[{term_id,name,slug,count}], suggested }.
 * @return string
 */
function tags_cluster_html( array $c ) {
	// 17.9.0 (#1624): an os-table, not a <ul> with a header <li>. The radio,
	// the checkbox and the name+slug are slot cells (OpenStation 1.1.11), so
	// the inputs stay light-DOM descendants of the form: slot assignment moves
	// nothing, and FormData still reads every one, the radios still one group.
	$rows = array();
	foreach ( (array) ( $c['terms'] ?? array() ) as $t ) {
		$id     = (int) ( $t['term_id'] ?? 0 );
		$is_sug = $id === (int) ( $c['suggested'] ?? 0 );
		$name   = \sn_tag_name( $t['name'] ?? '' ); // stored escaped; the kit escapes once.
		$labels = \sn_tag_cluster_control_labels( $name );
		$rows[] = array(
			'_key'      => 'term-' . $id,
			// 2026-10-12 audit: each control names its tag; the row lives in the
			// table's shadow DOM, so "Canonical" alone was announced with no tag.
			'canonical' => array( 'html' => \snt_kit_tag( 'input', array( 'type' => 'radio', 'name' => 'sn_tag_into', 'value' => (string) $id, 'checked' => $is_sug, 'aria-label' => $labels[0] ) ), 'text' => '' ),
			'merge'     => array( 'html' => \snt_kit_tag( 'input', array( 'type' => 'checkbox', 'name' => 'sn_tag_from[]', 'value' => (string) $id, 'checked' => ! $is_sug, 'aria-label' => $labels[1] ) ), 'text' => '' ),
			'tag'       => array( 'html' => '<strong>' . \snt_kit_esc( $name ) . '</strong> ' . \snt_kit_code( (string) ( $t['slug'] ?? '' ), false ), 'text' => $name ),
			'posts'     => (int) ( $t['count'] ?? 0 ),
		);
	}
	$columns = array(
		array( 'key' => 'canonical', 'label' => __( 'Canonical', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
		array( 'key' => 'merge', 'label' => __( 'Merge?', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
		array( 'key' => 'tag', 'label' => __( 'Tag', 'signal-and-noise-tools' ), 'sortable' => true, 'stack' => 'title' ),
		array( 'key' => 'posts', 'label' => __( 'Posts', 'signal-and-noise-tools' ), 'sortable' => true, 'align' => 'end' ),
	);
	$inner = \snt_kit_table( $columns, $rows, array( 'stack_on_phone' => true, 'striped' => false ) );
	// The hint follows the submit button, as the classic markup prints it
	// (`<button>…</button> <span class="description">…</span>` in the same
	// <p>, tag-consolidation-admin.php) — not before it.
	$hint  = '<p class="snt-hint">' . \snt_kit_esc( __( 'Pick the canonical tag (radio) and which dupes to fold in (checkbox).', 'signal-and-noise-tools' ) ) . '</p>';
	return '<h4 class="snt-h">' . \snt_kit_esc( \sn_tag_cluster_heading( $c ) ) . '</h4>'
		. tags_form( 'get', tags_page_hidden(), $inner, __( 'Preview merge', 'signal-and-noise-tools' ) ) . $hint;
}
