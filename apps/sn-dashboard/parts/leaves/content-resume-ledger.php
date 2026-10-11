<?php
/**
 * S&N Dashboard, Content > Resume Page: the ledger and the one edit region.
 *
 * 2026-10-11, owner rule: no collapsible sections. The classic editor's nine
 * folds are ONE table here: a row per section with its entry count, what it
 * opens with and a visible Edit button, under a finding built from the
 * document. `sn_edit=<section>` paints that section alone below the table, in
 * the resume_draft_save form, with the other eight riding along as hidden
 * carriers (content-resume-edit.php), because the handler rebuilds the whole
 * document from the post. With no section being edited nothing is painted
 * below the table: the sections are fixed (there is no add form to open), and
 * a long form there would push the Draft and PDF controls out of sight.
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
 * The finding over ONE table of the nine sections. Never capped: the
 * sections are fixed.
 *
 * @param array $sections resume_section_map().
 * @param array $doc      The document.
 * @return string
 */
function resume_ledger( array $sections, array $doc ) {
	$rows = array();
	foreach ( $sections as $key => $section ) {
		$rows[] = array(
			'_key'    => $key,
			'section' => $section['title'],
			'entries' => null === $section['count'] ? '' : number_format_i18n( $section['count'] ),
			'first'   => resume_clip( $section['first'] ),
			'edit'    => array( 'html' => resume_edit_button( 'resume', $key ), 'text' => '' ),
		);
	}
	$columns = array(
		array( 'key' => 'section', 'label' => __( 'Section', 'signal-and-noise-tools' ), 'stack' => 'title' ),
		array( 'key' => 'entries', 'label' => __( 'Entries', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'first', 'label' => __( 'Opens with', 'signal-and-noise-tools' ) ),
		array( 'key' => 'edit', 'label' => __( 'Edit', 'signal-and-noise-tools' ), 'stack' => 'actions' ),
	);
	return \snt_kit_ledger( resume_finding( $sections, $doc ), $columns, $rows, count( $rows ), array( 'stack_on_phone' => true, 'striped' => false ) );
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
		(string) call_user_func( $section['paint'] ) . resume_edit_cancel( 'resume' ),
		array(
			'submit' => __( 'Save draft', 'signal-and-noise-tools' ),
			'hidden' => resume_carry_others( $sections, $key ),
		)
	);
	return \snt_kit_section( $section['title'], $form, $section['hint'] );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_resume( array $ctx ) {
	$live = function_exists( 'sn_resume_doc_get' ) ? \sn_resume_doc_get() : null;
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
	return \snt_kit_section(
		__( 'Resume page', 'signal-and-noise-tools' ),
		resume_intro( $live ) . resume_draft_status() . resume_ledger( $sections, $doc )
	)
		. ( '' !== $editing ? resume_edit_region( $sections, $editing ) : '' )
		. resume_draft_controls() . resume_pdf_generate();
}
