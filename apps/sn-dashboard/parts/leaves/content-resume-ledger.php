<?php
/**
 * S&N Dashboard, Content > Resume Page: the ledger and the one edit region.
 *
 * 2026-10-11, owner rule: no collapsible sections. The classic editor's nine
 * folds are ONE table here: a row per section with its entry count, what it
 * opens with and a visible Edit button, under a finding built from the
 * document. `sn_edit=<section>` paints that section alone under its row
 * (2026-10-12, snt_kit_edit_list()), in the resume_draft_save form, with the
 * other eight riding along as hidden carriers (content-resume-edit.php),
 * because the handler rebuilds the whole document from the post. With no
 * section being edited no editor is painted: the sections are fixed (there is
 * no add form to open), and a long form would push the Draft and PDF controls
 * out of sight.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The finding: how many entries the seven lists hold, and how deep
 * Experience runs.
 *
 * @param array $sections resume_section_map().
 * @param array $doc      The document.
 * @return string
 */
function resume_finding( array $sections, array $doc ) {
	$total = 0;
	$lists = 0;
	foreach ( $sections as $section ) {
		if ( null !== $section['count'] ) {
			$total += (int) $section['count'];
			++$lists;
		}
	}
	$employers = (array) ( $doc['experience'] ?? array() );
	$roles     = 0;
	foreach ( $employers as $employer ) {
		$roles += count( (array) ( ( (array) $employer )['roles'] ?? array() ) );
	}
	return sprintf(
		/* translators: 1: entries ("24 entries"), 2: number of lists, 3: employers ("2 employers"), 4: roles ("3 roles") */
		__( '%1$s across %2$s lists; Experience holds %3$s with %4$s.', 'signal-and-noise-tools' ),
		/* translators: %s: number of entries */
		sprintf( _n( '%s entry', '%s entries', $total, 'signal-and-noise-tools' ), number_format_i18n( $total ) ),
		number_format_i18n( $lists ),
		/* translators: %s: number of employers */
		sprintf( _n( '%s employer', '%s employers', count( $employers ), 'signal-and-noise-tools' ), number_format_i18n( count( $employers ) ) ),
		/* translators: %s: number of roles */
		sprintf( _n( '%s role', '%s roles', $roles, 'signal-and-noise-tools' ), number_format_i18n( $roles ) )
	);
}

/**
 * The ledger of the nine sections, as snt_kit_edit_list() takes it: the
 * finding as the lead, ONE table, the open section's row index. Never
 * capped: the sections are fixed.
 *
 * @param array  $sections resume_section_map().
 * @param array  $doc      The document.
 * @param string $editing  The open section, '' for none.
 * @return array<string,mixed>
 */
function resume_ledger( array $sections, array $doc, $editing = '' ) {
	$rows = array();
	$at   = -1;
	foreach ( $sections as $key => $section ) {
		$at     = (string) $key === (string) $editing ? count( $rows ) : $at;
		$rows[] = array(
			'_key'    => $key,
			'section' => $section['title'],
			'entries' => null === $section['count'] ? '' : number_format_i18n( $section['count'] ),
			'first'   => resume_clip( $section['first'] ),
			'edit'    => array( 'html' => resume_edit_button( 'resume', $key, (string) $key === (string) $editing ), 'text' => '' ),
		);
	}
	return array(
		'lead'    => '<p class="snt-prose">' . \snt_kit_esc( resume_finding( $sections, $doc ) ) . '</p>',
		'columns' => array(
			array( 'key' => 'section', 'label' => __( 'Section', 'signal-and-noise-tools' ), 'stack' => 'title' ),
			array( 'key' => 'entries', 'label' => __( 'Entries', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'first', 'label' => __( 'Opens with', 'signal-and-noise-tools' ) ),
			array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
		),
		'rows'    => $rows,
		'at'      => $at,
		'table'   => array( 'striped' => false ),
	);
}

/**
 * The one edit region: the section's fields (its repeater, Add, Remove and
 * reorder intact), its helper line as the description, Save draft and
 * Cancel in one action row, the other sections as hidden carriers.
 *
 * @param array  $sections resume_section_map().
 * @param string $key      The section being edited.
 * @return string
 */
function resume_edit_region( array $sections, $key ) {
	$section = $sections[ $key ];
	$form    = \snt_kit_form(
		'resume_draft_save',
		(string) call_user_func( $section['paint'] ) . resume_edit_cancel( 'resume', $key ),
		array(
			'submit' => __( 'Save draft', 'signal-and-noise-tools' ),
			'hidden' => resume_carry_others( $sections, $key ),
		)
	);
	return \snt_kit_section( $section['title'], $form, $section['hint'], array( 'id' => 'snt-edit-region', 'tabindex' => '-1', 'data-snt-focus' => true ) );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_resume( array $ctx ) {
	$live = function_exists( 'sn_resume_doc_get_raw' ) ? \sn_resume_doc_get_raw() : null; // the stored document, variant flags included.
	if ( ! is_array( $live ) ) {
		return \snt_kit_empty(
			__( 'Resume page', 'signal-and-noise-tools' ),
			__( 'The resume editor is unavailable: no stored document and no readable seed.', 'signal-and-noise-tools' ),
			'media-document'
		);
	}
	// The editor paints the draft when there is one: that is what Save stores.
	$draft    = function_exists( 'sn_resume_draft_get' ) ? \sn_resume_draft_get() : null;
	$doc      = is_array( $draft ) ? $draft : $live;
	$sections = resume_section_map( $doc );
	$editing  = resume_edit_key( $ctx, array_keys( $sections ) );
	// 2026-10-12: the section's editor opens under its row (snt_kit_edit_list()).
	$list = resume_ledger( $sections, $doc, $editing );
	$list = array(
		'heading' => __( 'Resume page', 'signal-and-noise-tools' ),
		'lead'    => resume_es_stale_notice() . resume_intro( $live ) . $list['lead'],
		'attrs'   => array( 'id' => 'snt-edit-list', 'tabindex' => '-1', 'data-snt-focus' => true ),
	) + $list;
	// 2026-10-12 audit: Draft and Resume PDF are both short; they share a row.
	return \snt_kit_edit_list( $list, '' !== $editing ? resume_edit_region( $sections, $editing ) : '' )
		. \snt_kit_grid( array( resume_draft_controls(), resume_pdf_generate() ) );
}

/**
 * The stale-CV alert (inc/resume-es-stale.php): '' when /cv/ is current.
 *
 * @return string
 */
function resume_es_stale_notice() {
	$html = function_exists( 'sn_resume_es_stale' ) ? \sn_resume_es_stale_html( \sn_resume_es_stale() ) : '';
	return '' === $html ? '' : \snt_kit_notice( 'warn', $html );
}
