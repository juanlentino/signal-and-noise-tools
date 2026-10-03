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
 * @param string $cache_dir     Writable directory for Dompdf's font metrics cache.
 * @param bool   $include_phone True only for the private copy (never written to disk).
 * @param string $site_url      Home URL for the contact line ('' leaves it out).
 * @return array{bytes:string,pages:int}|WP_Error
 */
function sn_resume_pdf_render( $doc, $name, $cache_dir, $include_phone = false, $site_url = '' ) {
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
	$dompdf->loadHtml( sn_resume_pdf_html( $doc, $name, $lib . '/fonts', (bool) $include_phone, (string) $site_url ), 'UTF-8' );
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
	// PUBLIC: the phone only when the owner switched it on (Content → Resume,
	// PDF only). Default off; the private copy is the way to send it.
	$result = sn_resume_pdf_render( $doc, $name, $loc['dir'] . '/.font-cache', ! empty( $doc['pdf']['phone_public'] ), home_url( '/' ) );
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
	$previous = get_option( SN_RESUME_PDF_OPTION );
	update_option( SN_RESUME_PDF_OPTION, $meta, false );

	// The Download link carries ?v=<hash>: re-render /resume so it points at
	// this version. That is an ordinary page save, and the save does the page's
	// own purging: this plugin's per-URL Cloudflare purge (the /resume/
	// permalink and its listings) and Breeze's save_post handler, which drops
	// its stored copy of the page. Nothing here repeats either.
	if ( function_exists( 'sn_resume_sync_page' ) ) {
		sn_resume_sync_page();
	}
	// What a page save cannot know about is the file. It used to be cleared by
	// the purge-everything ability, which also emptied Redis and every other
	// page to drop one PDF. Now the file's own addresses go through the per-URL
	// path, which does nothing when Cloudflare is not configured.
	if ( function_exists( 'sn_cf_purge_urls' ) ) {
		sn_cf_purge_urls( sn_resume_pdf_purge_urls( $meta, $previous ) );
	}
	return $meta;
}

/**
 * The file's addresses at the edge after a generation. PURE.
 *
 * The bare URL (served for a year by the web server, whatever its bytes); the
 * new ?v= URL, because the same bytes give the same version and the edge may
 * already hold it; and the previous ?v= URL, which an old copy of /resume
 * still links and which would otherwise keep answering with the old file.
 *
 * @param array<string,mixed> $meta     The option just written.
 * @param mixed               $previous The option as it was before, if any.
 * @return string[]
 */
function sn_resume_pdf_purge_urls( array $meta, $previous = null ) {
	if ( empty( $meta['url'] ) ) {
		return array();
	}
	$urls = array( (string) $meta['url'] );
	foreach ( array( $meta, $previous ) as $m ) {
		if ( is_array( $m ) && ! empty( $m['url'] ) && ! empty( $m['sha256'] ) ) {
			$urls[] = (string) $m['url'] . '?v=' . substr( (string) $m['sha256'], 0, 8 );
		}
	}
	return array_values( array_unique( $urls ) );
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

/**
 * Stream a rendered PDF to the requesting admin as an attachment, never
 * written to disk, so it has no URL anyone else can fetch. The dispatcher has
 * checked the nonce and manage_options. Exits on success; returns a WP_Error
 * for the caller's flash on failure.
 *
 * @param array|null $doc        Document to render.
 * @param bool       $with_phone Whether the phone is printed.
 * @param string     $filename   Attachment name (a fixed string, never input).
 * @return WP_Error|void
 */
function sn_resume_pdf_stream( $doc, $with_phone, $filename ) {
	$loc = sn_resume_pdf_location();
	if ( ! is_array( $doc ) || null === $loc ) {
		return new WP_Error( 'sn_resume_pdf_stream', 'No resume document or uploads directory.' );
	}
	$name   = (string) apply_filters( 'sn_resume_pdf_name', get_bloginfo( 'name' ) );
	$result = sn_resume_pdf_render( $doc, $name, $loc['dir'] . '/.font-cache', (bool) $with_phone, home_url( '/' ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . strlen( $result['bytes'] ) );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $result['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF body, not HTML.
	exit;
}

/**
 * The PRIVATE copy: the live document WITH the phone, streamed, never stored.
 *
 * @return WP_Error|void
 */
function sn_resume_pdf_private_stream() {
	return sn_resume_pdf_stream( function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : null, true, 'JuanLentino_Resume_private.pdf' );
}

/**
 * The DRAFT preview: the draft under the PUBLIC phone rule, so it is the file
 * Publish would generate. Streamed, never stored.
 *
 * @return WP_Error|void
 */
function sn_resume_pdf_draft_stream() {
	$doc = function_exists( 'sn_resume_draft_get' ) ? sn_resume_draft_get() : null;
	return sn_resume_pdf_stream( $doc, ! empty( $doc['pdf']['phone_public'] ), 'JuanLentino_Resume_draft.pdf' );
}

/**
 * When the public PDF was generated, for people: the stored value is UTC
 * (gmdate 'c', unambiguous in the option), shown in the SITE timezone with the
 * site's own date and time formats (owner, 2026-09-22: the raw UTC stamp read
 * as a time that wasn't theirs). '' when unknown.
 *
 * @param array|false $meta The sn_resume_pdf option.
 * @return string
 */
function sn_resume_pdf_when( $meta ) {
	$ts = is_array( $meta ) ? strtotime( (string) ( $meta['generated'] ?? '' ) ) : false;
	if ( false === $ts ) {
		return '';
	}
	return (string) wp_date( get_option( 'date_format', 'F j, Y' ) . ' \\a\\t ' . get_option( 'time_format', 'g:i a' ), $ts );
}
