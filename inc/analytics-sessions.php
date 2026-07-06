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
 * Auto-derived + optional code-defined funnels. A site can add named funnels via
 * the 'sn_analytics_session_funnels' filter; nothing is required for the view to
 * work (transitions + quality render regardless).
 *
 * @return array List of array{title:string,steps:array}.
 */
function sn_analytics_session_funnels() {
	$defaults = array(
		array(
			'title' => __( 'Home → post → subscribe', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
			),
		),
	);
	return (array) apply_filters( 'sn_analytics_session_funnels', $defaults );
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
	$max_dwell  = array(); // path => max dwell ms
	$first_ts   = null;
	$last_ts    = null;
	$seq        = array(); // compact ordered events for funnel matching

	foreach ( $events as $e ) {
		$ts       = (int) ( $e['ts'] ?? 0 );
		$first_ts = ( null === $first_ts ) ? $ts : min( $first_ts, $ts );
		$last_ts  = ( null === $last_ts ) ? $ts : max( $last_ts, $ts );
		$type     = (string) ( $e['ev'] ?? '' );
		$p        = (string) ( $e['path'] ?? '' );
		$seq[]    = array( 'ev' => $type, 'path' => $p, 'ce' => (string) ( $e['ce'] ?? '' ) );

		if ( 'pv' === $type ) {
			$path[] = $p;
		} elseif ( 'sc' === $type ) {
			$max_scroll[ $p ] = max( $max_scroll[ $p ] ?? 0, (float) ( $e['scroll'] ?? 0 ) );
		} elseif ( 'tm' === $type ) {
			$max_dwell[ $p ] = max( $max_dwell[ $p ] ?? 0, (float) ( $e['dwell'] ?? 0 ) );
		} elseif ( 'ce' === $type ) {
			$name = (string) ( $e['ce'] ?? '' );
			if ( '' !== $name ) {
				$goals[] = $name;
			}
		}
	}

	$engaged = false;
	foreach ( $path as $p ) {
		if ( ( $max_scroll[ $p ] ?? 0 ) >= $engaged_scroll && ( $max_dwell[ $p ] ?? 0 ) >= $engaged_ms ) {
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
 * @return array{visits:int,bounce_rate:float,pages_per_visit:float,median_duration:int,engaged_visits:int,engaged_rate:float}
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
		);
	}

	$bounces    = 0;
	$pv_total   = 0;
	$engaged    = 0;
	$durations  = array();
	foreach ( $summaries as $s ) {
		$pv = (int) ( $s['pageviews'] ?? 0 );
		$pv_total += $pv;
		if ( $pv <= 1 ) {
			$bounces++;
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

/**
 * Does one compact event (from a summary 'events' list) satisfy a funnel step?
 *
 * @param array $step  array{match:string,value:string,prefix?:bool}.
 * @param array $event array{ev:string,path:string,ce:string}.
 * @return bool
 */
function sn_funnel_step_matches( array $step, array $event ) {
	$match  = (string) ( $step['match'] ?? '' );
	$value  = (string) ( $step['value'] ?? '' );
	$prefix = ! empty( $step['prefix'] );

	if ( 'path' === $match ) {
		if ( 'pv' !== (string) ( $event['ev'] ?? '' ) ) {
			return false;
		}
		$path = (string) ( $event['path'] ?? '' );
		return $prefix ? ( 0 === strncmp( $path, $value, strlen( $value ) ) ) : ( $path === $value );
	}
	if ( 'ce' === $match ) {
		return 'ce' === (string) ( $event['ev'] ?? '' ) && (string) ( $event['ce'] ?? '' ) === $value;
	}
	return false;
}

/**
 * Aggregate ordered-step completion across visits.
 *
 * @param array $summaries Visit summaries (each with an ordered 'events' list).
 * @param array $funnel    Ordered steps.
 * @return array List of array{label:string,reached:int,rate:float,drop:int}.
 */
function sn_funnel_report( array $summaries, array $funnel ) {
	$steps = array_values( $funnel );
	$n     = count( $steps );
	if ( 0 === $n ) {
		return array();
	}
	$reached = array_fill( 0, $n, 0 );

	foreach ( $summaries as $s ) {
		$events = isset( $s['events'] ) ? (array) $s['events'] : array();
		$idx    = 0;
		foreach ( $events as $e ) {
			if ( $idx >= $n ) {
				break;
			}
			if ( sn_funnel_step_matches( $steps[ $idx ], (array) $e ) ) {
				$reached[ $idx ]++;
				$idx++;
			}
		}
	}

	$first = $reached[0] > 0 ? $reached[0] : 0;
	$out   = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$label = (string) ( $steps[ $i ]['value'] ?? ( 'step ' . ( $i + 1 ) ) );
		$prev  = $i > 0 ? $reached[ $i - 1 ] : $reached[ $i ];
		$out[] = array(
			'label'   => $label,
			'reached' => $reached[ $i ],
			'rate'    => ( $first > 0 ) ? ( $reached[ $i ] / $first ) : 0.0,
			'drop'    => ( $i > 0 ) ? max( 0, $prev - $reached[ $i ] ) : 0,
		);
	}
	return $out;
}

/**
 * Build the AE SQL that pulls raw human events for sessionization. Returns ''
 * (so the caller no-ops) if the window or class is invalid. class is strictly
 * whitelisted and dates are format-validated — the only interpolated values —
 * so the string is injection-safe against the AE SQL API.
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class (human/suspect/bot).
 * @param int    $cap   Row cap (LIMIT).
 * @return string SQL, or '' when inputs are invalid.
 */
function sn_analytics_session_sql( $from, $to, $class, $cap ) {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from )
		|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return '';
	}
	$allowed = defined( 'SN_ANALYTICS_CLASSES' ) ? SN_ANALYTICS_CLASSES : array( 'human', 'suspect', 'bot' );
	if ( ! in_array( (string) $class, $allowed, true ) ) {
		return '';
	}
	$cap     = max( 1, (int) $cap );
	$dataset = defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews';

	return implode(
		' ',
		array(
			'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts,',
			'blob1 AS ev, blob2 AS path, blob3 AS ref, blob16 AS ce,',
			'double1 AS scroll, double2 AS dwell',
			'FROM ' . $dataset,
			// AE's SQL types are strict: the DateTime `timestamp` column cannot be
			// compared to a String literal (>= 422s), so wrap the validated bounds
			// in toDateTime(). $from/$to are regex-checked Y-m-d above.
			"WHERE timestamp >= toDateTime('{$from} 00:00:00') AND timestamp <= toDateTime('{$to} 23:59:59')",
			"AND blob7 = '{$class}'",
			"AND blob1 IN ('pv','sc','tm','ce')",
			// No ORDER BY: AE resolves ORDER BY against SELECT aliases (not raw
			// columns), so `index1`/`timestamp` both 422. sn_sessionize sorts each
			// visitor's events by ts in PHP anyway; the row cap only binds far above
			// this site's volume, where the `capped` flag already warns.
			"LIMIT {$cap}",
		)
	);
}

/**
 * Fetch + sessionize + summarize a window into visit summaries. Returns an
 * array with the summaries plus a 'capped' flag (true when the row cap was hit,
 * so the view can warn instead of silently truncating).
 *
 * @param string $from  Window start, 'Y-m-d'.
 * @param string $to    Window end, 'Y-m-d'.
 * @param string $class Traffic class.
 * @return array{summaries:array,visits:array,capped:bool,configured:bool}
 */
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	$cfg = sn_analytics_session_config();
	$sql = sn_analytics_session_sql( $from, $to, $class, $cfg['row_cap'] );
	if ( '' === $sql || ! function_exists( 'sn_analytics_query' ) ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	$rows = sn_analytics_query( $sql );
	if ( ! is_array( $rows ) ) {
		return array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => false );
	}
	$capped = count( $rows ) >= $cfg['row_cap'];
	$visits = sn_sessionize( $rows, $cfg['gap_sec'] );
	$summaries = array();
	foreach ( $visits as $v ) {
		$summaries[] = sn_visit_summary( $v, $cfg['engaged_scroll'], $cfg['engaged_ms'] );
	}
	return array( 'summaries' => $summaries, 'visits' => $visits, 'capped' => $capped, 'configured' => true );
}
