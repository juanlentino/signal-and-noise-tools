<?php
/**
 * Signal & Noise Tools: what the résumé's format routes share
 * (inc/resume-jsonresume-route.php, inc/resume-formats-route.php): the page's
 * authorship proof, its canonical, and the headers every format answers with.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * The page address a format represents, its canonical: the page itself
 * (`?lane=` is ignored since the one résumé, 2026-11-12).
 *
 * @param string $slug 'resume' or 'cv'.
 * @return string
 */
function sn_resume_format_canonical( $slug ) {
	return (string) get_permalink( sn_jsonresume_page( $slug ) );
}

/**
 * Answer 200 with a format's headers.
 *
 * @param string $type Content type.
 * @param string $slug 'resume' or 'cv'.
 * @return void
 */
function sn_resume_format_send( $type, $slug ) {
	status_header( 200 );
	foreach ( sn_resume_format_headers( $type, sn_resume_format_canonical( $slug ) ) as $h ) {
		header( $h, 0 !== strpos( $h, 'Link:' ) ); // a Link joins WordPress's own, never replaces them.
	}
}
