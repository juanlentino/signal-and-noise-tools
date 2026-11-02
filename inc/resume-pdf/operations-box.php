<?php
/**
 * Signal & Noise Tools: what the résumé PDF box says about the checks and the
 * operations file (inc/resume-pdf/operations.php), as plain lines; each editor
 * (classic and native) paints them its own way.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One line for the admin: the check's result for a generated PDF.
 *
 * @param array|mixed $meta A PDF's stored facts.
 * @return string Plain text; '' when the PDF was never checked.
 */
function sn_resume_pdf_check_line( $meta ) {
	$check = is_array( $meta['check'] ?? null ) ? $meta['check'] : null;
	if ( null === $check ) {
		return '';
	}
	$by = ' (' . (string) ( $check['extractor'] ?? '' ) . ', ' . number_format( (int) ( $check['chars'] ?? 0 ) ) . ' characters read)';
	return ( $check['flags'] ? 'Check flagged: ' . implode( '; ', $check['flags'] ) . '.' : 'Check passed: no control characters, ligatures or phone number.' ) . $by;
}

/**
 * The PDF box's lines: the music check, then the operations file.
 *
 * @return array<int,array{text:string,url:string}>
 */
function sn_resume_pdf_variant_lines() {
	$lines = array();
	$music = get_option( defined( 'SN_RESUME_PDF_OPTION' ) ? SN_RESUME_PDF_OPTION : 'sn_resume_pdf' );
	if ( '' !== sn_resume_pdf_check_line( $music ) ) {
		$lines[] = array( 'text' => 'Music PDF. ' . sn_resume_pdf_check_line( $music ), 'url' => '' );
	}
	$ops  = get_option( SN_RESUME_OPS_PDF_OPTION );
	$ops  = is_array( $ops ) ? $ops : array();
	$live = sn_resume_ops_pdf_live();
	if ( ! empty( $ops['error'] ) ) {
		$lines[] = array( 'text' => 'Operations PDF: the last Generate failed (' . (string) $ops['error'] . '). ' . ( $live ? 'The file below is still the one served.' : 'Nothing is served.' ), 'url' => '' );
	}
	if ( $live ) {
		$pages   = (int) ( $ops['pages'] ?? 0 );
		$flagged = ! empty( $ops['check']['flags'] ) ? ' It stays published: only a phone number withdraws it.' : '';
		$lines[] = array( 'text' => sprintf( 'Operations PDF, generated %1$s: %2$d %3$s, linked from the Operations lane on /resume. %4$s%5$s', function_exists( 'sn_resume_pdf_when' ) ? sn_resume_pdf_when( $ops ) : (string) ( $ops['generated'] ?? '' ), $pages, 1 === $pages ? 'page' : 'pages', sn_resume_pdf_check_line( $ops ), $flagged ), 'url' => (string) $ops['url'] );
	} elseif ( ! empty( $ops['error'] ) ) {
		return $lines; // the failure line says it all.
	} elseif ( null !== sn_resume_ops_config() ) {
		$lines[] = array( 'text' => 'Operations PDF: set up; Generate builds it beside the music PDF.', 'url' => '' );
	} elseif ( function_exists( 'sn_resume_draft_get' ) && null !== sn_resume_variant_config( sn_resume_draft_get(), 'operations' ) ) {
		$lines[] = array( 'text' => 'Operations PDF: set up in the draft. Publish it, then Generate builds it.', 'url' => '' );
	} else {
		$lines[] = array( 'text' => 'Operations PDF: not set up yet. "Set up the operations résumé" writes its content into the draft.', 'url' => '' );
	}
	return $lines;
}
