<?php
/**
 * Signal & Noise Tools: the operations résumé PDF (inc/resume-variants.php).
 *
 * Built by the same Generate press as the music PDF (it follows the
 * sn_resume_pdf_generated action, as the Spanish CV does), written beside it
 * under a stable name, and never linked from any page, sitemap, llms file or
 * format list. The file is static, so WordPress cannot set its headers: the
 * noindex comes from a Cloudflare response header rule on its path, and every
 * generation reads the header back and flags it when it is missing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_OPS_PDF_OPTION = 'sn_resume_pdf_operations';

/**
 * Whether a URL answers with X-Robots-Tag noindex. Null when it could not be read.
 *
 * @param string $url
 * @return bool|null
 */
function sn_resume_pdf_noindex( $url ) {
	if ( ! function_exists( 'wp_remote_head' ) ) {
		return null;
	}
	$res = wp_remote_head( add_query_arg( 'check', (string) time(), $url ), array( 'timeout' => 8, 'redirection' => 0 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	return false !== stripos( (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ), 'noindex' );
}

/**
 * Render, check and publish the operations PDF. Null when the operations
 * résumé has no settings yet.
 *
 * @return array|WP_Error|null The stored facts.
 */
function sn_resume_ops_pdf_generate() {
	$raw    = function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null;
	$config = sn_resume_variant_config( $raw, 'operations' );
	$loc    = sn_resume_pdf_location();
	if ( null === $config ) {
		return null;
	}
	if ( null === $loc || ! wp_mkdir_p( $loc['dir'] ) ) {
		return new WP_Error( 'sn_resume_pdf_uploads', 'The uploads directory is unavailable.' );
	}
	$config['built'] = sn_resume_built_for( $raw, 'operations' );
	$name            = (string) apply_filters( 'sn_resume_pdf_name', get_bloginfo( 'name' ) );
	$result          = sn_resume_pdf_render( sn_resume_doc_view( $raw, 'operations' ), $name, $loc['dir'] . '/.font-cache', false, home_url( '/' ), $config );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 0 !== strpos( (string) $result['bytes'], '%PDF-' ) ) {
		return new WP_Error( 'sn_resume_pdf_invalid', 'The renderer did not return a PDF.' );
	}
	$final = $loc['dir'] . '/' . $config['pdf_file'];
	if ( false === file_put_contents( $final . '.tmp', $result['bytes'] ) || ! rename( $final . '.tmp', $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic write inside uploads.
		return new WP_Error( 'sn_resume_pdf_write', 'Could not write the operations PDF.' );
	}
	$url = $loc['url'] . '/' . $config['pdf_file'];
	if ( function_exists( 'sn_cf_purge_urls' ) ) {
		sn_cf_purge_urls( array( $url ) );
	}
	$check = sn_resume_pdf_check( $result['bytes'], $result['pages'], 2 );
	$noindex = sn_resume_pdf_noindex( $url );
	if ( true !== $noindex ) {
		$check['flags'][] = null === $noindex ? 'the noindex header could not be read' : 'no X-Robots-Tag: noindex header';
	}
	$meta = array(
		'sha256'    => hash( 'sha256', $result['bytes'] ),
		'bytes'     => strlen( $result['bytes'] ),
		'pages'     => $result['pages'],
		'generated' => gmdate( 'c' ),
		'url'       => $url,
		'check'     => $check,
	);
	update_option( SN_RESUME_OPS_PDF_OPTION, $meta, false );
	return $meta;
}
add_action( 'sn_resume_pdf_generated', 'sn_resume_ops_pdf_generate', 20 );

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
 * What the PDF box says about the checks and the operations file, as plain
 * lines; each editor (classic and native) paints them its own way.
 *
 * @return array<int,array{text:string,url:string}>
 */
function sn_resume_pdf_variant_lines() {
	$lines = array();
	$music = get_option( defined( 'SN_RESUME_PDF_OPTION' ) ? SN_RESUME_PDF_OPTION : 'sn_resume_pdf' );
	if ( '' !== sn_resume_pdf_check_line( $music ) ) {
		$lines[] = array( 'text' => 'Music PDF. ' . sn_resume_pdf_check_line( $music ), 'url' => '' );
	}
	$ops = get_option( SN_RESUME_OPS_PDF_OPTION );
	if ( is_array( $ops ) && ! empty( $ops['url'] ) ) {
		$pages   = (int) ( $ops['pages'] ?? 0 );
		$lines[] = array( 'text' => sprintf( 'Operations PDF, generated %1$s: %2$d %3$s, not linked anywhere. %4$s', function_exists( 'sn_resume_pdf_when' ) ? sn_resume_pdf_when( $ops ) : (string) ( $ops['generated'] ?? '' ), $pages, 1 === $pages ? 'page' : 'pages', sn_resume_pdf_check_line( $ops ) ), 'url' => (string) $ops['url'] );
	} elseif ( null !== sn_resume_variant_config( function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null, 'operations' ) ) {
		$lines[] = array( 'text' => 'Operations PDF: set up; Generate builds it beside the music PDF.', 'url' => '' );
	} else {
		$lines[] = array( 'text' => 'Operations PDF: not set up yet. "Set up the operations résumé" writes its content into the draft.', 'url' => '' );
	}
	return $lines;
}
