<?php
/**
 * Signal & Noise Tools: where the résumé's formats are served and announced.
 *
 *   /resume.md, /cv.md               purpose-built Markdown (inc/resume-markdown.php)
 *   /resume/?output_format=markdown  the same bytes, ahead of the AI plugin's
 *                                    generic page conversion (its template_redirect
 *                                    runs at 10; this runs at 0)
 *   /resume/contact.vcf              the vCard (inc/resume-vcard.php)
 *
 * The résumé HTML also sends `Link: </resume.md>; rel="alternate";
 * type="text/markdown"`: the rights-signals worker, asked for the page with
 * `Accept: text/markdown`, serves that file instead of converting the HTML.
 * An older worker ignores the header and converts as before, so either side
 * can ship first. Everything answers only while the page is published, and
 * the formats are listed in the agents manifest (`sn_agents_surfaces`).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/resume-jsonresume-route.php';
require_once __DIR__ . '/resume-markdown.php';
require_once __DIR__ . '/resume-vcard.php';
require_once __DIR__ . '/resume-credentials.php';

/**
 * [kind, slug] for a format request ('md' or 'vcf'), else null. Path only.
 *
 * @param string $request_uri
 * @return array{0:string,1:string}|null
 */
function sn_resume_formats_route( $request_uri ) {
	$path = (string) parse_url( (string) $request_uri, PHP_URL_PATH );
	if ( preg_match( '~^/(resume|cv)\.md$~', $path, $m ) ) {
		return array( 'md', $m[1] );
	}
	return '/resume/contact.vcf' === $path ? array( 'vcf', 'resume' ) : null;
}

/**
 * Every format of a published résumé, by name. Empty when unpublished.
 *
 * @param string $slug 'resume' or 'cv'.
 * @return array<string,string>
 */
function sn_resume_formats( $slug ) {
	$page = sn_jsonresume_page( $slug );
	if ( ! $page ) {
		return array();
	}
	$doc = 'cv' === $slug ? ( function_exists( 'sn_resume_es_doc' ) ? sn_resume_es_doc() : null ) : sn_resume_doc_get();
	$pdf = (string) ( $doc['hero']['pdf_url'] ?? '' );
	if ( 'resume' === $slug && function_exists( 'sn_resume_pdf_link' ) ) {
		$pdf = sn_resume_pdf_link( $pdf );
	}
	// The operations résumé, /resume's second lane: its PDF once generated, and
	// its JSON Resume (inc/resume-page-lanes.php).
	$ops = 'resume' === $slug && function_exists( 'sn_resume_ops_pdf_url' ) ? get_option( SN_RESUME_OPS_PDF_OPTION ) : null;
	$ops = is_array( $ops ) && ! empty( $ops['url'] ) ? (string) $ops['url'] : '';
	return array_filter(
		array(
			'html'                   => (string) get_permalink( $page ),
			'markdown'               => home_url( '/' . $slug . '.md' ),
			'pdf'                    => $pdf,
			'pdf_operations'         => $ops,
			'json_resume'            => home_url( '/' . $slug . '/' . SN_JSONRESUME_FILE ),
			'json_resume_operations' => '' !== $ops ? home_url( '/' . $slug . '/' . SN_JSONRESUME_FILE . '?lane=operations' ) : '',
			'vcard'                  => home_url( '/resume/contact.vcf' ),
		)
	);
}

/**
 * `sn_agents_surfaces`: one entry for the résumé and its formats.
 *
 * @param array $surfaces
 * @return array
 */
function sn_resume_formats_surfaces( $surfaces ) {
	$f = sn_resume_formats( 'resume' );
	if ( ! $f ) {
		return $surfaces;
	}
	$es         = sn_resume_formats( 'cv' ) ? ' Spanish: ' . home_url( '/cv/' ) . ', ' . home_url( '/cv.md' ) . ', ' . home_url( '/cv/' . SN_JSONRESUME_FILE ) . '.' : '';
	$surfaces[] = array(
		'type'        => 'resume',
		'url'         => $f['html'],
		'format'      => 'text/html',
		'title'       => 'Résumé',
		'description' => 'The résumé in every format, from one source: Markdown ' . $f['markdown'] . ', JSON Resume ' . $f['json_resume'] . ', PDF ' . ( $f['pdf'] ?? '' ) . ', vCard ' . $f['vcard'] . '.'
			. ( isset( $f['pdf_operations'] ) ? ' The operations résumé (the page\'s second lane, ?lane=operations): JSON Resume ' . $f['json_resume_operations'] . ', PDF ' . $f['pdf_operations'] . '.' : '' )
			. $es . ' The page carries a Bitcoin-anchored authorship proof; the Markdown front matter and JSON Resume meta link its verify page.',
	);
	return $surfaces;
}

/**
 * The response body for a format, or null when there is nothing to serve.
 *
 * @param string $kind 'md' or 'vcf'.
 * @param string $slug 'resume' or 'cv'.
 * @return array{0:string,1:string}|null [content type, body].
 */
function sn_resume_format_body( $kind, $slug ) {
	$resume = sn_jsonresume_for( $slug );
	if ( null === $resume ) {
		return null;
	}
	if ( 'vcf' === $kind ) {
		return array( 'text/vcard; charset=utf-8', sn_resume_vcard( $resume ) );
	}
	$doc = 'cv' === $slug ? sn_resume_es_doc() : sn_resume_doc_get();
	return array( 'text/markdown; charset=utf-8', sn_resume_markdown( (array) $doc, $resume, sn_resume_formats( $slug ), 'cv' === $slug ? 'es' : 'en' ) );
}

/** template_redirect at 0: the format routes, ?output_format=markdown, the Link header. */
function sn_resume_formats_maybe_serve() {
	$req   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$route = sn_resume_formats_route( $req );
	$slug  = '';
	foreach ( array( 'resume', 'cv' ) as $s ) {
		$page = is_page( $s ) ? sn_jsonresume_page( $s ) : null;
		$slug = $page && (int) $page->ID === get_queried_object_id() ? $s : $slug;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only representation switch.
	if ( ! $route && '' !== $slug && 'markdown' === sanitize_key( wp_unslash( $_GET['output_format'] ?? '' ) ) ) {
		$route = array( 'md', $slug );
	}
	if ( ! $route ) {
		if ( '' !== $slug ) {
			header( 'Link: <' . esc_url_raw( home_url( '/' . $slug . '.md' ) ) . '>; rel="alternate"; type="text/markdown"', false );
		}
		return;
	}
	$body = sn_resume_format_body( $route[0], $route[1] );
	if ( null === $body ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: ' . $body[0] );
	header( 'Cache-Control: public, max-age=300' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Link: <' . esc_url_raw( (string) get_permalink( sn_jsonresume_page( $route[1] ) ) ) . '>; rel="canonical"', false );
	if ( 'vcf' === $route[0] ) {
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( strtolower( (string) get_bloginfo( 'name' ) ) ) . '.vcf"' );
	}
	echo $body[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown or text/vcard, not HTML.
	exit;
}

/** wp_head: the Markdown and vCard alternates on the résumé pages. */
function sn_resume_formats_head_links() {
	foreach ( array( 'resume', 'cv' ) as $slug ) {
		if ( is_page( $slug ) && sn_jsonresume_page( $slug ) ) {
			printf( '<link rel="alternate" type="text/markdown" href="%s" title="Résumé as Markdown">' . "\n", esc_url( home_url( '/' . $slug . '.md' ) ) );
			printf( '<link rel="alternate" type="text/vcard" href="%s" title="Contact card">' . "\n", esc_url( home_url( '/resume/contact.vcf' ) ) );
		}
	}
}

if ( ! defined( 'SN_RESUME_FORMATS_TEST' ) || ! SN_RESUME_FORMATS_TEST ) {
	add_action( 'template_redirect', 'sn_resume_formats_maybe_serve', 0 );
	add_action( 'wp_head', 'sn_resume_formats_head_links' );
	add_filter( 'sn_agents_surfaces', 'sn_resume_formats_surfaces' );
	add_filter( 'sn_schema_person_credentials', 'sn_resume_person_credentials' );
}
