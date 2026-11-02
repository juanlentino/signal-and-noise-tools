<?php
/**
 * Signal & Noise Tools: the operations lane is its own address (2026-11-01).
 *
 * On /resume/?lane=operations the canonical and og:url keep the lane: a phone's
 * share sheet copies the canonical, and a link preview reads og:url, so both
 * dropped the lane while it was /resume/. The lane is read as the page reads it
 * (sn_resume_active_lane(), an allowlist), and only while an operations résumé
 * exists, so the address always names what the page shows. No hreflang pair
 * there: the Spanish CV has no operations lane.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this request shows /resume's operations lane.
 *
 * @return bool
 */
function sn_resume_lane_address_active() {
	return function_exists( 'sn_resume_active_lane' ) && 'operations' === sn_resume_active_lane()
		&& is_page( 'resume' ) && function_exists( 'sn_jsonresume_page' ) && sn_jsonresume_page( 'resume' )
		&& function_exists( 'sn_resume_variant_config' ) && null !== sn_resume_variant_config( sn_resume_doc_get_raw(), 'operations' );
}

/**
 * The page's own address on the operations lane; any other URL untouched.
 *
 * @param string $url The permalink (or an override, which is left alone).
 * @return string
 */
function sn_resume_lane_address( $url ) {
	$page = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
	if ( ! $page || (string) $url !== (string) get_permalink( $page ) || ! sn_resume_lane_address_active() ) {
		return $url;
	}
	return add_query_arg( 'lane', 'operations', $url );
}
add_filter( 'get_canonical_url', 'sn_resume_lane_address', 20 );
add_filter( 'sn_seo_view_url', 'sn_resume_lane_address' );

/**
 * No hreflang pair on the operations lane.
 *
 * @param array $pair sn_lang_pair_urls().
 * @return array
 */
function sn_resume_lane_hreflang( $pair ) {
	return sn_resume_lane_address_active() ? array() : $pair;
}
add_filter( 'sn_lang_hreflang_pair', 'sn_resume_lane_hreflang' );
