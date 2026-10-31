<?php
/**
 * Signal & Noise Tools: the Indexing check, REPORT ONLY (Integrity › Reports).
 *
 * The motion_scan shape: zero findings by design, the payload rides the pack
 * as a `report` key, painted by sn_health_render_indexing_report() (classic)
 * and tools_reports_render_indexing() (native). The lists are the owner's own
 * action list: which URLs to open in Search Console and request.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/search-console-indexing-live.php';
require_once __DIR__ . '/search-console-requested.php';

/**
 * Check: Indexing (report only).
 *
 * @return array
 */
function sn_health_check_indexing() {
	$packed = sn_health_pack_check(
		__( 'Indexing: what to request, what is stuck, what a page lacks (report only)', 'signal-and-noise-tools' ),
		array(), // Report-first: the lists are the deliverable.
		__( 'Report only: Google lets a site request indexing for job postings and livestreams only, so for a note it is one click in Search Console. Mark the row requested here, and Check now reads what Google did since.', 'signal-and-noise-tools' ),
		null
	);
	$report           = sn_indexing_report();
	$packed['report'] = array_merge( $report, sn_indexing_request_extras( $report, time() ), array(
		'coverage' => sn_health_report_coverage( 'indexing' ), // One copy: inc/health-report-coverage.php.
	) );
	return $packed;
}

/**
 * The owner's side of the report (2026-10-31): each listed row's status
 * against its "requested" mark, the marked pages Google has since indexed, and
 * the last Check now. On the health report only, never in sn_indexing_report(),
 * which the indexing-requests ability and its remote twin return: their
 * contract does not move.
 *
 * @param array $report sn_indexing_report().
 * @param int   $now
 * @return array{request_status:array,indexed_after_request:array,checked_now:?array}
 */
function sn_indexing_request_extras( array $report, $now ) {
	$d     = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	$paths = array_merge( array_column( (array) ( $report['request_list'] ?? array() ), 'path' ), array_column( (array) ( $report['stuck'] ?? array() ), 'path' ) );
	$x     = snt_gsc_request_statuses( is_array( $d ) ? (array) ( $d['entries'] ?? array() ) : array(), snt_gsc_requested_marks(), $paths, $now );
	$last  = get_option( SNT_GSC_CHECK_NOW_LAST, null );
	return array(
		'request_status'        => $x['status'],
		'indexed_after_request' => $x['indexed_after_request'],
		'checked_now'           => is_array( $last ) ? $last : null,
	);
}

/**
 * Re-read the indexing check into the stored scan after a button press, so the
 * screen shows the answer now. Written straight to the option: a press is not
 * a scan, and sn_health_store_scan() would add a scan-history row.
 *
 * @return bool False when no scan is stored yet.
 */
function sn_indexing_refresh_stored_report() {
	$scan = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	if ( ! is_array( $scan ) || ! isset( $scan['checks']['indexing'] ) ) {
		return false;
	}
	$scan['checks']['indexing'] = sn_health_check_indexing();
	update_option( SN_HEALTH_CACHE_KEY, $scan, false );
	return true;
}
