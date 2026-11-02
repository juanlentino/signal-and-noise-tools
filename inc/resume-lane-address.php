<?php
/**
 * Signal & Noise Tools: the operations lane is its own address (2026-11-01).
 *
 * On /resume/?lane=operations the canonical and og:url keep the lane: a phone's
 * share sheet copies the canonical, and a link preview reads og:url, so both
 * dropped the lane while it was /resume/. The lane is read as the page reads it
 * (sn_resume_active_lane(), an allowlist), and only while an operations résumé
 * exists, so the address always names what the page shows. No hreflang pair
 * there: the Spanish CV has no operations lane. The lane's head names it too:
 * its own title and description, and the ProfilePage follows (2026-11-02).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stored résumé, and the operations summary's first sentence, read once
 * per request: sn_resume_doc_get_raw() normalizes on every call and one head
 * asks several times. Read again only when the stored option changes (the same
 * array is the same table, so the check costs nothing).
 *
 * @param string $field 'raw' or 'first'.
 * @return mixed
 */
function sn_resume_lane_address_memo( $field ) {
	static $m = array();
	$key = defined( 'SN_RESUME_DOC_OPTION' ) ? get_option( SN_RESUME_DOC_OPTION ) : null;
	if ( ! array_key_exists( 'key', $m ) || $m['key'] !== $key ) {
		$m = array( 'key' => $key, 'raw' => sn_resume_doc_get_raw() );
	}
	if ( 'first' === $field && ! array_key_exists( 'first', $m ) ) {
		$doc        = sn_resume_lane_doc( $m['raw'], 'operations' );
		$summary    = trim( (string) ( $doc['hero']['summary'] ?? '' ) );
		$m['first'] = '' === $summary ? '' : preg_split( '/(?<=[.!?])\s+(?=\p{Lu})/u', $summary, 2 )[0];
	}
	return $m[ $field ];
}

/**
 * Whether this request shows /resume's operations lane. The lane is read
 * first, so any other view does no more than that.
 *
 * @return bool
 */
function sn_resume_lane_address_active() {
	return function_exists( 'sn_resume_active_lane' ) && 'operations' === sn_resume_active_lane()
		&& is_page( 'resume' ) && function_exists( 'sn_jsonresume_page' ) && sn_jsonresume_page( 'resume' )
		&& function_exists( 'sn_resume_variant_config' ) && null !== sn_resume_variant_config( sn_resume_lane_address_memo( 'raw' ), 'operations' );
}

/**
 * The page's own address on the operations lane; any other URL untouched.
 *
 * @param string $url The permalink (or an override, which is left alone).
 * @return string
 */
function sn_resume_lane_address( $url ) {
	if ( ! sn_resume_lane_address_active() ) {
		return $url;
	}
	$page = get_queried_object();
	if ( ! $page || (string) $url !== (string) get_permalink( $page ) ) {
		return $url;
	}
	return add_query_arg( 'lane', 'operations', $url );
}
add_filter( 'get_canonical_url', 'sn_resume_lane_address', 20 );
add_filter( 'sn_seo_view_url', 'sn_resume_lane_address' );

/**
 * The operations lane's title: <title>, og:title and twitter:title.
 *
 * @param string $title The view's title.
 * @return string
 */
function sn_resume_lane_title( $title ) {
	if ( ! sn_resume_lane_address_active() ) {
		return $title;
	}
	$name = function_exists( 'sn_setting' ) ? (string) sn_setting( 'identity.person_name', get_bloginfo( 'name' ) ) : (string) get_bloginfo( 'name' );
	return $name . ' · Résumé · ' . SN_RESUME_LANE_LABELS['operations'];
}
add_filter( 'sn_seo_view_title', 'sn_resume_lane_title' );

/**
 * The operations lane's description (meta, og, twitter, the ProfilePage): the
 * first sentence of its summary as stored, never reworded. A sentence ends at
 * . ! or ? before a capital, so "the U.S. and" does not end one.
 * ponytail: an abbreviation before a capital ("U.S. Latin") would cut early; a
 * stored description field per lane is the upgrade if the summary ever reads so.
 *
 * @param string $description The view's description.
 * @return string
 */
function sn_resume_lane_description( $description ) {
	$first = sn_resume_lane_address_active() ? sn_resume_lane_address_memo( 'first' ) : '';
	return '' === $first ? $description : $first;
}
add_filter( 'sn_seo_view_description', 'sn_resume_lane_description' );

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
