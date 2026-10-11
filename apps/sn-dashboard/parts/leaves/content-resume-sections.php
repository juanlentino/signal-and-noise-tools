<?php
/**
 * S&N Dashboard, Content > Resume Page: the nine sections.
 *
 * Each section is a title, its helper line, its entry count, what it opens
 * with, and the painter of its fields, in the classic order. The ledger
 * (content-resume-ledger.php) lists them; the edit region paints one of them
 * as fields and the other eight as hidden carriers (content-resume-edit.php).
 * Row painters: content-resume-parts.php; the Hero and PDF-only field groups:
 * content-resume.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A list's first row's field, '' when the list is empty.
 *
 * @param array  $rows  Rows.
 * @param string $field Field.
 * @return string
 */
function resume_first( array $rows, $field ) {
	$row = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
	return is_scalar( $row[ $field ] ?? null ) ? (string) $row[ $field ] : '';
}

/**
 * The nine sections, in the classic order. `count` is null for the two
 * blocks of single fields (Hero, PDF only), which the classic summary never
 * counted either.
 *
 * @param array $doc The document.
 * @return array<string,array{title:string,hint:string,count:?int,first:string,paint:callable}>
 */
function resume_section_map( array $doc ) {
	$hero    = (array) ( $doc['hero'] ?? array() );
	$stats   = (array) ( $doc['stats'] ?? array() );
	$exp     = (array) ( $doc['experience'] ?? array() );
	$earlier = (array) ( $doc['earlier'] ?? array() );
	$entries = (array) ( $earlier['entries'] ?? array() );
	$edu     = (array) ( $doc['education'] ?? array() );
	$aff     = (array) ( $doc['affiliations'] ?? array() );
	$pubs    = (array) ( $doc['publications'] ?? array() );
	$skills  = (array) ( $doc['skills'] ?? array() );
	$pdf     = (array) ( $doc['pdf'] ?? array() );
	$ns      = __NAMESPACE__;
	$blank   = array( 'roles' => array( array() ) );

	return array(
		'hero'         => array(
			'title' => __( 'Hero', 'signal-and-noise-tools' ),
			'hint'  => __( 'The opening band: summary, credential chips, contact line, and the PDF download.', 'signal-and-noise-tools' ),
			'count' => null,
			'first' => (string) ( $hero['summary'] ?? '' ),
			'paint' => static function () use ( $hero ) { return resume_hero( $hero ); },
		),
		'stats'        => array(
			'title' => __( 'Stats', 'signal-and-noise-tools' ),
			'hint'  => __( 'The numbers strip under the hero.', 'signal-and-noise-tools' ),
			'count' => count( $stats ),
			'first' => trim( resume_first( $stats, 'n' ) . ' ' . resume_first( $stats, 'label' ) ),
			'paint' => static function () use ( $stats, $ns ) { return resume_list( $stats, $ns . '\resume_stat_row', 'resume[stats]', '__S__', __( '+ Add stat', 'signal-and-noise-tools' ), __( 'stat', 'signal-and-noise-tools' ) ); },
		),
		'experience'   => array(
			'title' => __( 'Experience', 'signal-and-noise-tools' ),
			'hint'  => __( 'One card per employer; each holds one or more roles with their bullets. Bullets may use <strong>, <em>, and links.', 'signal-and-noise-tools' ),
			'count' => count( $exp ),
			'first' => resume_first( $exp, 'org' ),
			'paint' => static function () use ( $exp, $blank ) {
				$card = static function ( $prefix, array $entry ) { return resume_employer_card( $prefix, '__R__', $entry, true ); };
				return resume_list( $exp, $card, 'resume[experience]', '__E__', __( '+ Add employer', 'signal-and-noise-tools' ), __( 'employer', 'signal-and-noise-tools' ), $blank );
			},
		),
		'earlier'      => array(
			'title' => __( 'Earlier career', 'signal-and-noise-tools' ),
			'hint'  => __( 'Rendered inside a collapsed "details" fold at the end of Experience.', 'signal-and-noise-tools' ),
			'count' => count( $entries ),
			'first' => resume_first( $entries, 'org' ),
			'paint' => static function () use ( $earlier, $entries, $blank ) {
				$card = static function ( $prefix, array $entry ) { return resume_employer_card( $prefix, '__Y__', $entry, false ); };
				return resume_text( 'resume[earlier][label]', __( 'Fold label', 'signal-and-noise-tools' ), $earlier['label'] ?? '', 'Earlier career · 1997 - 2015' )
					. resume_list( $entries, $card, 'resume[earlier][entries]', '__X__', __( '+ Add earlier employer', 'signal-and-noise-tools' ), __( 'employer', 'signal-and-noise-tools' ), $blank );
			},
		),
		'education'    => array(
			'title' => __( 'Education', 'signal-and-noise-tools' ),
			'hint'  => '',
			'count' => count( $edu ),
			'first' => resume_first( $edu, 'title' ),
			'paint' => static function () use ( $edu, $ns ) { return resume_list( $edu, $ns . '\resume_titled_lines_row', 'resume[education]', '__D__', __( '+ Add education', 'signal-and-noise-tools' ), __( 'education entry', 'signal-and-noise-tools' ) ); },
		),
		'affiliations' => array(
			'title' => __( 'Affiliations & Certifications', 'signal-and-noise-tools' ),
			'hint'  => '',
			'count' => count( $aff ),
			'first' => resume_first( $aff, 'title' ),
			'paint' => static function () use ( $aff, $ns ) { return resume_list( $aff, $ns . '\resume_titled_lines_row', 'resume[affiliations]', '__A__', __( '+ Add affiliation', 'signal-and-noise-tools' ), __( 'affiliation', 'signal-and-noise-tools' ) ); },
		),
		'publications' => array(
			'title' => __( 'Publications', 'signal-and-noise-tools' ),
			'hint'  => __( 'A new paper is one row: venue line, title, and link.', 'signal-and-noise-tools' ),
			'count' => count( $pubs ),
			'first' => resume_first( $pubs, 'title' ),
			'paint' => static function () use ( $pubs, $ns ) { return resume_list( $pubs, $ns . '\resume_publication_row', 'resume[publications]', '__P__', __( '+ Add publication', 'signal-and-noise-tools' ), __( 'publication', 'signal-and-noise-tools' ) ); },
		),
		'skills'       => array(
			'title' => __( 'Skills', 'signal-and-noise-tools' ),
			'hint'  => __( 'One table row per category; items is the comma-separated cell.', 'signal-and-noise-tools' ),
			'count' => count( $skills ),
			'first' => resume_first( $skills, 'category' ),
			'paint' => static function () use ( $skills, $ns ) { return resume_list( $skills, $ns . '\resume_skills_row', 'resume[skills]', '__K__', __( '+ Add skills row', 'signal-and-noise-tools' ), __( 'skills row', 'signal-and-noise-tools' ) ); },
		),
		'pdf'          => array(
			'title' => __( 'PDF only', 'signal-and-noise-tools' ),
			'hint'  => __( 'Used by the generated PDF, never shown on /resume. Preview PDF shows the draft; Publish rebuilds the public PDF once one exists.', 'signal-and-noise-tools' ),
			'count' => null,
			'first' => (string) ( $pdf['headline'] ?? '' ),
			'paint' => static function () use ( $pdf ) { return resume_pdf( $pdf ); },
		),
	);
}
