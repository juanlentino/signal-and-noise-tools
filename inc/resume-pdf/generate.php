<?php
/**
 * Signal & Noise Tools — the resume PDF generator (docs/RESUME-PDF.md, Phase 2).
 *
 * Renders the resume document through inc/resume-pdf/template.php with Dompdf
 * and writes it to a STABLE path, uploads/resume/JuanLentino_Resume.pdf. The
 * generated timestamp, byte size, page count and SHA-256 land in one option;
 * the /resume Download link reads the stable URL plus ?v=<hash prefix>, so a
 * new PDF busts every cache layer without a new file name.
 *
 * Why Dompdf: pure PHP, runs on Cloudways PHP 8.4 with no binary, and writes
 * real selectable text (ATS-readable). It lives in lib/pdf/ with its vendor/
 * COMMITTED, because the self-updater ships the tag archive and the dev
 * vendor/ is gitignored. isRemoteEnabled stays off: the template needs no
 * network, and a PDF renderer that fetches URLs is an SSRF surface.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_PDF_OPTION = 'sn_resume_pdf';
const SN_RESUME_PDF_FILE   = 'JuanLentino_Resume.pdf';

/**
 * Where the generated file lives.
 *
 * @return array{dir:string,url:string}|null Null when uploads are unavailable.
 */
function sn_resume_pdf_location() {
	$up = wp_upload_dir( null, false );
	if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
		return null;
	}
	return array(
		'dir' => trailingslashit( $up['basedir'] ) . 'resume',
		'url' => trailingslashit( $up['baseurl'] ) . 'resume',
	);
}

/**
 * Render bytes for a document. Pure given its inputs, so the test suite runs it
 * against fixture data without WordPress.
 *
 * @param array  $doc       Canonical resume document.
 * @param string $name      Header name.
 * @param string $cache_dir Writable directory for Dompdf's font metrics cache.
 * @return array{bytes:string,pages:int}|WP_Error
 */
function sn_resume_pdf_render( $doc, $name, $cache_dir ) {
	$lib = dirname( __DIR__, 2 ) . '/lib/pdf';
	if ( ! is_readable( $lib . '/vendor/autoload.php' ) ) {
		return new WP_Error( 'sn_resume_pdf_no_renderer', 'The PDF renderer (lib/pdf/vendor) is missing from this install.' );
	}
	require_once $lib . '/vendor/autoload.php';
	if ( ! is_dir( $cache_dir ) && ! wp_mkdir_p( $cache_dir ) ) {
		return new WP_Error( 'sn_resume_pdf_cache', 'Could not create the PDF font cache directory.' );
	}

	$options = new \Dompdf\Options();
	$options->set( 'isRemoteEnabled', false );
	$options->set( 'isPhpEnabled', false );
	$options->set( 'isFontSubsettingEnabled', true );
	$options->set( 'defaultFont', 'Lato' );
	$options->set( 'fontDir', $cache_dir );
	$options->set( 'fontCache', $cache_dir );
	$options->set( 'chroot', array( $lib, $cache_dir ) );

	$dompdf = new \Dompdf\Dompdf( $options );
	$dompdf->loadHtml( sn_resume_pdf_html( $doc, $name, $lib . '/fonts' ), 'UTF-8' );
	$dompdf->setPaper( 'letter', 'portrait' );
	$dompdf->addInfo( 'Title', $name . ' — Resume' );
	$dompdf->addInfo( 'Author', $name );
	$dompdf->render();

	return array(
		'bytes' => (string) $dompdf->output(),
		'pages' => (int) $dompdf->getCanvas()->get_page_count(),
	);
}

/**
 * Generate the PDF from the stored document and publish it at the stable path.
 *
 * @return array{sha256:string,bytes:int,pages:int,generated:string,url:string}|WP_Error
 */
function sn_resume_pdf_generate() {
	$doc = function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : null;
	if ( ! is_array( $doc ) ) {
		return new WP_Error( 'sn_resume_pdf_no_doc', 'No resume document to render.' );
	}
	$loc = sn_resume_pdf_location();
	if ( null === $loc ) {
		return new WP_Error( 'sn_resume_pdf_uploads', 'The uploads directory is unavailable.' );
	}
	if ( ! wp_mkdir_p( $loc['dir'] ) ) {
		return new WP_Error( 'sn_resume_pdf_mkdir', 'Could not create uploads/resume.' );
	}

	$name   = (string) apply_filters( 'sn_resume_pdf_name', get_bloginfo( 'name' ) );
	$result = sn_resume_pdf_render( $doc, $name, $loc['dir'] . '/.font-cache' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 0 !== strpos( $result['bytes'], '%PDF-' ) ) {
		return new WP_Error( 'sn_resume_pdf_invalid', 'The renderer did not return a PDF.' );
	}

	// Atomic publish: a reader never sees a half-written file.
	$final = $loc['dir'] . '/' . SN_RESUME_PDF_FILE;
	$tmp   = $final . '.tmp';
	if ( false === file_put_contents( $tmp, $result['bytes'] ) || ! rename( $tmp, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local atomic write inside uploads.
		return new WP_Error( 'sn_resume_pdf_write', 'Could not write the PDF file.' );
	}

	$meta = array(
		'sha256'    => hash( 'sha256', $result['bytes'] ),
		'bytes'     => strlen( $result['bytes'] ),
		'pages'     => $result['pages'],
		'generated' => gmdate( 'c' ),
		'url'       => $loc['url'] . '/' . SN_RESUME_PDF_FILE,
	);
	update_option( SN_RESUME_PDF_OPTION, $meta, false );

	// The Download link carries ?v=<hash>: re-render /resume so it points at
	// this version, then purge exactly as the "Purge All Caches" button does.
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}
	if ( function_exists( 'snt_ability_purge_all_caches' ) ) {
		snt_ability_purge_all_caches( array() );
	}
	return $meta;
}

/**
 * The Download link for /resume: the generated file with a cache-busting hash
 * once one exists, else the hand-set URL from the form.
 *
 * @param string $fallback hero.pdf_url.
 * @return string
 */
function sn_resume_pdf_link( $fallback ) {
	$meta = get_option( SN_RESUME_PDF_OPTION );
	if ( is_array( $meta ) && ! empty( $meta['url'] ) && ! empty( $meta['sha256'] ) ) {
		return $meta['url'] . '?v=' . substr( (string) $meta['sha256'], 0, 8 );
	}
	return (string) $fallback;
}
