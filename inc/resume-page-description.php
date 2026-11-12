<?php
/**
 * Signal & Noise Tools: /resume's description follows the one résumé
 * (owner, 2026-11-12).
 *
 * The page's own SEO description was written for the music résumé. While the
 * operations settings exist, /resume's meta, og and twitter description and
 * its ProfilePage read the first sentence of the résumé's summary as stored,
 * never reworded or cut. Everything else in the head is the page's own: the
 * canonical is /resume/ on every address, `?lane=` included, and a coded link
 * (/r/CODE) stays noindex with that canonical. Until 2026-11-12 the
 * operations lane kept ?lane=operations in its canonical and had its own
 * title; both are gone with the lane.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The longest first sentence the description takes; past it, the page's own. */
const SN_RESUME_LANE_DESCRIPTION_MAX = 300;

/**
 * The summary's first sentence, read once per stored document: a sentence
 * ends at . ! or ? (or one inside a closing paren or quote) before a capital,
 * a digit or the end, so "the U.S. and" does not end one. '' when none fits.
 * ponytail: an abbreviation before a capital ("U.S. Latin") would cut early; a
 * stored description field is the upgrade if the summary ever reads so.
 *
 * @return string
 */
function sn_resume_description_first() {
	static $m = array();
	$key = defined( 'SN_RESUME_DOC_OPTION' ) ? get_option( SN_RESUME_DOC_OPTION ) : null;
	if ( array_key_exists( 'key', $m ) && $m['key'] === $key ) {
		return $m['first'];
	}
	$raw   = sn_resume_doc_get_raw();
	$first = '';
	if ( function_exists( 'sn_resume_variant_config' ) && null !== sn_resume_variant_config( $raw, 'operations' ) ) {
		$summary = trim( (string) ( sn_resume_lane_doc( $raw, 'operations' )['hero']['summary'] ?? '' ) );
		$first   = preg_match( '/^.+?[.!?][)"\'\x{201D}\x{2019}]*(?=\s+[\p{Lu}\p{N}]|$)/us', $summary, $s ) && mb_strlen( $s[0] ) <= SN_RESUME_LANE_DESCRIPTION_MAX ? $s[0] : '';
	}
	$m = array( 'key' => $key, 'first' => $first );
	return $first;
}

/**
 * /resume's description (meta, og, twitter, the ProfilePage).
 *
 * @param string $description The view's description.
 * @return string
 */
function sn_resume_lane_description( $description ) {
	if ( ! is_page( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' ) ) {
		return $description;
	}
	$first = sn_resume_description_first();
	return '' === $first ? $description : $first;
}
add_filter( 'sn_seo_view_description', 'sn_resume_lane_description' );
