<?php
/**
 * Signal & Noise Tools: analytics_query, the executor.
 *
 * Validates (inc/sn-metrics-analytics-vocab.php), reads one base dimension per
 * day (inc/sn-metrics-analytics-fetch.php), then groups, filters, floors,
 * compares and orders in PHP. Rows under the floor fold into `withheld`
 * whenever a dimension other than day is grouped. compare:previous reads the
 * adjacent window of the same length (sn_analytics_prior_window) and adds
 * previous values, deltas and each row's share of the window's views.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grouped rows for one window: key => {day?, dimension value, sums}.
 *
 * @param array  $spec  Validated query.
 * @param string $from  Y-m-d.
 * @param string $to    Y-m-d.
 * @param string $class Traffic class.
 * @return array|null Null when the read failed.
 */
function snt_mq_grouped( array $spec, $from, $to, $class ) {
	$dim  = current( array_diff( $spec['dims'], array( 'day' ) ) ) ?: 'day';
	$base = in_array( $dim, array( 'source', 'referrer_category' ), true ) ? 'referrer' : $dim;
	$rows = snt_mq_fetch( $base, $from, $to, $class );
	if ( null === $rows ) {
		return null;
	}
	$self   = 'referrer' === $base ? sn_analytics_self_hosts() : array();
	$by_day = in_array( 'day', $spec['dims'], true );
	$out    = array();
	foreach ( $rows as $r ) {
		$value = $r['value'];
		if ( 'referrer' === $base ) {
			$value = sn_analytics_canonical_source( $value, $self );
			$value = 'referrer_category' === $dim ? sn_analytics_source_category_of_label( $value ) : $value;
		}
		$cells = $by_day ? array( 'day' => $r['day'] ) : array();
		if ( 'day' !== $dim ) {
			$cells[ $dim ] = $value;
		}
		foreach ( $spec['filters'] as $fd => $f ) {
			$v = (string) ( $cells[ $fd ] ?? '' );
			if ( ( $f['include'] && ! in_array( $v, $f['include'], true ) ) || in_array( $v, $f['exclude'], true ) ) {
				continue 2;
			}
		}
		$key = implode( "\x1f", $cells );
		$acc = $out[ $key ] ?? $cells + array( 'views' => 0, 'visits' => 0, 'scroll_w' => 0.0, 'time_w' => 0.0 );
		foreach ( array( 'views', 'visits', 'scroll_w', 'time_w' ) as $m ) {
			$acc[ $m ] += $r[ $m ];
		}
		$out[ $key ] = $acc;
	}
	return $out;
}

/**
 * Finish a grouped row: the requested metrics only.
 *
 * @param array $g       Grouped row.
 * @param array $metrics Requested metrics.
 * @return array
 */
function snt_mq_metrics( array $g, array $metrics ) {
	$all = array(
		'views'      => (int) $g['views'],
		'visits'     => (int) $g['visits'],
		'scroll_avg' => $g['views'] ? round( $g['scroll_w'] / $g['views'], 2 ) : null,
		'time_avg'   => $g['views'] ? round( $g['time_w'] / $g['views'], 2 ) : null,
	);
	return array_intersect_key( $all, array_flip( $metrics ) );
}

/** Ability execute callback: signal-noise/analytics-query. */
function snt_ability_analytics_query( $input ) {
	$input = is_array( $input ) ? $input : array();
	$spec  = snt_mq_validate( $input );
	$win   = is_wp_error( $spec ) ? $spec : snt_metrics_window( $input );
	if ( is_wp_error( $win ) ) {
		return $win;
	}
	$class = snt_metrics_class( $input );
	$cur   = snt_mq_grouped( $spec, $win['from'], $win['to'], $class );
	if ( null === $cur ) {
		return new WP_Error( 'snt_metrics_read', 'The analytics read failed.' );
	}
	$total    = array_sum( array_column( $cur, 'views' ) );
	$floored  = array( 'day' ) !== $spec['dims'];
	$withheld = array( 'rows' => 0, 'views' => 0, 'visits' => 0 );
	if ( $floored ) {
		$f        = snt_metrics_floor( array_values( $cur ), SNT_MQ_MAX_ROWS );
		$withheld = $f['withheld'];
		$cur      = array_filter( $cur, static fn( $g ) => $g['visits'] >= SNT_METRICS_FLOOR );
	}
	$prior = null;
	$prev  = array();
	if ( 'previous' === $spec['compare'] && 'all' !== $win['range'] ) {
		$prior = array_combine( array( 'from', 'to' ), sn_analytics_prior_window( $win['from'], $win['to'] ) );
		$prev  = snt_mq_grouped( $spec, $prior['from'], $prior['to'], $class );
		if ( null === $prev ) {
			return new WP_Error( 'snt_metrics_read', 'The previous window read failed.' );
		}
	}
	$rows = array();
	foreach ( $cur as $key => $g ) {
		$row = array_diff_key( $g, array_flip( array( 'views', 'visits', 'scroll_w', 'time_w' ) ) ) + snt_mq_metrics( $g, $spec['metrics'] );
		if ( null !== $prior ) {
			$p               = isset( $prev[ $key ] ) ? snt_mq_metrics( $prev[ $key ], $spec['metrics'] ) : array_fill_keys( $spec['metrics'], 0 );
			$row['previous'] = $p;
			$row['delta']    = array();
			foreach ( $spec['metrics'] as $m ) {
				$d                  = null === $row[ $m ] || null === $p[ $m ] ? null : $row[ $m ] - $p[ $m ];
				$row['delta'][ $m ] = is_float( $d ) ? round( $d, 2 ) : $d; // counts stay integers
			}
			$row['share'] = $total ? round( $g['views'] / $total, 4 ) : 0.0;
		}
		$rows[] = $row;
	}
	$by  = $spec['order_by'];
	$dir = 'asc' === $spec['order'] ? 1 : -1;
	usort( $rows, static fn( $a, $b ) => $dir * ( ( $a[ $by ] ?? '' ) <=> ( $b[ $by ] ?? '' ) ) );
	return $win + array(
		'class'      => $class,
		'dimensions' => $spec['dims'],
		'metrics'    => $spec['metrics'],
		'compare'    => null === $prior ? ( 'previous' === $spec['compare'] ? array( 'reason' => 'range all has no previous window' ) : null ) : $prior,
		'floor'      => $floored ? SNT_METRICS_FLOOR : null,
		'rows'       => array_slice( $rows, 0, $spec['limit'] ),
		'rows_total' => count( $rows ),
		'withheld'   => $withheld,
	);
}
