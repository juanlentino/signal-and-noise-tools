<?php
/**
 * Signal & Noise: edge-analytics rollup, the 5xx reads (the errors reading and range, the paths by day, the asker, the days shape and totals, the read marks and annotations, the errors days, the machine split).
 *
 * Split from inc/edge-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 17.8.1 (#1002): the 5xx rows read back. sn_edge_errors_dims() has stored
 * which paths failed and who answered since 13.96.3, and nothing read them:
 * sn_edge_top_dim() was only ever asked for country, colo and threat. This
 * is the one reader the Edge panels and `cloudflare-status` share.
 *
 * @param int         $days  Window, in days, ending today (UTC).
 * @param string|null $today YYYY-MM-DD reference day; defaults to now, UTC.
 * @return array{from:string,to:string,total:int,paths:array,sources:array}
 */
function sn_edge_errors_reading( $days = 7, $today = null ) {
	$to   = null === $today ? gmdate( 'Y-m-d' ) : (string) $today;
	$from = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - max( 0, (int) $days - 1 ) * DAY_IN_SECONDS );
	return sn_edge_errors_range( $from, $to );
}

/**
 * The same reading over an explicit inclusive [$from,$to] (YYYY-MM-DD), for a
 * panel that already has its own range.
 *
 * @param string $from First day.
 * @param string $to   Last day.
 * @return array{from:string,to:string,total:int,paths:array,paths_by_status:array,sources:array,days:array,asked_by:array,paths_by_day:array}
 */
function sn_edge_errors_range( $from, $to ) {
	$from    = (string) $from;
	$to      = (string) $to;
	$paths   = sn_edge_top_dim( 'err_path', $from, $to, 10 );
	$sources = array();
	foreach ( sn_edge_top_dim( 'err_source', $from, $to, 10 ) as $row ) {
		$row['label'] = sn_edge_error_source_label( $row['value'] );
		$sources[]    = $row;
	}
	// 18.2.0: one row per day, so the day a filter changed is visible instead
	// of averaged into the week, and the week's total is the full sum rather
	// than the top ten sources' share of it.
	$days     = function_exists( 'sn_edge_errors_days' ) ? sn_edge_errors_days( $from, $to ) : array();
	// 18.3.0: each day says whether it was read, so a 0 is never ambiguous.
	$days     = sn_edge_errors_days_annotate(
		$days,
		function_exists( 'get_option' ) ? (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() ) : array(),
		gmdate( 'Y-m-d' )
	);
	$asked_by = sn_edge_errors_asked_by_totals( $days );
	return array(
		'from'        => $from,
		'to'          => $to,
		// 17.9.1: sampled rows before this day were counted twice over.
		'honest_from' => function_exists( 'get_option' ) ? (string) get_option( SN_EDGE_HONEST_FROM_OPT, '' ) : '',
		// 17.9.3: the errors query's last outcome; a non-empty error means the
		// window below was NOT read, which is not the same as no errors.
		'query'       => function_exists( 'get_option' ) ? get_option( SN_EDGE_ERRORS_QUERY_OPT, null ) : null,
		'total'       => $days ? (int) array_sum( array_column( $days, 'total' ) ) : (int) array_sum( array_column( $sources, 'requests' ) ),
		'paths'       => $paths,
		// #1006: path and status together, "520 dynamic /wp-json/...".
		'paths_by_status' => sn_edge_top_dim( 'err_path_status', $from, $to, 10 ),
		'sources'     => $sources,
		'days'        => $days,
		'asked_by'    => $asked_by,
		// Each day's top paths with who asked; local readers only (the
		// remote twin copies named keys, so this never reaches it).
		'paths_by_day' => sn_edge_errors_paths_by_day( $from, $to ),
	);
}

/**
 * Each day's top failing paths with who asked and who answered, from the
 * err_path_asker rows. PURE. A day with no rows was stored before those rows
 * existed (or had no 5xx): `stored` false and `paths` null, never an empty
 * list that would read as a clean day. Local surfaces only; the remote
 * edge-errors twin keeps its week (contract 14).
 *
 * @param array<string,array<int,array{value:string,requests:int}>> $by_day Day => err_path_asker rows, most first.
 * @param int                                                       $limit  Paths kept per day.
 * @return array<int,array{day:string,stored:bool,paths:array|null}>
 */
function sn_edge_errors_paths_by_day_shape( array $by_day, $limit = 5 ) {
	$out = array();
	foreach ( $by_day as $day => $rows ) {
		$paths = array();
		foreach ( (array) $rows as $r ) {
			if ( count( $paths ) < (int) $limit && preg_match( '/^(\S+) (\d+) (\d+|-) (\S+) (.+)$/', (string) ( $r['value'] ?? '' ), $m ) ) {
				$paths[] = array( 'path' => $m[5], 'edge' => (int) $m[2], 'origin' => $m[3], 'cache' => $m[4], 'asker' => $m[1], 'requests' => (int) ( $r['requests'] ?? 0 ) );
			}
		}
		$out[] = array( 'day' => (string) $day, 'stored' => (bool) $rows, 'paths' => $rows ? $paths : null );
	}
	return $out;
}

/**
 * sn_edge_errors_paths_by_day_shape() over [$from,$to], one read per day.
 *
 * @param string $from First day.
 * @param string $to   Last day.
 * @return array
 */
function sn_edge_errors_paths_by_day( $from, $to ) {
	$by_day = array();
	for ( $t = strtotime( (string) $from . ' UTC' ), $end = strtotime( (string) $to . ' UTC' ); false !== $t && false !== $end && $t <= $end && count( $by_day ) < 31; $t += DAY_IN_SECONDS ) {
		$day            = gmdate( 'Y-m-d', $t );
		$by_day[ $day ] = sn_edge_top_dim( 'err_path_asker', $day, $day, 5 ); // the shape's own limit, read no further.
	}
	return sn_edge_errors_paths_by_day_shape( $by_day, 5 );
}

/**
 * Who ASKED for a stored 5xx (18.2.0), from the err_source value's `src=`
 * prefix: `visitor` (eyeball), `worker` (a Worker's subrequest), `other`
 * (any other Cloudflare request source) or `unrecorded`. Unrecorded means the
 * row was stored before the errors query carried requestSource (17.9.3), so
 * in a window spanning that change it is the pre-filter leftover, not a class
 * of traffic.
 *
 * @param string $value A stored err_source value.
 * @return string visitor|worker|other|unrecorded
 */
function sn_edge_error_asker( $value ) {
	if ( ! preg_match( '/^src=(\S+) /', (string) $value, $s ) ) {
		return 'unrecorded';
	}
	if ( 'eyeball' === $s[1] ) {
		return 'visitor';
	}
	return 0 === strpos( $s[1], 'edgeWorker' ) ? 'worker' : 'other';
}

/**
 * Shape err_source rows into one entry per day of [$from,$to] (18.2.0). PURE:
 * the reader below only fetches. Every day in the range is present, zero-
 * filled, so a day with no stored 5xx reads 0 instead of vanishing.
 *
 * @param array  $rows Rows of {day, value, requests}.
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array<int,array{day:string,total:int,visitor:int,worker:int,other:int,unrecorded:int}>
 */
function sn_edge_errors_days_shape( array $rows, $from, $to ) {
	$out   = array();
	$start = strtotime( (string) $from . ' UTC' );
	$end   = strtotime( (string) $to . ' UTC' );
	if ( false === $start || false === $end || $end < $start || ( $end - $start ) > 400 * DAY_IN_SECONDS ) {
		return array();
	}
	for ( $t = $start; $t <= $end; $t += DAY_IN_SECONDS ) {
		$day         = gmdate( 'Y-m-d', $t );
		$out[ $day ] = array( 'day' => $day, 'total' => 0, 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 );
	}
	foreach ( $rows as $r ) {
		$day = (string) ( $r['day'] ?? '' );
		if ( ! isset( $out[ $day ] ) ) {
			continue;
		}
		$n                      = (int) ( $r['requests'] ?? 0 );
		$out[ $day ]['total']  += $n;
		$out[ $day ][ sn_edge_error_asker( (string) ( $r['value'] ?? '' ) ) ] += $n;
	}
	return array_values( $out );
}

/**
 * The window's asked-by totals, summed from the day rows (18.2.0).
 *
 * @param array $days Output of sn_edge_errors_days_shape().
 * @return array{visitor:int,worker:int,other:int,unrecorded:int}
 */
function sn_edge_errors_asked_by_totals( array $days ) {
	$out = array( 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 );
	foreach ( $days as $d ) {
		foreach ( $out as $k => $v ) {
			$out[ $k ] = $v + (int) ( $d[ $k ] ?? 0 );
		}
	}
	return $out;
}

/**
 * Record that the errors query ran for $day (18.3.0): '' when it was read,
 * the refusal otherwise. SN_EDGE_ERRORS_QUERY_OPT keeps only the LAST run,
 * which cannot say whether an older day in the window was ever read; this
 * map can. Capped at the newest 30 days.
 *
 * @param string $day   YYYY-MM-DD the snapshot covers.
 * @param string $error '' on success, the query's refusal otherwise.
 * @return void
 */
function sn_edge_errors_mark_read( $day, $error ) {
	$map                    = (array) get_option( SN_EDGE_ERRORS_READ_OPT, array() );
	$map[ (string) $day ] = (string) $error;
	ksort( $map );
	update_option( SN_EDGE_ERRORS_READ_OPT, array_slice( $map, -30, null, true ), false );
}

/**
 * Stamp each day row with whether it was read (18.3.0). PURE.
 *
 *   read       the errors query ran for that day and answered
 *   failed     it ran and was refused: the day was NOT read, a 0 means nothing
 *   pending    no run has covered it yet (today, or yesterday before the daily
 *              rollup): a 0 means "not yet", never "clean"
 *   untracked  older than this bookkeeping (stored before 18.3.0)
 *
 * @param array  $days  Output of sn_edge_errors_days_shape().
 * @param array  $read  SN_EDGE_ERRORS_READ_OPT: day => '' or error.
 * @param string $today YYYY-MM-DD (UTC).
 * @return array The same rows, each with `read`.
 */
function sn_edge_errors_days_annotate( array $days, array $read, $today ) {
	$yesterday = gmdate( 'Y-m-d', strtotime( (string) $today . ' UTC' ) - DAY_IN_SECONDS );
	foreach ( $days as $i => $d ) {
		$day = (string) ( $d['day'] ?? '' );
		if ( array_key_exists( $day, $read ) ) {
			$days[ $i ]['read'] = '' === (string) $read[ $day ] ? 'read' : 'failed';
		} elseif ( $day >= $yesterday ) {
			$days[ $i ]['read'] = 'pending';
		} else {
			$days[ $i ]['read'] = 'untracked';
		}
	}
	return $days;
}

/**
 * Read the stored err_source rows per day and shape them (18.2.0).
 *
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array See sn_edge_errors_days_shape().
 */
function sn_edge_errors_days( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, value, SUM(requests) AS requests
		 FROM {$table} WHERE day >= %s AND day <= %s AND dim = %s
		 GROUP BY day, value",
		(string) $from,
		(string) $to,
		'err_source'
	), ARRAY_A );
	return sn_edge_errors_days_shape( (array) $rows, $from, $to );
}

/**
 * The headline reconciliation: edge HTML pageviews (every client) vs the beacon's
 * human pageviews (JS-executing humans) → the machine / no-JS traffic the beacon
 * never saw. Clamped at 0 (a sampled beacon window can momentarily over-count).
 *
 * @return array{edge:int, human:int, machine:int, machine_pct:int}
 */
function sn_edge_machine_split( $from, $to ) {
	$edge  = (int) ( sn_edge_range_totals( $from, $to )['page_views'] ?? 0 );
	$human = function_exists( 'sn_analytics_range_totals' )
		? (int) ( sn_analytics_range_totals( $from, $to, 'human' )['views'] ?? 0 )
		: 0;
	$machine = max( 0, $edge - $human );
	return array(
		'edge'        => $edge,
		'human'       => $human,
		'machine'     => $machine,
		'machine_pct' => $edge > 0 ? (int) round( $machine / $edge * 100 ) : 0,
	);
}
