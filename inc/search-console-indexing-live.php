<?php
/**
 * Signal & Noise Tools: the indexing guard, the LIVE half.
 *
 * Reads what is already stored (the weekly URL Inspection map, the link
 * graph, the post table) and hands it to sn_indexing_build(). Never inspects,
 * never pings, never requests indexing: reading this spends no quota.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/search-console-indexing.php';

/**
 * Published notes and pages as publish-check subjects.
 *
 * @return array<int,array>
 */
function sn_indexing_subjects() {
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$posts = (array) get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => defined( 'SNT_GSC_COVERAGE_MAX_URLS' ) ? SNT_GSC_COVERAGE_MAX_URLS : 200, 'no_found_rows' => true ) );
	$out   = array();
	foreach ( $posts as $p ) {
		if ( ! is_object( $p ) ) {
			continue;
		}
		$id   = (int) $p->ID;
		$url  = (string) get_permalink( $p );
		$en   = 'page' === $p->post_type ? (int) get_post_meta( $id, defined( 'SN_LANG_PAIR_META' ) ? SN_LANG_PAIR_META : '_sn_en_counterpart', true ) : 0;
		$at   = strtotime( (string) $p->post_date_gmt . ' UTC' );
		$out[] = array(
			'post_id'   => $id,
			'path'      => sn_path_join_key( $url ),
			'url'       => $url,
			'type'      => (string) $p->post_type,
			'published' => false === $at ? 0 : (int) $at,
			'indexable' => sn_post_is_indexable( $p ),
			'canonical' => (string) get_post_meta( $id, '_sn_canonical_url', true ),
			'core'      => 'page' === $p->post_type && sn_indexing_is_core( $url, $en > 0 ? (string) get_permalink( $en ) : '' ),
			'en_path'   => $en > 0 ? sn_path_join_key( (string) get_permalink( $en ) ) : '',
		);
	}
	return $out;
}

/**
 * The report, read live from the stores. generated_at is now; coverage_run_at
 * is when the URL Inspection map last moved (null before the first run).
 *
 * @return array
 */
function sn_indexing_report() {
	$d       = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	$entries = is_array( $d ) ? (array) $d['entries'] : array();
	$inbound = function_exists( 'snt_ml_inbound_by_path' ) ? snt_ml_inbound_by_path() : null;
	$prop    = function_exists( 'sn_setting' ) ? (string) sn_setting( 'search_console.property', '' ) : '';
	return array_merge(
		sn_indexing_build( $entries, sn_indexing_subjects(), $inbound, time(), $prop, sn_indexing_chrome_paths() ),
		array(
			'generated_at'    => time(),
			'coverage_run_at' => is_array( $d ) ? (int) $d['synced_at'] : null,
		)
	);
}
