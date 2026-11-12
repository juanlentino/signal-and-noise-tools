<?php
/**
 * Signal & Noise Tools: the English /resume's headed bands and its formats line
 * (inc/resume-page-lanes.php). The edition marks and hunks that compared two
 * lanes are gone with the second lane (owner, 2026-11-12).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The headed bands, in page order (each h2's anchor slug). */
const SN_RESUME_LANE_BANDS = array( 'built', 'experience', 'credentials', 'publications', 'skills' );

/**
 * The headed bands a résumé document fills, in page order. PURE.
 *
 * @param array $doc The résumé's document (sn_resume_lane_doc()).
 * @return string[]
 */
function sn_resume_lane_band_keys( $doc ) {
	$has = array(
		'built'        => ! empty( $doc['built']['items'] ),
		'experience'   => ! empty( $doc['experience'] ) || ! empty( $doc['earlier']['entries'] ),
		'credentials'  => ! empty( $doc['education'] ) || ! empty( $doc['affiliations'] ),
		'publications' => ! empty( $doc['publications'] ),
		'skills'       => ! empty( $doc['skills'] ),
	);
	return array_keys( array_filter( $has ) );
}

/**
 * "Also as Markdown · JSON Resume": the résumé's machine twins, at its end.
 *
 * @return string
 */
function sn_resume_lane_formats() {
	$slug = '/' . ( 'es' === sn_resume_lang() ? 'cv' : ( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' ) );
	$json = $slug . '/' . ( defined( 'SN_JSONRESUME_FILE' ) ? SN_JSONRESUME_FILE : 'json-resume.json' );
	return sn_resume_para( 'sn-resume-formats', sn_resume_l( 'Also as', 'También en' ) . ' <a href="' . esc_url( $slug . '.md' ) . '">Markdown</a> · <a href="' . esc_url( $json ) . '">JSON Resume</a>' );
}

/** The music proof the résumé points to (owner, 2026-11-12): /music's verified credits; /cv points to /musica. */
const SN_RESUME_CREDITS_SLUG    = 'music';
const SN_RESUME_CREDITS_SLUG_ES = 'musica';

/**
 * The verified-credits address (/music/, or /musica/ on /cv), or '' while no
 * such page is published, so nothing links a 404.
 *
 * @return string
 */
function sn_resume_credits_url() {
	$slug = function_exists( 'sn_resume_lang' ) && 'es' === sn_resume_lang() ? SN_RESUME_CREDITS_SLUG_ES : SN_RESUME_CREDITS_SLUG;
	$page = function_exists( 'get_page_by_path' ) ? get_page_by_path( $slug ) : null;
	return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
}

/**
 * "Verified credits: juanlentino.com/music": the address printed in full, so
 * a reader (and an ATS) keeps it. '' with no credits page.
 *
 * @param string $url sn_resume_credits_url().
 * @return string Escaped inline HTML.
 */
function sn_resume_credits_html( $url ) {
	if ( '' === $url ) {
		return '';
	}
	return ( function_exists( 'sn_resume_l' ) ? sn_resume_l( 'Verified credits', 'Créditos verificados' ) : 'Verified credits' ) . ': <a href="' . esc_url( $url ) . '">' . esc_html( preg_replace( '~^https?://(www\.)?~i', '', rtrim( $url, '/' ) ) ) . '</a>';
}
