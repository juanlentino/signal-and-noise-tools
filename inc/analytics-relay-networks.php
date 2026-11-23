<?php
/**
 * Which network numbers the relay rule's readers arrive from (owner,
 * 2026-11-22). The analytics worker presumes a page view human when the
 * network NAME matches RELAY_ASN (akamai|fastly, mirrored here as
 * SNT_ANALYTICS_RELAY_ASN_SOURCE) and the browser is Safari on iOS or macOS:
 * iCloud Private Relay. The same network with another browser is suspect.
 * The rule matches by name, never by AS number; this lists the numbers
 * behind the names so the owner can see them before the rule is changed.
 *
 * The AS number is double12 of a second-generation row (analytics worker
 * README, "double12: network AS number"; src/index.js writes
 * [...doubles10, pid, asn, ...]). A legacy row has no such double and reads 0.
 *
 * Read on demand, never stored; nothing is changed or subtracted. No visitor
 * code, IP address or user agent leaves this file.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-vid.php'; // SN_AE_VID: the visitor-day hash, the first 16 characters of index1.
require_once __DIR__ . '/analytics-generation.php'; // the split bound a stitched read interpolates.
require_once __DIR__ . '/analytics-network-terms.php'; // the worker's RELAY_ASN, as text and as ILIKE terms.

const SNT_RELAY_NETWORKS_MAX        = 200;  // groups returned.
const SNT_RELAY_NETWORKS_READ_LIMIT = 5000; // groups read per half; well under the AE 10,000-row cap.
const SNT_RELAY_NETWORKS_CLASSES    = array( 'human', 'suspect', 'bot' );

/**
 * Page views on the relay-named networks, one row per (AS number, network
 * name, stored class, browser, OS). Only shapes live Analytics Engine already
 * runs: ILIKE in WHERE (the counted rule), toUInt32(double) grouped by its
 * alias (resume_lane), count(DISTINCT SN_AE_VID), ORDER BY an alias. PURE.
 *
 * @param int    $days   Trailing window, clamped to 1..90.
 * @param string $source One half's dataset.
 * @param string $range  That half's side of the split, or ''.
 * @return string
 */
function sn_relay_networks_sql( $days, $source, $range ) {
	$days  = max( 1, min( 90, (int) $days ) );
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return 'SELECT toUInt32(double12) AS asn, blob12 AS network, blob7 AS class, blob8 AS browser, blob9 AS os, sum(_sample_interval) AS views, count(DISTINCT ' . SN_AE_VID . ') AS visitor_days'
		. ' FROM ' . (string) $source
		. " WHERE timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY){$range} AND blob1 = 'pv' AND " . sn_analytics_org_ilike_any( sn_analytics_network_terms()['relay'] )
		. ' GROUP BY asn, network, class, browser, os ORDER BY views DESC LIMIT ' . SNT_RELAY_NETWORKS_READ_LIMIT;
}

/**
 * Whether a folded row is Safari on iOS or macOS, the browser half of the
 * relay rule (the worker's browserFrom() and osFrom() names). PURE.
 *
 * @param array $row A folded row.
 * @return bool
 */
function sn_relay_networks_is_safari_apple( array $row ) {
	return 'Safari' === $row['browser'] && in_array( $row['os'], array( 'iOS', 'macOS' ), true );
}

/**
 * The rows and the summary from the halves of a stitched read. PURE. The
 * split sits on the visitor hash's rotation, so one group's counts from the
 * two halves add. The summary is read from every group, before the cap.
 *
 * @param array<int,array> $sets Row sets from sn_analytics_stitched_rows().
 * @return array{rows:array,truncated:bool,summary:array}
 */
function sn_relay_networks_fold( array $sets ) {
	$text  = static fn( $v ) => snt_arows_clean( 'city', (string) $v, array() ); // printable, at most 128, as the place names.
	$by    = array();
	$limit = false;
	foreach ( $sets as $set ) {
		$limit = $limit || count( $set ) >= SNT_RELAY_NETWORKS_READ_LIMIT;
		foreach ( $set as $r ) {
			$r   = (array) $r;
			$row = array(
				'asn'     => max( 0, (int) ( $r['asn'] ?? 0 ) ),
				'network' => $text( $r['network'] ?? '' ),
				'class'   => in_array( (string) ( $r['class'] ?? '' ), SNT_RELAY_NETWORKS_CLASSES, true ) ? (string) $r['class'] : '(unknown)',
				'browser' => $text( $r['browser'] ?? '' ),
				'os'      => $text( $r['os'] ?? '' ),
			);
			$key = implode( "\x1F", $row );
			$by[ $key ] ??= $row + array( 'views' => 0.0, 'visitor_days' => 0 );
			$by[ $key ]['views']        += (float) ( $r['views'] ?? 0 );
			$by[ $key ]['visitor_days'] += (int) ( $r['visitor_days'] ?? 0 );
		}
	}
	$zero  = array( 'human' => 0, 'suspect' => 0, 'bot' => 0, 'unknown' => 0 );
	$sum   = array( 'views' => 0, 'relay_human' => array( 'views' => 0, 'asn_unrecorded_views' => 0, 'asns' => array() ), 'safari_apple' => $zero, 'not_safari_apple' => $zero );
	$asns  = array();
	$rows  = array();
	foreach ( $by as $row ) {
		$row['views'] = (int) round( $row['views'] );
		$rows[]       = $row;
		$views        = $row['views'];
		$apple        = sn_relay_networks_is_safari_apple( $row );
		$sum['views'] += $views;
		$sum[ $apple ? 'safari_apple' : 'not_safari_apple' ][ '(unknown)' === $row['class'] ? 'unknown' : $row['class'] ] += $views;
		if ( $apple && 'human' === $row['class'] ) {
			$sum['relay_human']['views'] += $views;
			if ( 0 === $row['asn'] ) {
				$sum['relay_human']['asn_unrecorded_views'] += $views;
			} else {
				$asns[ $row['asn'] ] = ( $asns[ $row['asn'] ] ?? 0 ) + $views;
			}
		}
	}
	arsort( $asns );
	foreach ( $asns as $asn => $views ) {
		$sum['relay_human']['asns'][] = array( 'asn' => (int) $asn, 'views' => $views );
	}
	usort( $rows, static fn( $a, $b ) => $b['views'] <=> $a['views'] ?: $a['asn'] <=> $b['asn'] );
	return array(
		'rows'      => array_slice( $rows, 0, SNT_RELAY_NETWORKS_MAX ),
		'truncated' => $limit || count( $rows ) > SNT_RELAY_NETWORKS_MAX,
		'summary'   => $sum,
	);
}
