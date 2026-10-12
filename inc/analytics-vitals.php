<?php
/**
 * Field Core Web Vitals as a read (theme audit idea 4, 2026-10-12): p75 and
 * the good / needs improvement / poor split for LCP, INP and CLS, per device
 * class and for the top paths, over a window.
 *
 * The theme beacon sends each vital as its own event (vl, vi, vc) with the
 * value in double7 (CLS x1000). Quantiles are not additive by day, so this
 * reads the weighted value distribution from Analytics Engine for the exact
 * window (two queries: by device, and by path for the top paths) and takes
 * p75 and the bands here, the way inc/analytics-percentiles.php does. A read
 * that fails or reaches the row cap is no answer, never a partial one.
 *
 * Unknown is never 0: a cell with no measurements has samples 0 and null
 * figures. A cell under SNT_VITALS_MIN_SAMPLES says small_sample.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-percentiles.php'; // the weighted quantile, the day bounds, the dataset choice.

/** Under this many (sample-weighted) measurements a cell is labeled small_sample. */
const SNT_VITALS_MIN_SAMPLES = 30;

/**
 * Event, Google's good and poor thresholds (stored units), output divisor and unit.
 *
 * @return array<string,array{event:string,good:float,poor:float,div:float,unit:string}>
 */
function sn_analytics_vitals_metrics() {
	return array(
		'lcp' => array( 'event' => 'vl', 'good' => 2500.0, 'poor' => 4000.0, 'div' => 1.0, 'unit' => 'ms' ),
		'inp' => array( 'event' => 'vi', 'good' => 200.0, 'poor' => 500.0, 'div' => 1.0, 'unit' => 'ms' ),
		'cls' => array( 'event' => 'vc', 'good' => 100.0, 'poor' => 250.0, 'div' => 1000.0, 'unit' => 'score' ),
	);
}

/**
 * The distribution SQL for one dimension (device or path): event, key, value,
 * weight. Paths are an allowlisted IN list (both spellings of each, with and
 * without the trailing slash). PURE given the class rule.
 *
 * @param string   $dim    'device' | 'path'.
 * @param string   $from   Y-m-d.
 * @param string   $to     Y-m-d.
 * @param string   $class  Traffic class.
 * @param string[] $paths  Canonical paths (path dimension only).
 * @param string   $source Dataset.
 * @param string   $range  One side of a stitched split, or ''.
 * @return string SQL, or '' when there is nothing to ask.
 */
function sn_analytics_vitals_sql( $dim, $from, $to, $class, array $paths, $source, $range = '' ) {
	$col    = 'path' === $dim ? 'blob2' : 'blob5';
	$class  = in_array( $class, SN_ANALYTICS_CLASSES, true ) ? $class : 'human';
	$source = preg_replace( '/[^a-z0-9_]/', '', (string) $source );
	$range  = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	$from   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '1970-01-01';
	$to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '1970-01-01';
	$events = "'" . implode( "','", array_column( sn_analytics_vitals_metrics(), 'event' ) ) . "'";
	$in     = '';
	if ( 'path' === $dim ) {
		$list = array();
		foreach ( $paths as $p ) {
			$p = (string) $p;
			if ( 1 !== preg_match( '#^/[A-Za-z0-9\-._~/%]*$#', $p ) ) {
				continue;
			}
			$list[ $p ]       = true;
			$list[ $p . '/' ] = '/' !== $p;
		}
		$list = array_keys( array_filter( $list ) );
		if ( array() === $list ) {
			return '';
		}
		$in = " AND blob2 IN ('" . implode( "','", $list ) . "')";
	}
	list( $lo, $hi ) = sn_analytics_local_day_bounds_utc( $from, $to );
	return implode( ' ', array(
		"SELECT blob1 AS e, {$col} AS k, double7 AS v, sum(_sample_interval) AS w",
		'FROM ' . $source,
		"WHERE blob1 IN ({$events}) AND " . sn_analytics_class_where( $class ) . $in,
		"AND timestamp >= toDateTime('{$lo}')",
		"AND timestamp <= toDateTime('{$hi}'){$range}",
		'GROUP BY e, k, v',
	) );
}

/**
 * One cell: samples, p75 (output units), and the three band shares. No
 * measurements: samples 0 and every figure null. PURE.
 *
 * @param array<int,array{v:float,w:float}> $pairs  Value and weight.
 * @param string                            $metric lcp | inp | cls.
 * @return array{samples:int,p75:float|null,good:float|null,needs_improvement:float|null,poor:float|null,small_sample:bool}
 */
function sn_analytics_vitals_cell( array $pairs, $metric ) {
	$m     = sn_analytics_vitals_metrics()[ $metric ];
	$total = array_sum( array_column( $pairs, 'w' ) );
	if ( $total <= 0 ) {
		return array( 'samples' => 0, 'p75' => null, 'good' => null, 'needs_improvement' => null, 'poor' => null, 'small_sample' => true );
	}
	$good = 0.0;
	$poor = 0.0;
	foreach ( $pairs as $p ) {
		$good += $p['v'] <= $m['good'] ? $p['w'] : 0;
		$poor += $p['v'] > $m['poor'] ? $p['w'] : 0;
	}
	$pct = static fn( $x ) => round( 100 * $x / $total, 1 );
	return array(
		'samples'           => (int) round( $total ),
		'p75'               => round( sn_analytics_weighted_quantile( $pairs, 0.75 ) / $m['div'], 'cls' === $metric ? 3 : 0 ),
		'good'              => $pct( $good ),
		'needs_improvement' => $pct( $total - $good - $poor ),
		'poor'              => $pct( $poor ),
		'small_sample'      => $total < SNT_VITALS_MIN_SAMPLES,
	);
}

/**
 * Fold AE rows into key => metric => cell. Keys go through $key_of (device
 * '' reads unknown; paths fold to their canonical spelling); every key in
 * $keys gets all three metrics, measured or not. PURE.
 *
 * @param array    $rows   Rows {e, k, v, w}.
 * @param string[] $keys   Keys always present.
 * @param callable $key_of fn( string ): string.
 * @return array<string,array<string,array>>
 */
function sn_analytics_vitals_fold( array $rows, array $keys, callable $key_of ) {
	$by_event = array_flip( array_column( sn_analytics_vitals_metrics(), 'event' ) );
	$names    = array_keys( sn_analytics_vitals_metrics() );
	$pairs    = array_fill_keys( $keys, array_fill_keys( $names, array() ) );
	foreach ( $rows as $r ) {
		$r = (array) $r;
		$e = (string) ( $r['e'] ?? '' );
		if ( ! isset( $by_event[ $e ] ) ) {
			continue;
		}
		$k = (string) $key_of( (string) ( $r['k'] ?? '' ) );
		$pairs[ $k ] = $pairs[ $k ] ?? array_fill_keys( $names, array() );
		$pairs[ $k ][ $names[ $by_event[ $e ] ] ][] = array( 'v' => (float) ( $r['v'] ?? 0 ), 'w' => (float) ( $r['w'] ?? 0 ) );
	}
	$out = array();
	foreach ( $pairs as $k => $metrics ) {
		foreach ( $metrics as $name => $p ) {
			$out[ $k ][ $name ] = sn_analytics_vitals_cell( $p, $name );
		}
	}
	return $out;
}

require_once __DIR__ . '/analytics-vitals-read.php'; // the live read, the ability and the display rows.
