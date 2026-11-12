<?php
/**
 * Signal & Noise Tools: where the résumé's formats are served and announced.
 *
 *   /resume.md, /cv.md               purpose-built Markdown (inc/resume-markdown.php)
 *   /resume/?output_format=markdown  the same bytes, served by the AI plugin's
 *                                    Markdown feature: this file hands it the
 *                                    document through its
 *                                    `wpai_markdown_singular_sections` filter and
 *                                    serves it itself only while that feature is
 *                                    not hooked
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
	// The one résumé's PDF is the operations build, once generated (inc/resume-pdf/operations.php).
	if ( 'resume' === $slug && sn_resume_formats_one() && function_exists( 'sn_resume_ops_pdf_live' ) ) {
		$pdf = sn_resume_ops_pdf_live() ? sn_resume_ops_pdf_link() : ''; // with its ?v=; none before it exists.
	}
	return array_filter(
		array(
			'html'        => (string) get_permalink( $page ),
			'markdown'    => home_url( '/' . $slug . '.md' ),
			'pdf'         => $pdf,
			'json_resume' => home_url( '/' . $slug . '/' . SN_JSONRESUME_FILE ),
			'vcard'       => 'resume' === $slug && sn_resume_formats_one() ? '' : home_url( '/resume/contact.vcf' ), // the one résumé never links the contact card (owner, 2026-11-02).
		)
	);
}

/**
 * Whether /resume is the one résumé built from the operations settings
 * (inc/resume-page-lanes.php), rather than the plain music document.
 *
 * @return bool
 */
function sn_resume_formats_one() {
	return function_exists( 'sn_resume_variant_config' ) && null !== sn_resume_variant_config( sn_resume_doc_get_raw(), 'operations' );
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
		'description' => 'The résumé in every format, from one source: Markdown ' . $f['markdown'] . ', JSON Resume ' . $f['json_resume'] . ( isset( $f['pdf'] ) ? ', PDF ' . $f['pdf'] : '' ) . ( isset( $f['vcard'] ) ? ', vCard ' . $f['vcard'] : '' ) . '.'
			. $es . ( sn_resume_proof( sn_jsonresume_page( 'resume' ) ) ? ' The page carries a Bitcoin-anchored authorship proof; the Markdown front matter and JSON Resume meta link its verify page.' : '' ),
	);
	return $surfaces;
}

/**
 * The response body for a format, or null when there is nothing to serve.
 * /resume.md is the one résumé the page shows; any `?lane=` is ignored.
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
	$one = 'resume' === $slug && sn_resume_formats_one();
	$doc = 'cv' === $slug ? sn_resume_es_doc() : ( $one ? sn_resume_lane_doc( sn_resume_doc_get_raw(), 'operations' ) : sn_resume_doc_get() );
	return array( 'text/markdown; charset=utf-8', sn_resume_markdown( (array) $doc, $resume, sn_resume_formats( $slug ), 'cv' === $slug ? 'es' : 'en' ) );
}

/** 'resume' or 'cv' when the request is that published page, else ''. */
function sn_resume_formats_page_slug() {
	$slug = '';
	foreach ( array( 'resume', 'cv' ) as $s ) {
		$page = is_page( $s ) ? sn_jsonresume_page( $s ) : null;
		$slug = $page && (int) $page->ID === get_queried_object_id() ? $s : $slug;
	}
	return $slug;
}

/** Whether the request asks for the page with ?output_format=markdown. */
function sn_resume_formats_markdown_asked() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only representation switch.
	return 'markdown' === sanitize_key( wp_unslash( $_GET['output_format'] ?? '' ) );
}

/**
 * Whether the AI plugin's Markdown feature will answer this request: its
 * handler is hooked on template_redirect (found by class and method, since the
 * plugin keeps its instances private). False when the plugin is absent or the
 * feature is off, and then this file serves ?output_format=markdown itself.
 *
 * @return bool
 */
function sn_resume_formats_ai_markdown_hooked() {
	$class = 'WordPress\\AI\\Experiments\\Markdown_Feeds\\Markdown_Feeds';
	$hook  = $GLOBALS['wp_filter']['template_redirect'] ?? null;
	foreach ( is_object( $hook ) ? (array) $hook->callbacks : array() as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'] ?? null;
			if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof $class && 'handle_template_redirect' === $fn[1] ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * `wpai_markdown_singular_sections`: on a published résumé page the AI
 * plugin's Markdown document is the purpose-built one, as its only section.
 * The renderer joins sections and ends the document with one newline, so the
 * section drops its own and the response body equals /resume.md byte for byte.
 *
 * @param array<string,string> $sections Named Markdown sections.
 * @param WP_Post|mixed        $post     Post being rendered.
 * @return array<string,string>
 */
function sn_resume_formats_markdown_sections( $sections, $post = null ) {
	foreach ( array( 'resume', 'cv' ) as $slug ) {
		$page = sn_jsonresume_page( $slug );
		$body = $page && is_object( $post ) && (int) $page->ID === (int) ( $post->ID ?? 0 ) ? sn_resume_format_body( 'md', $slug ) : null;
		if ( null !== $body ) {
			return array( 'resume' => "\n" === substr( $body[1], -1 ) ? substr( $body[1], 0, -1 ) : $body[1] );
		}
	}
	return $sections;
}

/**
 * template_redirect at 9, just before the AI plugin serves
 * ?output_format=markdown at 10: the résumé formats' own headers (cacheable
 * for five minutes, open to any origin, the page as canonical). The AI plugin
 * then sets Content-Type and X-Robots-Tag: noindex itself.
 */
function sn_resume_formats_markdown_headers() {
	$slug = sn_resume_formats_page_slug();
	if ( '' === $slug || ! sn_resume_formats_markdown_asked() || ! sn_resume_formats_ai_markdown_hooked() ) {
		return;
	}
	$body = sn_resume_format_body( 'md', $slug );
	if ( null !== $body ) {
		sn_resume_format_send( $body[0], $slug );
	}
}

/** template_redirect at 0: the format routes, ?output_format=markdown without the AI plugin, the Link header. */
function sn_resume_formats_maybe_serve() {
	$req   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$route = sn_resume_formats_route( $req );
	$slug  = sn_resume_formats_page_slug();
	if ( ! $route && '' !== $slug && sn_resume_formats_markdown_asked() ) {
		if ( sn_resume_formats_ai_markdown_hooked() ) {
			return; // the AI plugin serves it at 10, with the section above and the headers sent at 9.
		}
		$route = array( 'md', $slug );
	}
	if ( ! $route ) {
		if ( '' !== $slug ) {
			header( 'Link: <' . esc_url_raw( sn_resume_markdown_url( $slug ) ) . '>; rel="alternate"; type="text/markdown"', false );
		}
		return;
	}
	$body = sn_resume_format_body( $route[0], $route[1] );
	if ( null === $body ) {
		return;
	}
	sn_resume_format_send( $body[0], $route[1] );
	if ( 'vcf' === $route[0] ) {
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( strtolower( (string) get_bloginfo( 'name' ) ) ) . '.vcf"' );
	}
	echo $body[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown or text/vcard, not HTML.
	exit;
}

/**
 * The Markdown for a résumé page: /resume.md or /cv.md.
 *
 * @param string $slug 'resume' or 'cv'.
 * @return string
 */
function sn_resume_markdown_url( $slug ) {
	return home_url( '/' . $slug . '.md' );
}

/** wp_head: the Markdown and vCard alternates on the résumé pages. */
function sn_resume_formats_head_links() {
	foreach ( array( 'resume', 'cv' ) as $slug ) {
		if ( is_page( $slug ) && sn_jsonresume_page( $slug ) ) {
			printf( '<link rel="alternate" type="text/markdown" href="%s" title="Résumé as Markdown">' . "\n", esc_url( sn_resume_markdown_url( $slug ) ) );
			$vcard = sn_resume_formats( $slug )['vcard'] ?? '';
			if ( '' !== $vcard ) { // the one résumé never links the contact card (owner, 2026-11-02).
				printf( '<link rel="alternate" type="text/vcard" href="%s" title="Contact card">' . "\n", esc_url( $vcard ) );
			}
		}
	}
}

if ( ! defined( 'SN_RESUME_FORMATS_TEST' ) || ! SN_RESUME_FORMATS_TEST ) {
	add_action( 'template_redirect', 'sn_resume_formats_maybe_serve', 0 );
	add_action( 'template_redirect', 'sn_resume_formats_markdown_headers', 9 );
	add_filter( 'wpai_markdown_singular_sections', 'sn_resume_formats_markdown_sections', 10, 2 );
	add_action( 'wp_head', 'sn_resume_formats_head_links' );
	add_filter( 'sn_agents_surfaces', 'sn_resume_formats_surfaces' );
	add_filter( 'sn_schema_person_credentials', 'sn_resume_person_credentials' );
}
