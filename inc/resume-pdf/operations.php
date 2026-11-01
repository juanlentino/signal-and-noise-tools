<?php
/**
 * Signal & Noise Tools: the operations résumé PDF (inc/resume-variants.php).
 *
 * Built by the same Generate press as the music PDF (it follows the
 * sn_resume_pdf_generated action, as the Spanish CV does) and never linked
 * from any page, sitemap, llms file or format list. It is not a file in
 * uploads: a static file's headers are the web server's, and the owner did not
 * want to spend a Cloudflare rule on one (2026-11-01). The bytes are kept in an
 * option and served by WordPress at /resume/<file name> with X-Robots-Tag:
 * noindex, nofollow; every generation reads that header back.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_OPS_PDF_OPTION = 'sn_resume_pdf_operations';
const SN_RESUME_OPS_PDF_BYTES  = 'sn_resume_pdf_operations_bytes'; // base64: a binary string in a utf8mb4 column could be mangled.

/**
 * The operations PDF's stable address: /resume/<file name>.
 *
 * @param string $file
 * @return string
 */
function sn_resume_ops_pdf_url( $file ) {
	return home_url( '/resume/' . $file );
}

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
	$loc    = sn_resume_pdf_location(); // for Dompdf's font cache only; the PDF itself is not written there.
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
	update_option( SN_RESUME_OPS_PDF_BYTES, base64_encode( $result['bytes'] ), false );
	$url = sn_resume_ops_pdf_url( $config['pdf_file'] );
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
 * Serve the operations PDF at /resume/<file name>, noindex. Anything else
 * passes through untouched.
 */
function sn_resume_ops_pdf_maybe_serve() {
	$path   = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to one fixed path, never echoed.
	$config = sn_resume_variant_config( function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null, 'operations' );
	if ( null === $config || '/resume/' . $config['pdf_file'] !== $path ) {
		return;
	}
	$bytes = base64_decode( (string) get_option( SN_RESUME_OPS_PDF_BYTES, '' ), true );
	if ( false === $bytes || 0 !== strpos( $bytes, '%PDF-' ) ) {
		return; // not generated yet: the ordinary 404.
	}
	status_header( 200 );
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="' . $config['pdf_file'] . '"' );
	header( 'Content-Length: ' . strlen( $bytes ) );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=300' ); // Generate purges this URL.
	echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.
	exit;
}
add_action( 'template_redirect', 'sn_resume_ops_pdf_maybe_serve', 0 );

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
