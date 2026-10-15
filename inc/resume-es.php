<?php
/**
 * Signal & Noise Tools: the Spanish résumé, /cv/ and JuanLentino_CV.pdf.
 *
 * Not a second résumé system (owner, 2026-10-15: no new screen in the
 * settings). The Spanish text lives in one file, seed-content/resume-data-es.json,
 * in the same shape as the English document. The same page builder and the
 * same PDF template render it, with their headings switched to Spanish
 * (inc/resume-lang.php). Contact details (LinkedIn, email, phone, website, the
 * phone's public switch) always come from the live English document, so they
 * are never duplicated and never committed.
 *
 * When it renders: the /cv/ page (a Spanish page paired to /resume, seeded as
 * a draft by inc/bilingual-seed.php) is filled whenever the Spanish file or
 * the English contact details change, on the next admin load; the PDF is
 * regenerated with it, and every time the English PDF is generated. The
 * page's status is never touched: the owner publishes /cv/.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-lang.php';

const SN_RESUME_ES_SLUG     = 'cv';
const SN_RESUME_ES_PDF_FILE = 'JuanLentino_CV.pdf';
const SN_RESUME_ES_PDF_OPT  = 'sn_resume_pdf_es';
const SN_RESUME_ES_SYNC_OPT = 'sn_resume_es_synced';

/**
 * The Spanish document: the file, plus the English contact details and the
 * Spanish PDF's URL. Normalized like the English one. Pure over its inputs.
 *
 * @param array       $es  The decoded Spanish file.
 * @param array|null  $en  The live English document.
 * @param string      $pdf The Spanish PDF URL, or ''.
 * @return array|null
 */
function sn_resume_es_merge( $es, $en, $pdf ) {
	if ( ! is_array( $es ) || ! function_exists( 'sn_resume_doc_normalize' ) ) {
		return null;
	}
	$en                       = is_array( $en ) ? $en : array();
	$es['hero']['linkedin']   = (string) ( $en['hero']['linkedin'] ?? '' );
	$es['hero']['pdf_url']    = (string) $pdf;
	foreach ( array( 'email', 'phone', 'phone_public', 'website' ) as $k ) {
		$es['pdf'][ $k ] = $en['pdf'][ $k ] ?? '';
	}
	return sn_resume_doc_normalize( $es );
}

/**
 * The Spanish document as the site renders it now.
 *
 * @return array|null
 */
function sn_resume_es_doc() {
	$raw  = json_decode( (string) file_get_contents( __DIR__ . '/seed-content/resume-data-es.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bundled file.
	$meta = get_option( SN_RESUME_ES_PDF_OPT );
	$pdf  = is_array( $meta ) ? (string) ( $meta['url'] ?? '' ) : '';
	return sn_resume_es_merge( $raw, function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : null, $pdf );
}

/**
 * Run $fn with the résumé rendering in Spanish, and always switch back.
 *
 * @param callable $fn What to render.
 * @return mixed
 */
function sn_resume_es_render( callable $fn ) {
	sn_resume_lang( 'es' );
	try {
		return $fn();
	} finally {
		sn_resume_lang( 'en' );
	}
}

/**
 * Fill /cv/ with the Spanish body. Only a page paired to /resume is written,
 * and only its content: its status stays the owner's.
 *
 * @return bool Whether /cv/ now holds the current body.
 */
function sn_resume_es_sync_page() {
	$page = get_page_by_path( SN_RESUME_ES_SLUG, OBJECT, 'page' );
	$doc  = sn_resume_es_doc();
	if ( ! $page || ! $doc || ! function_exists( 'sn_lang_en_counterpart' ) || ! sn_lang_en_counterpart( (int) $page->ID ) ) {
		return false;
	}
	$body = (string) sn_resume_es_render( static fn() => sn_resume_body_html( $doc ) );
	if ( '' === $body ) {
		return false;
	}
	if ( $body === (string) $page->post_content ) {
		return true;
	}
	return ! is_wp_error( wp_update_post( wp_slash( array( 'ID' => (int) $page->ID, 'post_content' => $body ) ), true ) );
}

/**
 * Render JuanLentino_CV.pdf beside the English PDF, then refresh /cv/ so its
 * Download button carries the new ?v=.
 *
 * @return bool
 */
function sn_resume_es_pdf_generate() {
	$doc = sn_resume_es_doc();
	$loc = function_exists( 'sn_resume_pdf_location' ) ? sn_resume_pdf_location() : null;
	if ( ! $doc || null === $loc || ! wp_mkdir_p( $loc['dir'] ) ) {
		return false;
	}
	$name   = (string) apply_filters( 'sn_resume_pdf_name', get_bloginfo( 'name' ) );
	$result = sn_resume_es_render( static fn() => sn_resume_pdf_render( $doc, $name, $loc['dir'] . '/.font-cache', ! empty( $doc['pdf']['phone_public'] ), home_url( '/' ) ) );
	if ( is_wp_error( $result ) || 0 !== strpos( (string) $result['bytes'], '%PDF-' ) ) {
		return false;
	}
	$final = $loc['dir'] . '/' . SN_RESUME_ES_PDF_FILE;
	if ( false === file_put_contents( $final . '.tmp', $result['bytes'] ) || ! rename( $final . '.tmp', $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic write inside uploads.
		return false;
	}
	$hash = hash( 'sha256', $result['bytes'] );
	$url  = $loc['url'] . '/' . SN_RESUME_ES_PDF_FILE;
	update_option( SN_RESUME_ES_PDF_OPT, array( 'sha256' => $hash, 'url' => $url . '?v=' . substr( $hash, 0, 8 ), 'generated' => gmdate( 'c' ) ), false );
	if ( function_exists( 'sn_cf_purge_urls' ) ) {
		sn_cf_purge_urls( array( $url ) );
	}
	sn_resume_es_sync_page();
	return true;
}
add_action( 'sn_resume_pdf_generated', 'sn_resume_es_pdf_generate' );

/**
 * On an admin load: when the Spanish file or the English contact details have
 * changed since the last render, render /cv/ and its PDF again. Waits until
 * the /cv/ page exists.
 */
function sn_resume_es_maybe_sync() {
	if ( ! current_user_can( 'publish_pages' ) || ! get_page_by_path( SN_RESUME_ES_SLUG, OBJECT, 'page' ) ) {
		return;
	}
	$en    = function_exists( 'sn_resume_doc_get' ) ? sn_resume_doc_get() : array();
	$state = md5( (string) file_get_contents( __DIR__ . '/seed-content/resume-data-es.json' ) . wp_json_encode( array( $en['hero']['linkedin'] ?? '', $en['pdf'] ?? array() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bundled file.
	if ( get_option( SN_RESUME_ES_SYNC_OPT ) === $state ) {
		return;
	}
	// Recorded only on success, so a failed render is retried on the next load.
	if ( sn_resume_es_pdf_generate() || sn_resume_es_sync_page() ) {
		update_option( SN_RESUME_ES_SYNC_OPT, $state, false );
	}
}
add_action( 'admin_init', 'sn_resume_es_maybe_sync', 20 );
