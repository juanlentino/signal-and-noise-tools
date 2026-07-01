<?php
/**
 * Signal & Noise Tools — Content-Health scan summary accessors.
 *
 * Pure, side-effect-free projections over a cached sn_health_last_scan() array,
 * shared by every surface that summarizes the scan so they can never disagree on
 * "what is off":
 *   - the Dashboard tab first-glance Health card + attention strip
 *     (inc/admin-tab-dashboard.php),
 *   - the "S&N Health" home dashboard widget (inc/site-health-widget.php).
 *
 * All Content-Health checks are FAULT checks: a check's `count` is a count of
 * problems. External "link rot" (external_links) is included — it counts only
 * rotted citations (4xx/5xx/network failures), not a catalog of every external
 * link — so it is a finding like any other, exactly as the Health tab treats it
 * (inc/health-checks-admin.php splits with-findings from passing purely on
 * count>0). The finding total is therefore a flat sum of every check's count.
 *
 * @package SignalNoiseTools
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Total findings across every check in a scan.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return int Sum of every check's count (0 when there is no scan / no checks).
 */
function sn_health_finding_total( $scan ) {
	$total = 0;
	if ( ! is_array( $scan ) ) {
		return $total;
	}
	foreach ( (array) ( $scan['checks'] ?? array() ) as $check ) {
		$total += (int) ( $check['count'] ?? 0 );
	}
	return $total;
}

/**
 * The checks that have findings, ranked by count (descending).
 *
 * Equal counts keep their scan (definition) order — PHP 8's sort is stable.
 *
 * @param array|null $scan A sn_health_last_scan() array (or null / non-array).
 * @return array<string,array> key => check envelope, count>0 only, count-desc.
 */
function sn_health_flagged_checks( $scan ) {
	if ( ! is_array( $scan ) ) {
		return array();
	}
	$flagged = array();
	foreach ( (array) ( $scan['checks'] ?? array() ) as $key => $check ) {
		if ( (int) ( $check['count'] ?? 0 ) > 0 ) {
			$flagged[ $key ] = $check;
		}
	}
	uasort( $flagged, static function ( $a, $b ) {
		return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
	} );
	return $flagged;
}
