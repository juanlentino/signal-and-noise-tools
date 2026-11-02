<?php
/**
 * Signal & Noise Tools: the operations résumé PDF (inc/resume-variants.php).
 *
 * Built by the same Generate press as the music PDF (it follows the
 * sn_resume_pdf_generated action, as the Spanish CV does). Since the page's
 * second lane (owner brief 2026-11-01 #2) the operations lane's Download links
 * it, and the format list and llms.txt name it; it stays out of sitemaps, and
 * the noindex header keeps it out of search. It is not a file in
 * uploads: a static file's headers are the web server's, and the owner was
 * unsure a Cloudflare rule was available for one (2026-11-01). The bytes are kept in an
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
 * Whether the operations PDF is there to link: generated, stored as a PDF,
 * and not withdrawn. Only a phone number in it withdraws it; a file with
 * another flag stays published, the flag shown in the PDF box. The page's
 * Download, the format list and llms.txt all ask.
 *
 * @return bool
 */
function sn_resume_ops_pdf_live() {
	$meta = get_option( SN_RESUME_OPS_PDF_OPTION );
	return is_array( $meta ) && ! empty( $meta['url'] ) && '' !== sn_resume_ops_pdf_bytes();
}

/**
 * The stored operations PDF, or '' when there is none or it is not a PDF.
 *
 * @return string
 */
function sn_resume_ops_pdf_bytes() {
	$bytes = base64_decode( (string) get_option( SN_RESUME_OPS_PDF_BYTES, '' ), true );
	return false !== $bytes && 0 === strpos( $bytes, '%PDF-' ) ? $bytes : '';
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
 * résumé has no settings yet. A failure is recorded beside the last good
 * facts, so the PDF box says so instead of showing an old success.
 *
 * @return array|WP_Error|null The stored facts.
 */
function sn_resume_ops_pdf_generate() {
	$out = sn_resume_ops_pdf_build();
	if ( is_wp_error( $out ) ) {
		$prev = get_option( SN_RESUME_OPS_PDF_OPTION );
		update_option( SN_RESUME_OPS_PDF_OPTION, array( 'error' => $out->get_error_message(), 'error_at' => gmdate( 'c' ) ) + ( is_array( $prev ) ? $prev : array() ), false );
	}
	// Generate syncs /resume before this file is built; sync again so the
	// operations lane's Download follows the file (an identical body is not rewritten).
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}
	return $out;
}

/**
 * The work of sn_resume_ops_pdf_generate().
 *
 * @return array|WP_Error|null
 */
function sn_resume_ops_pdf_build() {
	$raw    = function_exists( 'sn_resume_doc_get_raw' ) ? sn_resume_doc_get_raw() : null;
	$config = sn_resume_variant_config( $raw, 'operations' );
	$loc    = sn_resume_pdf_location(); // for Dompdf's font cache only; the PDF itself is not written there.
	if ( null === $config ) {
		delete_option( SN_RESUME_OPS_PDF_BYTES ); // settings removed: no file, and no facts naming one.
		delete_option( SN_RESUME_OPS_PDF_OPTION );
		return null;
	}
	$view = sn_resume_doc_view( $raw, 'operations' );
	if ( empty( $view['experience'] ) && empty( $view['publications'] ) ) {
		return new WP_Error( 'sn_resume_pdf_empty', 'The operations résumé has no experience and no publications to show.' );
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
	// A phone in it is never served: checked before the bytes are stored.
	$url   = sn_resume_ops_pdf_url( $config['pdf_file'] );
	$check = sn_resume_pdf_check( $result['bytes'], $result['pages'], 2, (string) ( $raw['pdf']['phone'] ?? '' ) );
	if ( in_array( 'a phone number', $check['flags'], true ) ) {
		delete_option( SN_RESUME_OPS_PDF_BYTES );
		delete_option( SN_RESUME_OPS_PDF_OPTION ); // nothing is served, so nothing may link it (sn_resume_ops_pdf_live()).
		if ( function_exists( 'sn_cf_purge_urls' ) ) {
			sn_cf_purge_urls( array( $url ) ); // and the edge stops serving the last one.
		}
		return new WP_Error( 'sn_resume_pdf_phone', 'The operations PDF read back with a phone number; it was not published.' );
	}
	update_option( SN_RESUME_OPS_PDF_BYTES, base64_encode( $result['bytes'] ), false );
	// Read back: update_option() is false for an unchanged value too, and a large one can fail to store.
	if ( sn_resume_ops_pdf_bytes() !== $result['bytes'] ) {
		return new WP_Error( 'sn_resume_pdf_write', 'The operations PDF could not be stored.' );
	}
	if ( function_exists( 'sn_cf_purge_urls' ) ) {
		sn_cf_purge_urls( array( $url ) );
	}
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
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to one fixed path, never echoed.
	if ( 0 !== strpos( $path, '/resume/' ) || '.pdf' !== substr( $path, -4 ) ) {
		return; // every other request: no document read on the hot path.
	}
	$config = sn_resume_ops_config();
	if ( null === $config || '/resume/' . $config['pdf_file'] !== $path ) {
		return;
	}
	$bytes = sn_resume_ops_pdf_bytes();
	if ( '' === $bytes ) {
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

require_once __DIR__ . '/operations-box.php'; // what the PDF box says about it.
