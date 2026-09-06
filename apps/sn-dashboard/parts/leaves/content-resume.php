<?php
/**
 * S&N Dashboard — Content → Resume Page, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/resume-page.php,
 * `sn_admin_render_resume_section()`) is the STRUCTURED editor for /resume:
 * one form (`sn_action=resume_save`), eight collapsed sections (Hero, Stats,
 * Experience, Earlier career, Education, Affiliations, Publications, Skills)
 * of real fields and repeatable rows, one Save button, and a hard failure
 * state when sn_resume_doc_get() has neither a stored document nor a
 * readable seed. Same reader, same names, same handler; the kit's parts
 * instead of wp-admin's. Row painters: content-resume-parts.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/content-resume-parts.php';

/**
 * One collapsed section — sn_rsm_section_open(): heading, row-count badge
 * (the fold's hint), helper line, body. Closed by default, as the classic
 * `<details>`; a closed fold's fields still submit with the form.
 *
 * @param string $title Section title.
 * @param string $hint  Helper line shown when open ('' for none).
 * @param int    $count Row count for the summary (-1 = none).
 * @param string $inner Painted body.
 * @return string
 */
function resume_section( $title, $hint, $count, $inner ) {
	$helper = '' !== $hint ? '<p class="snt-hint">' . \snt_kit_esc( $hint ) . '</p>' : '';
	return \snt_kit_tag(
		'os-disclosure',
		array(
			'heading' => (string) $title,
			'hint'    => $count >= 0 ? (string) (int) $count : null,
		),
		$helper . $inner
	);
}

/**
 * The intro: which page this edits, and whether the form has taken it over.
 *
 * @param array $doc The document.
 * @return string
 */
function resume_intro( array $doc ) {
	$link = \snt_kit_link( '/resume', home_url( '/resume' ) );
	if ( '' !== (string) ( $doc['updated'] ?? '' ) ) {
		return '<p class="snt-prose">'
			. sprintf( /* translators: %s: the /resume link */ \snt_kit_esc( __( 'This form is the editor for the live %s page. Saving regenerates it.', 'signal-and-noise-tools' ) ), $link )
			. ' ' . \snt_kit_esc( __( 'Last saved:', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( (string) $doc['updated'], false ) . '.'
			. '</p>';
	}
	return '<p class="snt-prose">'
		. sprintf( /* translators: %s: the /resume link */ \snt_kit_esc( __( 'This form is the editor for the live %s page, prefilled from the current published content. The first save takes over the page body: from then on this form is the canonical editor.', 'signal-and-noise-tools' ) ), $link )
		. '</p>';
}

/**
 * The Hero section: summary, chips, contact/LinkedIn, PDF URL/label.
 *
 * @param array $hero The hero block.
 * @return string
 */
function resume_hero( array $hero ) {
	return \snt_kit_field( 'textarea', 'resume[hero][summary]', __( 'Summary', 'signal-and-noise-tools' ), (string) ( $hero['summary'] ?? '' ), array( 'rows' => 3 ) )
		. resume_lines( 'resume[hero][chips]', $hero['chips'] ?? array(), __( 'Credential chips: one per line', 'signal-and-noise-tools' ), __( 'Credential or membership', 'signal-and-noise-tools' ), 4 )
		. resume_pair(
			resume_text( 'resume[hero][contact_line]', __( 'Contact line', 'signal-and-noise-tools' ), $hero['contact_line'] ?? '', 'City, State' ),
			resume_text( 'resume[hero][linkedin]', __( 'LinkedIn URL', 'signal-and-noise-tools' ), $hero['linkedin'] ?? '', 'https://www.linkedin.com/in/…' )
		)
		. resume_pair(
			resume_text( 'resume[hero][pdf_url]', __( 'PDF URL', 'signal-and-noise-tools' ), $hero['pdf_url'] ?? '', 'https://…/Resume.pdf' ),
			resume_text( 'resume[hero][pdf_label]', __( 'PDF link label', 'signal-and-noise-tools' ), $hero['pdf_label'] ?? '', 'Name · Resume (PDF)' )
		);
}

/**
 * The eight sections, in the classic order.
 *
 * @param array $doc The document.
 * @return string
 */
function resume_sections( array $doc ) {
	$stats   = (array) ( $doc['stats'] ?? array() );
	$exp     = (array) ( $doc['experience'] ?? array() );
	$earlier = (array) ( $doc['earlier'] ?? array() );
	$entries = (array) ( $earlier['entries'] ?? array() );
	$edu     = (array) ( $doc['education'] ?? array() );
	$aff     = (array) ( $doc['affiliations'] ?? array() );
	$pubs    = (array) ( $doc['publications'] ?? array() );
	$skills  = (array) ( $doc['skills'] ?? array() );
	$ns      = __NAMESPACE__;

	$employer = static function ( $prefix, array $entry ) { return resume_employer_card( $prefix, '__R__', $entry, true ); };
	$early    = static function ( $prefix, array $entry ) { return resume_employer_card( $prefix, '__Y__', $entry, false ); };
	$blank_employer = array( 'roles' => array( array() ) );

	return resume_section( __( 'Hero', 'signal-and-noise-tools' ), __( 'The opening band: summary, credential chips, contact line, and the PDF download.', 'signal-and-noise-tools' ), -1, resume_hero( (array) ( $doc['hero'] ?? array() ) ) )
		. resume_section( __( 'Stats', 'signal-and-noise-tools' ), __( 'The numbers strip under the hero.', 'signal-and-noise-tools' ), count( $stats ), resume_list( $stats, $ns . '\resume_stat_row', 'resume[stats]', '__S__', __( '+ Add stat', 'signal-and-noise-tools' ) ) )
		. resume_section( __( 'Experience', 'signal-and-noise-tools' ), __( 'One card per employer; each holds one or more roles with their bullets. Bullets may use <strong>, <em>, and links.', 'signal-and-noise-tools' ), count( $exp ), resume_list( $exp, $employer, 'resume[experience]', '__E__', __( '+ Add employer', 'signal-and-noise-tools' ), $blank_employer ) )
		. resume_section(
			__( 'Earlier career (collapsed fold)', 'signal-and-noise-tools' ),
			__( 'Rendered inside a collapsed "details" fold at the end of Experience.', 'signal-and-noise-tools' ),
			count( $entries ),
			resume_text( 'resume[earlier][label]', __( 'Fold label', 'signal-and-noise-tools' ), $earlier['label'] ?? '', 'Earlier career · 1997 - 2015' )
			. resume_list( $entries, $early, 'resume[earlier][entries]', '__X__', __( '+ Add earlier employer', 'signal-and-noise-tools' ), $blank_employer )
		)
		. resume_section( __( 'Education', 'signal-and-noise-tools' ), '', count( $edu ), resume_list( $edu, $ns . '\resume_titled_lines_row', 'resume[education]', '__D__', __( '+ Add education', 'signal-and-noise-tools' ) ) )
		. resume_section( __( 'Affiliations & Certifications', 'signal-and-noise-tools' ), '', count( $aff ), resume_list( $aff, $ns . '\resume_titled_lines_row', 'resume[affiliations]', '__A__', __( '+ Add affiliation', 'signal-and-noise-tools' ) ) )
		. resume_section( __( 'Publications', 'signal-and-noise-tools' ), __( 'A new paper is one row: venue line, title, and link.', 'signal-and-noise-tools' ), count( $pubs ), resume_list( $pubs, $ns . '\resume_publication_row', 'resume[publications]', '__P__', __( '+ Add publication', 'signal-and-noise-tools' ) ) )
		. resume_section( __( 'Skills', 'signal-and-noise-tools' ), __( 'One table row per category; items is the comma-separated cell.', 'signal-and-noise-tools' ), count( $skills ), resume_list( $skills, $ns . '\resume_skills_row', 'resume[skills]', '__K__', __( '+ Add skills row', 'signal-and-noise-tools' ) ) );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_content_resume( array $ctx ) {
	unset( $ctx );
	$doc = function_exists( 'sn_resume_doc_get' ) ? \sn_resume_doc_get() : null;
	if ( ! is_array( $doc ) ) {
		return \snt_kit_empty(
			__( 'Resume page', 'signal-and-noise-tools' ),
			__( 'The resume editor is unavailable: no stored document and no readable seed.', 'signal-and-noise-tools' ),
			'media-document'
		);
	}
	return \snt_kit_section(
		__( 'Resume page', 'signal-and-noise-tools' ),
		resume_intro( $doc ) . \snt_kit_form( 'resume_save', resume_sections( $doc ), array( 'submit' => __( 'Save resume', 'signal-and-noise-tools' ) ) )
	);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['content/resume'] = __NAMESPACE__ . '\\paint_content_resume';
		return $painters;
	}
);
