<?php
/**
 * CLS shift sources: which page elements account for the not-good CLS
 * readings in a window. From analytics worker 2.4.0 each `vc` row carries, in
 * blob17, a short selector of the element that moved most in the page's
 * largest layout shift (tag, id and class names: page markup only), '' when
 * the beacon sent none. blob17 means that ONLY where blob1 = 'vc': on a `pv`
 * row it is the UTM source and on a `ce` row the event's name. Both pageviews
 * datasets spell the column blob17; a legacy `vc` row holds '' there.
 *
 * Paths are named the way the by-path vitals read names them: canonical, and
 * only inside its character allowlist. Like that read there is no rule for a
 * coded path (/r/CODE): it is a path the owner's reads already name.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The most rows cls_sources lists. */
const SNT_VITALS_SOURCES_MAX = 50;

/**
 * The SQL for one leg: the not-good CLS readings per path and source, or the
 * weighted count of those that carried no source. PURE given the class rule.
 *
 * @param bool   $attributed True for the per-source rows, false for the count without a source.
 * @param string $from       Y-m-d.
 * @param string $to         Y-m-d.
 * @param string $class      Traffic class.
 * @param string $source     Dataset.
 * @param string $range      One side of a stitched split, or ''.
 * @return string
 */
function sn_analytics_vitals_sources_sql( $attributed, $from, $to, $class, $source, $range = '' ) {
	$m      = sn_analytics_vitals_metrics()['cls'];
	$class  = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$source = preg_replace( '/[^a-z0-9_]/', '', (string) $source );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$from   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';
	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );
	// Every form here is one a live read already sends: the weighted sum over
	// an if() with _sample_interval and 0, max() of a column, GROUP BY on aliases.
	$select = $attributed
		? 'SELECT blob2 AS k, blob17 AS s, sum(_sample_interval) AS w, sum(if(double7 > ' . (int) $m['poor'] . ', _sample_interval, 0)) AS p, max(double7) AS m'
		: 'SELECT sum(_sample_interval) AS w';
	return implode( ' ', array_filter( array(
		$select,
		'FROM ' . $source,
		"WHERE blob1 = 'vc' AND double7 > " . (int) $m['good'] . ' AND blob17 ' . ( $attributed ? '!=' : '=' ) . " '' AND " . sn_analytics_class_where( $class ) . sn_analytics_excluded_path_sql(),
		"AND timestamp >= toDateTime('{$lo}')",
		"AND timestamp <= toDateTime('{$hi}'){$range}",
		$attributed ? 'GROUP BY k, s' : '',
	) ) );
}

/**
 * Fold the per-source rows (both path spellings, both stitched halves) into
 * the list: readings and poor add, worst is the larger; most readings first,
 * then path and source so a tie keeps its order; at most 50. PURE.
 *
 * @param array $rows Rows {k, s, w, p, m}.
 * @return array<int,array{path:string,source:string,readings:int,needs_improvement:int,poor:int,worst:float}>
 */
function sn_analytics_vitals_sources_fold( array $rows ) {
	$acc = array();
	foreach ( $rows as $r ) {
		$r    = (array) $r;
		$path = sn_analytics_canonical_path( (string) ( $r['k'] ?? '' ) );
		$src  = (string) ( $r['s'] ?? '' );
		// The worker cleans a source, but a stored row is not trusted: its shape is checked again.
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_#.-]{0,95}\z/', $src ) || ! sn_analytics_vitals_path_ok( $path ) ) {
			continue;
		}
		$key         = $path . "\n" . $src;
		$a           = $acc[ $key ] ?? array( 'path' => $path, 'source' => $src, 'w' => 0.0, 'p' => 0.0, 'm' => 0.0 );
		$acc[ $key ] = array( 'w' => $a['w'] + (float) ( $r['w'] ?? 0 ), 'p' => $a['p'] + (float) ( $r['p'] ?? 0 ), 'm' => max( $a['m'], (float) ( $r['m'] ?? 0 ) ) ) + $a;
	}
	$div = sn_analytics_vitals_metrics()['cls']['div'];
	$out = array();
	foreach ( $acc as $a ) {
		$readings = (int) round( $a['w'] );
		$poor     = min( $readings, (int) round( $a['p'] ) );
		$out[]    = array( 'path' => $a['path'], 'source' => $a['source'], 'readings' => $readings, 'needs_improvement' => $readings - $poor, 'poor' => $poor, 'worst' => round( $a['m'] / $div, 3 ) );
	}
	usort( $out, static fn( $x, $y ) => array( $y['readings'], $x['path'], $x['source'] ) <=> array( $x['readings'], $y['path'], $y['source'] ) );
	return array_slice( $out, 0, SNT_VITALS_SOURCES_MAX );
}

/**
 * The three keys the vitals payload gains. A failed or capped leg nulls the
 * list AND the count (a list without its count could read as complete), and
 * the reason is recorded in sn_analytics_vitals_failure() and carried here:
 * a caller of the ability cannot read a PHP static.
 *
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @return array{cls_sources:array|null,cls_sources_unattributed:int|null,cls_sources_failure:string}
 */
function sn_analytics_vitals_sources( $from, $to, $class ) {
	$what = 'the CLS sources read';
	$leg  = static fn( $attributed ) => sn_analytics_vitals_stitched( static fn( $source, $range ) => sn_analytics_vitals_sources_sql( $attributed, $from, $to, $class, $source, $range ), $what, $from, $to );
	$rows = $leg( true );
	$none = null === $rows ? null : $leg( false );
	if ( null === $none ) {
		return array( 'cls_sources' => null, 'cls_sources_unattributed' => null, 'cls_sources_failure' => sn_analytics_vitals_failure() );
	}
	return array(
		'cls_sources'              => sn_analytics_vitals_sources_fold( $rows ),
		'cls_sources_unattributed' => (int) round( array_sum( array_map( static fn( $r ) => (float) ( ( (array) $r )['w'] ?? 0 ), $none ) ) ),
		'cls_sources_failure'      => '',
	);
}

/**
 * One vitals leg over the window, both generations stitched. Null when a read
 * failed or a half reached the row cap (a partial answer); the reason is then
 * in sn_analytics_vitals_failure(), named by $what.
 *
 * @param callable $build fn( string $source, string $range ): string.
 * @param string   $what  The leg, in words ("the by-device read").
 * @param string   $from  Y-m-d.
 * @param string   $to    Y-m-d.
 * @return array|null
 */
function sn_analytics_vitals_stitched( callable $build, $what, $from, $to ) {
	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );
	$started         = time();
	$sets            = sn_analytics_stitched_rows( $build, substr( $lo, 0, 10 ), $hi );
	$cap             = defined( 'SN_ANALYTICS_AE_ROW_CAP' ) ? SN_ANALYTICS_AE_ROW_CAP : 10000;
	if ( null === $sets ) {
		// Quote the stored error only when it was recorded during this read.
		$err = function_exists( 'sn_analytics_last_error' ) ? sn_analytics_last_error( $started ) : null;
		// The code and the body excerpt only: the URL carries the account id.
		$why = is_array( $err ) ? ' (Analytics Engine said HTTP ' . (int) $err['code'] . ': ' . (string) $err['message'] . ')' : ' (no error was recorded)';
		sn_analytics_vitals_failure( $what . ' failed' . $why );
		return null;
	}
	if ( array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cap ) ) {
		sn_analytics_vitals_failure( $what . ' reached the row cap, so its distribution would be partial' );
		return null;
	}
	return array_merge( ...$sets );
}
