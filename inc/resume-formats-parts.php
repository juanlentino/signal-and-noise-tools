<?php
/**
 * Signal & Noise Tools: what the résumé's format routes share
 * (inc/resume-jsonresume-route.php, inc/resume-formats-route.php): the lane a
 * format address names, the map of both lanes, the page's authorship proof,
 * and the headers every format answers with.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The query a format address carries for a lane: '?lane=operations' while
 * /resume has its operations résumé and that lane is the one asked for, else ''.
 *
 * @param string $slug 'resume' or 'cv'.
 * @param string $lane 'music' or 'operations'.
 * @return string
 */
function sn_resume_lane_query( $slug, $lane ) {
	$ops = 'resume' === $slug && 'operations' === $lane && function_exists( 'sn_resume_variant_config' )
		&& null !== sn_resume_variant_config( sn_resume_doc_get_raw(), 'operations' );
	return $ops ? '?lane=operations' : '';
}

/**
 * Both of /resume's lanes, each with its page, Markdown, JSON Resume and PDF,
 * so each twin names the other (`lanes:` in the Markdown front matter,
 * `meta.x-lanes` in the JSON Resume). Empty while /resume has one lane. A PDF
 * not yet generated is left out.
 *
 * @return array<string,array<string,string>>
 */
function sn_resume_lanes() {
	$q = sn_resume_lane_query( 'resume', 'operations' );
	$f = '' !== $q && function_exists( 'sn_resume_formats' ) ? sn_resume_formats( 'resume' ) : array();
	if ( ! $f ) {
		return array();
	}
	return array(
		'music'      => array_filter( array( 'html' => $f['html'], 'md' => $f['markdown'], 'json' => $f['json_resume'], 'pdf' => $f['pdf'] ?? '' ) ),
		'operations' => array_filter( array( 'html' => $f['html'] . $q, 'md' => $f['markdown'] . $q, 'json' => $f['json_resume'] . $q, 'pdf' => $f['pdf_operations'] ?? '' ) ),
	);
}

/**
 * The page's authorship proof, or null when it has none (an unanchored page:
 * sn_prov_machine_pointers_manifest() answers null).
 *
 * @param WP_Post $page
 * @return array{verify_url:string,note_uid:string}|null
 */
function sn_resume_proof( $page ) {
	$man = function_exists( 'sn_prov_machine_pointers_manifest' ) ? sn_prov_machine_pointers_manifest( (int) $page->ID ) : null;
	$uid = is_array( $man ) ? (string) ( $man['subject']['uid'] ?? '' ) : '';
	return '' === $uid ? null : array( 'verify_url' => home_url( '/verify?note=' . rawurlencode( $uid ) ), 'note_uid' => $uid );
}

/**
 * The headers every résumé format answers with: five minutes cacheable, open
 * to any origin, never indexed, and the page it represents as canonical.
 *
 * @param string $type      Content type.
 * @param string $canonical The page's address.
 * @return string[]
 */
function sn_resume_format_headers( $type, $canonical ) {
	return array(
		'Content-Type: ' . $type,
		'Cache-Control: public, max-age=300',
		'Access-Control-Allow-Origin: *',
		'X-Robots-Tag: noindex',
		'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"',
	);
}

/**
 * The page address a format represents, its canonical: /resume/?lane=operations
 * for an operations format (the address inc/resume-lane-address.php gives that
 * lane), else the page.
 *
 * @param string $slug 'resume' or 'cv'.
 * @param string $lane 'music' or 'operations'.
 * @return string
 */
function sn_resume_format_canonical( $slug, $lane ) {
	return (string) get_permalink( sn_jsonresume_page( $slug ) ) . sn_resume_lane_query( $slug, $lane );
}

/**
 * Answer 200 with a format's headers.
 *
 * @param string $type Content type.
 * @param string $slug 'resume' or 'cv'.
 * @param string $lane 'music' or 'operations'.
 * @return void
 */
function sn_resume_format_send( $type, $slug, $lane = 'music' ) {
	status_header( 200 );
	foreach ( sn_resume_format_headers( $type, sn_resume_format_canonical( $slug, $lane ) ) as $h ) {
		header( $h, 0 !== strpos( $h, 'Link:' ) ); // a Link joins WordPress's own, never replaces them.
	}
}
