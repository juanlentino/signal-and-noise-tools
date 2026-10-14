<?php
/**
 * Signal & Noise Tools: Analytics Sessions, the pure visit transforms (sessionize, the visit summary, pageview visits, session metrics, top paths).
 *
 * Split from inc/analytics-sessions.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		// $events is never empty (only populated vids reach $by_vid), so the inner
		// loop always ran and $current holds at least the final event.
		$visits[] = $current;
	}
	return $visits;
}

/**
 * Summarize one visit (list of ordered events) into a struct.
 *
 * Known simplification: if a path is viewed more than once in a visit, scroll
 * and dwell are attributed to that path in aggregate (max), not to a specific
 * repeat view — acceptable at this site's volume.
 *
 * @param array $events         Ordered events of a single visit.
 * @param int   $engaged_scroll Scroll % floor for an engaged read.
 * @param int   $engaged_ms     Dwell ms floor for an engaged read.
 * @return array Visit summary struct.
 */
function sn_visit_summary( array $events, $engaged_scroll = SN_ANALYTICS_SESSION_ENGAGED_PCT, $engaged_ms = SN_ANALYTICS_SESSION_ENGAGED_MS ) {
	$path       = array();
	$goals      = array();
	$max_scroll = array(); // path => max scroll %
	$dwell_sum  = array(); // path => max dwell ms
	$first_ts   = null;
	$last_ts    = null;
	$seq        = array(); // compact ordered events for funnel matching

	foreach ( $events as $e ) {
		$ts       = (int) ( $e['ts'] ?? 0 );
		$first_ts = ( null === $first_ts ) ? $ts : min( $first_ts, $ts );
		$last_ts  = ( null === $last_ts ) ? $ts : max( $last_ts, $ts );
		$type     = (string) ( $e['ev'] ?? '' );
		$p        = (string) ( $e['path'] ?? '' );
		// The name is read only off a `ce` row. In the second-generation dataset
		// the same column holds a pageview's UTM source, which is not an event.
		$seq[]    = array( 'ev' => $type, 'path' => $p, 'ce' => 'ce' === $type ? (string) ( $e['ce'] ?? '' ) : '' );

		if ( 'pv' === $type ) {
			$path[] = $p;
		} elseif ( 'sc' === $type ) {
			$max_scroll[ $p ] = max( $max_scroll[ $p ] ?? 0, (float) ( $e['scroll'] ?? 0 ) );
		} elseif ( 'tm' === $type ) {
			// tm is a per-flush DELTA (sn-beacon.js v10.44.4): sum the slices.
			$dwell_sum[ $p ] = ( $dwell_sum[ $p ] ?? 0 ) + (float) ( $e['dwell'] ?? 0 );
		} elseif ( 'ce' === $type ) {
			$name = (string) ( $e['ce'] ?? '' );
			if ( '' !== $name ) {
				$goals[] = $name;
			}
		}
	}

	$engaged = false;
	foreach ( $path as $p ) {
		if ( ( $max_scroll[ $p ] ?? 0 ) >= $engaged_scroll && ( $dwell_sum[ $p ] ?? 0 ) >= $engaged_ms ) {
			$engaged = true;
			break;
		}
	}

	return array(
		'entry'     => $path ? $path[0] : '',
		'exit'      => $path ? $path[ count( $path ) - 1 ] : '',
		'path'      => $path,
		'pageviews' => count( $path ),
		'duration'  => ( null === $first_ts ) ? 0 : ( $last_ts - $first_ts ),
		'engaged'   => $engaged,
		'goals'     => array_values( array_unique( $goals ) ),
		'events'    => $seq,
	);
}

/**
 * Keep only visits that contain at least one pageview.
 *
 * A "visit" = a within-day index1 group with >= 1 pageview. Groups made only of
 * server events (srv:1 / RSS ce), scroll, or timing beacons with NO pageview are
 * not visits and belong to the Events view — an RSS feed reader polling hourly
 * would otherwise gap-split into dozens of phantom pageview-less "visits" and
 * corrupt bounce / pages-per-visit / median-duration.
 *
 * @param array $summaries Visit summaries from sn_visit_summary().
 * @return array Re-indexed list of summaries with pageviews >= 1.
 */
function sn_pageview_visits( array $summaries ) {
	$visits = array();
	foreach ( $summaries as $s ) {
		if ( (int) ( $s['pageviews'] ?? 0 ) >= 1 ) {
			$visits[] = $s;
		}
	}
	return $visits;
}

/**
 * Aggregate visit-quality metrics from a list of visit summaries.
 *
 * @param array $summaries Visit summaries from sn_visit_summary().
 * @return array{visits:int,bounce_rate:float,pages_per_visit:float,median_duration:int,engaged_visits:int,engaged_rate:float,two_page_visits:int,deep_visits:int}
 */
function sn_session_metrics( array $summaries ) {
	$n = count( $summaries );
	if ( 0 === $n ) {
		return array(
			'visits'          => 0,
			'bounce_rate'     => 0.0,
			'pages_per_visit' => 0.0,
			'median_duration' => 0,
			'engaged_visits'  => 0,
			'engaged_rate'    => 0.0,
			'two_page_visits' => 0,
			'deep_visits'     => 0,
		);
	}

	$bounces    = 0;
	$two        = 0; // visits of exactly two pageviews.
	$deep       = 0; // visits of three or more.
	$pv_total   = 0;
	$engaged    = 0;
	$durations  = array();
	foreach ( $summaries as $s ) {
		$pv = (int) ( $s['pageviews'] ?? 0 );
		$pv_total += $pv;
		if ( $pv <= 1 ) {
			$bounces++;
		} elseif ( 2 === $pv ) {
			$two++;
		} else {
			$deep++;
		}
		if ( ! empty( $s['engaged'] ) ) {
			$engaged++;
		}
		$durations[] = (int) ( $s['duration'] ?? 0 );
	}

	sort( $durations );
	$mid    = intdiv( $n, 2 );
	$median = ( 0 === $n % 2 )
		? (int) round( ( $durations[ $mid - 1 ] + $durations[ $mid ] ) / 2 )
		: $durations[ $mid ];

	return array(
		'visits'          => $n,
		'bounce_rate'     => $bounces / $n,
		'pages_per_visit' => $pv_total / $n,
		'median_duration' => $median,
		'engaged_visits'  => $engaged,
		'engaged_rate'    => $engaged / $n,
		'two_page_visits' => $two,
		'deep_visits'     => $deep,
	);
}

/**
 * Count consecutive page-to-page transitions across visits, most common first.
 *
 * @param array $summaries Visit summaries (each with a 'path' list).
 * @param int   $limit     Max transitions to return.
 * @return array List of array{from:string,to:string,count:int}.
 */
function sn_session_paths( array $summaries, $limit = 20 ) {
	$counts = array(); // "from\x00to" => count
	foreach ( $summaries as $s ) {
		$path = isset( $s['path'] ) ? array_values( (array) $s['path'] ) : array();
		for ( $i = 1, $len = count( $path ); $i < $len; $i++ ) {
			$key            = $path[ $i - 1 ] . "\x00" . $path[ $i ];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	arsort( $counts );
	$out = array();
	foreach ( $counts as $key => $count ) {
		list( $from, $to ) = explode( "\x00", $key, 2 );
		$out[] = array(
			'from'  => $from,
			'to'    => $to,
			'count' => (int) $count,
		);
		if ( count( $out ) >= (int) $limit ) {
			break;
		}
	}
	return $out;
}

