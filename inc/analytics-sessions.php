<?php
/**
 * Signal & Noise — cookieless within-day session engine (v8.8.0).
 *
 * Reads the existing sn_pageviews Analytics Engine data as WITHIN-DAY visits.
 * index1 (the daily-rotating visitor hash) may be grouped ONLY inside a single
 * UTC day — never across days (the salt rotates at UTC midnight, so cross-day
 * stitching is impossible by construction). No cookie, no device storage, no
 * consent trigger. This is the deliberate, documented revision of the prior
 * "index1 is count-only" principle.
 *
 * Pure transforms only — NO top-level add_action/add_filter, so the module loads
 * standalone under the CLI test harness.
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SESSION_GAP_SEC     = 1800;  // >30 min idle starts a new visit.
const SN_ANALYTICS_SESSION_ENGAGED_PCT = 50;    // engaged read: scroll depth % floor.
const SN_ANALYTICS_SESSION_ENGAGED_MS  = 15000; // engaged read: dwell ms floor.
const SN_ANALYTICS_SESSION_ROW_CAP     = 50000; // max raw rows pulled per window.

/**
 * Filterable session-engine config. Constants are the defaults; the
 * 'sn_analytics_session_config' filter lets a site override any key.
 *
 * @return array{gap_sec:int,engaged_scroll:int,engaged_ms:int,row_cap:int}
 */
function sn_analytics_session_config() {
	$cfg = array(
		'gap_sec'        => SN_ANALYTICS_SESSION_GAP_SEC,
		'engaged_scroll' => SN_ANALYTICS_SESSION_ENGAGED_PCT,
		'engaged_ms'     => SN_ANALYTICS_SESSION_ENGAGED_MS,
		'row_cap'        => SN_ANALYTICS_SESSION_ROW_CAP,
	);
	$out = (array) apply_filters( 'sn_analytics_session_config', $cfg );
	// Coerce back to ints so a bad filter can't poison the SQL/int math.
	return array(
		'gap_sec'        => max( 60, (int) ( $out['gap_sec'] ?? $cfg['gap_sec'] ) ),
		'engaged_scroll' => max( 0, min( 100, (int) ( $out['engaged_scroll'] ?? $cfg['engaged_scroll'] ) ) ),
		'engaged_ms'     => max( 0, (int) ( $out['engaged_ms'] ?? $cfg['engaged_ms'] ) ),
		'row_cap'        => max( 100, (int) ( $out['row_cap'] ?? $cfg['row_cap'] ) ),
	);
}

/**
 * Group raw events into within-day visits.
 *
 * @param array $rows    Rows with keys vid, ts (int epoch), ev, path, ref, ce, scroll, dwell.
 * @param int   $gap_sec Idle gap that starts a new visit. Default from config.
 * @return array List of visits; each visit is an ordered list of event rows.
 */
function sn_sessionize( array $rows, $gap_sec = SN_ANALYTICS_SESSION_GAP_SEC ) {
	$gap_sec = max( 1, (int) $gap_sec );

	// Bucket by visitor id.
	$by_vid = array();
	foreach ( $rows as $r ) {
		$vid = isset( $r['vid'] ) ? (string) $r['vid'] : '';
		if ( '' === $vid ) {
			continue;
		}
		$r['ts']          = (int) ( $r['ts'] ?? 0 );
		$by_vid[ $vid ][] = $r;
	}

	$visits = array();
	foreach ( $by_vid as $events ) {
		usort(
			$events,
			function ( $a, $b ) {
				return $a['ts'] <=> $b['ts'];
			}
		);
		$current = array();
		$prev_ts = null;
		foreach ( $events as $e ) {
			if ( null !== $prev_ts && ( $e['ts'] - $prev_ts ) > $gap_sec ) {
				$visits[]  = $current;
				$current   = array();
			}
			$current[] = $e;
			$prev_ts   = $e['ts'];
		}
		if ( ! empty( $current ) ) {
			$visits[] = $current;
		}
	}
	return $visits;
}
