<?php
/**
 * Signal & Noise: admin POST handlers for the Indexing report's two buttons
 * (2026-10-31). Each is fn( array $post ): string returning a ?sn_flash=… code,
 * reached BY NAME from sn_admin_post_handlers(), which checks the capability
 * and the nonce first.
 *
 * Actions served: gsc_mark_requested, gsc_check_now
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mark one row requested. The path must be a key of the inspection map that
 * Google has not indexed (snt_gsc_mark_requested() refuses anything else).
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_gsc_mark_requested( $post ) {
	// Only ever an isset() key into the map, never stored or printed unless it
	// is one: sanitize_text_field() would strip a percent-encoded path's octets.
	$path = isset( $post['path'] ) && is_string( $post['path'] ) ? (string) wp_unslash( $post['path'] ) : '';
	if ( ! function_exists( 'snt_gsc_mark_requested' ) || ! snt_gsc_mark_requested( $path, time() ) ) {
		return 'gsc_mark_invalid';
	}
	sn_indexing_refresh_stored_report();
	return 'gsc_marked';
}

/**
 * Check now: inspect the request list and the marked rows with Google, then
 * re-read the report so the rows show the answer.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_gsc_check_now( $post ) {
	unset( $post );
	if ( ! function_exists( 'snt_gsc_check_now' ) ) {
		return 'gsc_not_ready';
	}
	$d         = snt_gsc_coverage_data();
	$entries   = is_array( $d ) ? (array) ( $d['entries'] ?? array() ) : array();
	$published = array();
	foreach ( $entries as $e ) {
		if ( snt_gsc_index_gap_candidate( $e ) ) {
			$published[ (int) $e['post_id'] ] = (int) get_post_time( 'U', true, (int) $e['post_id'] );
		}
	}
	$now = time();
	$r   = snt_gsc_check_now( $now, snt_gsc_check_now_targets( $entries, $published, snt_gsc_requested_marks(), $now, snt_gsc_check_now_since() ) );
	if ( is_wp_error( $r ) ) {
		return 'snt_gsc_check_wait' === $r->get_error_code() ? 'gsc_check_wait' : 'gsc_not_ready';
	}
	sn_indexing_refresh_stored_report();
	if ( $r['remaining'] > 0 ) {
		return 'gsc_check_partial';
	}
	return $r['errors'] > 0 ? 'gsc_checked_errors' : 'gsc_checked';
}
