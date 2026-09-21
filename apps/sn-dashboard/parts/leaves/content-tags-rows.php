<?php
/**
 * S&N Dashboard: Content → Tags, the Jev readings, the groups ledger and
 * the recent operations. Split from content-tags-parts.php (#1573) so each
 * file stays under three hundred lines; the forms and the clusters stay there.
 *
 * The two ledgers ("Jev: by tag", "Recent tag operations") take their rows
 * from inc/tag-consolidation-rows.php, which the classic twin paints too:
 * same columns, same cap, same "+N more" line on both surfaces.
 *
 * @package SignalNoiseTools
 * @since 17.4.1
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * 16.9.0: Jev tag fit. Replaces the Claude suggest (untagged notes only, a
 * proposal only). 16.9.2: Jev reads every note against the tags it carries
 * and lists the ones whose subject it could not find; it proposes none. The
 * rows are the allow-list sn_handle_tag_fit_apply() enforces; this only
 * paints them.
 *
 * @return string
 */
function tags_fit_html() {
	$heading = __( 'Jev: tag fit', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! \sn_jev_is_ready() ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'Install Connector for TypeSafe Jev and add the key under Settings › Connectors.', 'signal-and-noise-tools' ) ) . '</p>' );
	}
	$data = function_exists( 'sn_jev_tags_data' ) ? \sn_jev_tags_data() : null;
	$rows = null === $data ? array() : \sn_jev_tags_rows( $data );
	$run  = \snt_kit_form( 'tag_fit_run', '', array( 'submit' => __( 'Read tags now', 'signal-and-noise-tools' ) ) );
	if ( null === $data ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . \snt_kit_esc( __( 'Jev reads every published and scheduled note against the tags it carries, the tag descriptions as the state: a tag whose subject the note does not touch is listed for removal. About seventy requests; under a cent.', 'signal-and-noise-tools' ) ) . '</p>' . $run );
	}
	if ( array() === $rows ) {
		return \snt_kit_section( $heading, '<p class="snt-prose">' . sprintf( /* translators: %s: relative time element */ \snt_kit_esc( __( 'Last read %s: every tag on every note touches its subject.', 'signal-and-noise-tools' ) ), \snt_kit_relative_time( (int) $data['synced_at'] ) ) . '</p>' . $run );
	}
	$inner = '';
	foreach ( $rows as $pid => $row ) {
		$boxes = '';
		foreach ( $row['remove'] as $t ) {
			$boxes .= tags_check_row( 'remove[' . (int) $pid . '][]', (int) $t['id'], \snt_kit_esc( sprintf( /* translators: 1: tag, 2: score */ __( 'Remove "%1$s" (attached for reach, %2$s of 2)', 'signal-and-noise-tools' ), (string) $t['name'], number_format_i18n( (float) $t['score'], 2 ) ) ), false );
		}
		$inner .= '<p class="snt-prose"><strong><a href="' . esc_url( get_edit_post_link( (int) $pid ) ?: '' ) . '">' . \snt_kit_esc( $row['title'] ) . '</a></strong></p><ul class="snt-list">' . $boxes . '</ul>';
	}
	return \snt_kit_section(
		$heading,
		'<p class="snt-prose">' . sprintf( /* translators: 1: notes flagged, 2: relative time element */ \snt_kit_esc( __( '%1$d notes, read %2$s. A misfit is a tag whose subject Jev could not find in the note, scored under 0.5 of 2 with confidence 0.7 or better; nothing else is listed, and Jev proposes no tags. Jev read each tag\'s description: a wrong reading of a right tag is the description to fix. Tags are not prose; a published note can take the change.', 'signal-and-noise-tools' ) ), count( $rows ), \snt_kit_relative_time( (int) $data['synced_at'] ) ) . '</p>'
		. tags_form( 'post', tags_post_hidden( 'tag_fit_apply' ), $inner, __( 'Apply selected', 'signal-and-noise-tools' ) )
		. $run
	);
}

/**
 * 17.1.0: the pass pivoted per tag, what a reader of each archive gets.
 * 17.2.1: one summary line, then only the tags that carry notes which only
 * touch them. #1573: those tags as a LEDGER (snt_kit_table: Tag, Notes, Mean,
 * Only touching, the touching titles with score and confidence), thirty rows
 * then "+N more", full width; the paragraphs were a 1,329px column beside a
 * 238px box.
 *
 * @return string
 */
function tags_by_tag_html() {
	$heading = __( 'Jev: by tag', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! \sn_jev_is_ready() ) {
		return '';
	}
	$data = function_exists( 'sn_jev_tags_data' ) ? \sn_jev_tags_data() : null;
	if ( null === $data ) {
		return '';
	}
	$rows = \sn_jev_tags_by_tag( $data );
	if ( array() === $rows ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'No tags in the last pass.', 'signal-and-noise-tools' ) ) );
	}
	$wide    = array_values( array_filter( $rows, static fn( $r ) => array() !== $r['touching'] ) );
	$summary = sprintf( /* translators: 1: tags, 2: tags with touching notes, 3: their names */ __( '%1$d tags in the last pass; %2$d carry notes that only touch them: %3$s.', 'signal-and-noise-tools' ), count( $rows ), count( $wide ), $wide ? implode( ', ', array_column( $wide, 'name' ) ) : __( 'none', 'signal-and-noise-tools' ) );
	$inner   = '<p class="snt-prose">' . \snt_kit_esc( $summary ) . ' ' . \snt_kit_esc( __( 'A note under 1 of 2 touches the tag rather than being about it; which tags a note carries stays your call.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( $wide ) {
		$ledger = \sn_admin_tag_by_tag_rows( $wide );
		$inner .= tags_ledger_table( \sn_admin_tag_by_tag_columns(), $ledger['rows'] ) . \sn_admin_tag_more_line( $ledger['more'], 'snt-hint' );
	}
	return \snt_kit_section( $heading, $inner );
}

/**
 * 17.2.0: file tags under /notes/tags' headings from here, since the native
 * view never shows WordPress's own tag screen where the theme put its field
 * (theme 13.4.0). 17.2.1: a ledger, not a form. One line per heading naming
 * its tags, "Not yet filed" only when a tag is, and ONE small form (a tag,
 * a heading, File). Twenty-six selects were a scroll.
 *
 * @return string
 */
function tags_groups_html() {
	$heading = __( 'Groups on /notes/tags', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_notes_tag_groups' ) || ! function_exists( 'sn_notes_tag_group_effective' ) ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'The theme\'s tag groups are not available (Signal & Noise theme 13.4.0 or later).', 'signal-and-noise-tools' ) ) );
	}
	$ledger = tags_groups_ledger();
	if ( array() === $ledger['tags'] ) {
		return \snt_kit_section( $heading, \snt_kit_empty( __( 'No tags.', 'signal-and-noise-tools' ) ) );
	}
	$lines = '';
	foreach ( $ledger['groups'] as $g ) {
		$lines .= '<li><strong>' . \snt_kit_esc( $g['title'] ) . '</strong>: ' . \snt_kit_esc( $g['names'] ? implode( ', ', $g['names'] ) : __( 'nothing yet', 'signal-and-noise-tools' ) ) . '</li>';
	}
	if ( $ledger['unfiled'] ) {
		$lines .= '<li><strong>' . \snt_kit_esc( __( 'Not yet filed', 'signal-and-noise-tools' ) ) . '</strong>: ' . \snt_kit_esc( implode( ', ', $ledger['unfiled'] ) ) . '</li>';
	}
	$tag_opts = array();
	foreach ( $ledger['tags'] as $t ) {
		$tag_opts[ (string) $t['id'] ] = $t['name'];
	}
	$first  = (string) array_key_first( $tag_opts );
	$inner  = \snt_kit_field( 'select', 'file_tag', __( 'Tag', 'signal-and-noise-tools' ), $first, array( 'options' => $tag_opts ) )
		. \snt_kit_field( 'select', 'file_group', __( 'Heading', 'signal-and-noise-tools' ), '', array( 'options' => $ledger['options'] ) );
	$hidden = '';
	foreach ( tags_post_hidden( 'tag_group_apply' ) as $name => $value ) {
		$hidden .= \snt_kit_field( 'hidden', $name, '', $value );
	}
	$form = \snt_kit_tag(
		'os-form',
		array( 'class' => 'snt-form', 'os-action' => 'post', 'submit-label' => __( 'File', 'signal-and-noise-tools' ), 'show-reset' => 'false', 'columns' => '2' ),
		$inner . $hidden
	);
	return \snt_kit_section( $heading, '<ul class="snt-list">' . $lines . '</ul>' . $form );
}

/**
 * The ledger behind the Groups section: every tag with the heading it
 * renders under, grouped; the unfiled ones; the select options. PURE given
 * the theme's two functions and get_terms(). Shared by both surfaces.
 *
 * @return array{groups:array,unfiled:array,tags:array,options:array}
 */
function tags_groups_ledger() {
	// The theme owns these; the guard lives here so the builder stands alone.
	if ( ! function_exists( 'sn_notes_tag_groups' ) || ! function_exists( 'sn_notes_tag_group_effective' ) ) {
		return array( 'groups' => array(), 'unfiled' => array(), 'tags' => array(), 'options' => array() );
	}
	$options = array( '' => __( 'Not yet filed', 'signal-and-noise-tools' ) );
	$groups  = array();
	foreach ( \sn_notes_tag_groups() as $g ) {
		$title = html_entity_decode( (string) $g['title'], ENT_QUOTES, 'UTF-8' );
		$options[ (string) $g['id'] ] = $title;
		$groups[ (string) $g['id'] ] = array( 'title' => $title, 'names' => array() );
	}
	$terms   = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	$tags    = array();
	$unfiled = array();
	foreach ( (array) $terms as $t ) {
		if ( ! is_object( $t ) || ! isset( $t->term_id ) ) {
			continue;
		}
		$gid    = (string) \sn_notes_tag_group_effective( $t );
		$tags[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'group' => $gid );
		if ( isset( $groups[ $gid ] ) ) {
			$groups[ $gid ]['names'][] = (string) $t->name;
		} else {
			$unfiled[] = (string) $t->name;
		}
	}
	usort( $tags, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
	foreach ( $groups as &$g ) {
		sort( $g['names'], SORT_FLAG_CASE | SORT_STRING );
	}
	unset( $g );
	sort( $unfiled, SORT_FLAG_CASE | SORT_STRING );
	return array( 'groups' => array_values( $groups ), 'unfiled' => $unfiled, 'tags' => $tags, 'options' => $options );
}

/**
 * Recent merges and prunes, the last ten. #1573: a table (Operation, Tags:
 * the first five slugs then "+N"), "+N more" under it; the list was a
 * 1,603-character wall.
 *
 * @return string
 */
function tags_recent_html() {
	$hist = get_option( 'sn_tag_merge_history', array() );
	if ( ! is_array( $hist ) || ! $hist ) {
		return '';
	}
	$ledger = \sn_admin_tag_recent_rows( $hist );
	return \snt_kit_section(
		__( 'Recent tag operations', 'signal-and-noise-tools' ),
		tags_ledger_table( \sn_admin_tag_recent_columns(), $ledger['rows'] ) . \sn_admin_tag_more_line( $ledger['more'], 'snt-hint' )
	);
}

/**
 * A ledger's kit table from the shared key => label columns
 * (inc/tag-consolidation-rows.php); the classic twin paints the same rows
 * as a plain table.
 *
 * @param array<string,string>           $columns key => label.
 * @param array<int,array<string,mixed>> $rows    Row values keyed by column key.
 * @return string
 */
function tags_ledger_table( array $columns, array $rows ) {
	$cols = array();
	foreach ( $columns as $key => $label ) {
		$cols[] = array( 'key' => $key, 'label' => $label );
	}
	return \snt_kit_table( $cols, $rows );
}
