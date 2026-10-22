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

/**
 * Check: Indexing (report only).
 *
 * @return array
 */
function sn_health_check_indexing() {
	$packed = sn_health_pack_check(
		__( 'Indexing: what to request, what is stuck, what a page lacks (report only)', 'signal-and-noise-tools' ),
		array(), // Report-first: the lists are the deliverable.
		__( 'Report only: open each Inspect link in Search Console and click Request indexing. The API cannot request indexing, and nothing here tries.', 'signal-and-noise-tools' ),
		null
	);
	$packed['report'] = array_merge( sn_indexing_report(), array(
		'coverage' => sn_health_report_coverage( 'indexing' ), // One copy: inc/health-report-coverage.php.
	) );
	return $packed;
}
