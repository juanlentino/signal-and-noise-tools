<?php
/**
 * Signal & Noise Tools, Machine Readers: the ledger folds.
 *
 * Pure folds over the sensor's normalized rows, so an agent can answer two
 * questions the summary collapses: which PURPOSE each family read for, and
 * which reader fetched the rights files, when, and how regularly. Nothing here
 * fetches; inc/abilities-machine-readers-ledger.php fetches and these fold.
 * Every function takes rows and returns a fresh array.
 *
 * @package SignalNoiseTools
 * @since 17.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A (family, path) pair is a poller from this many reads up. */
const SN_MR_POLLER_MIN_READS = 3;

/**
 * Coefficient of variation of the intervals at or under which the reads are
 * called regular. 0.5 means the intervals stay within about half their mean:
 * a daily fetch that sometimes slips a few hours still reads as a schedule,
 * a human-shaped cluster of three reads in an hour does not.
 */
const SN_MR_POLLER_MAX_CV = 0.5;

/**
 * Family x purpose x agent cells over the aggregate rows, hits descending.
 *
 * `days` is the number of distinct days the cell was seen on, `surfaces` the
 * cell's hits per surface class. A cell with purpose `unknown` is an unmapped
 * reader, not a reader with no purpose.
 *
 * @param array $rows snt_mr_fetch() aggregate rows.
 * @return array{cells:array<int,array>,total:int,families:int}
 */
function snt_mr_crosstab( $rows ) {
	$cells = array();
	$days  = array();
	$total = 0;
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$hits    = max( 0, (int) ( $row['hits'] ?? 0 ) );
		$family  = (string) ( $row['family'] ?? '' );
		$purpose = (string) ( $row['purpose'] ?? 'unknown' );
		$agent   = (string) ( $row['agent'] ?? '' );
		$surface = (string) ( $row['surface'] ?? '' );
		$key     = $family . '|' . $purpose . '|' . $agent;
		$total  += $hits;
		if ( ! isset( $cells[ $key ] ) ) {
			$cells[ $key ] = array( 'family' => $family, 'purpose' => $purpose, 'agent' => $agent, 'hits' => 0, 'days' => 0, 'surfaces' => array() );
			$days[ $key ]  = array();
		}
		$cells[ $key ]['hits'] += $hits;
		if ( '' !== $surface ) {
			$cells[ $key ]['surfaces'][ $surface ] = ( $cells[ $key ]['surfaces'][ $surface ] ?? 0 ) + $hits;
		}
		$day = (string) ( $row['day'] ?? '' );
		if ( '' !== $day ) {
			$days[ $key ][ $day ] = true;
		}
	}
	foreach ( $cells as $key => &$cell ) {
		$cell['days'] = count( $days[ $key ] );
		arsort( $cell['surfaces'] );
	}
	unset( $cell );
	usort( $cells, static fn( $a, $b ) => $b['hits'] <=> $a['hits'] ?: strcmp( $a['family'] . $a['purpose'] . $a['agent'], $b['family'] . $b['purpose'] . $b['agent'] ) );
	return array( 'cells' => array_values( $cells ), 'total' => $total, 'families' => count( array_unique( array_column( $cells, 'family' ) ) ) );
}

/**
 * Cadence per (family, path) over the rights-detail rows.
 *
 * `reads` sums the rows' hits. Intervals are the gaps between consecutive
 * rows in time order. `regularity`
 * is the coefficient of variation of those gaps (0 is a metronome, null when
 * there are fewer than two gaps); `poller` is SN_MR_POLLER_MIN_READS reads or
 * more at SN_MR_POLLER_MAX_CV or under.
 *
 * @param array $reads snt_mr_fetch( $days, 'rights' ) rows.
 * @return array<int,array> Rows descending by reads.
 */
function snt_mr_rights_cadence( $reads ) {
	$groups = array();
	foreach ( (array) $reads as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$at = strtotime( (string) ( $row['observed_at'] ?? '' ) );
		if ( false === $at ) {
			continue;
		}
		$key = (string) ( $row['family'] ?? '' ) . '|' . (string) ( $row['path'] ?? '' );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array( 'family' => (string) ( $row['family'] ?? '' ), 'path' => (string) ( $row['path'] ?? '' ), 'vendors' => array(), 'purposes' => array(), 'at' => array(), 'hits' => 0 );
		}
		$groups[ $key ]['at'][]                                           = $at;
		$groups[ $key ]['hits']                                          += max( 1, (int) ( $row['hits'] ?? 1 ) );
		$groups[ $key ]['vendors'][ (string) ( $row['vendor'] ?? '' ) ]   = true;
		$groups[ $key ]['purposes'][ (string) ( $row['purpose'] ?? '' ) ] = true;
	}
	$out = array();
	foreach ( $groups as $g ) {
		sort( $g['at'] );
		$gaps = array();
		for ( $i = 1, $n = count( $g['at'] ); $i < $n; $i++ ) {
			$gaps[] = $g['at'][ $i ] - $g['at'][ $i - 1 ];
		}
		$cv    = snt_mr_cv( $gaps );
		$n     = count( $g['at'] );
		$reads = $g['hits'];
		sort( $gaps );
		$out[] = array(
			'family'            => $g['family'],
			'path'              => $g['path'],
			'vendors'           => array_values( array_filter( array_keys( $g['vendors'] ), 'strlen' ) ),
			'purposes'          => array_values( array_filter( array_keys( $g['purposes'] ), 'strlen' ) ),
			'reads'             => $reads,
			'first'             => gmdate( 'c', $g['at'][0] ),
			'last'              => gmdate( 'c', $g['at'][ $n - 1 ] ),
			'median_interval_s' => $gaps ? (int) $gaps[ intdiv( count( $gaps ), 2 ) ] : null,
			'regularity'        => $cv,
			'poller'            => $reads >= SN_MR_POLLER_MIN_READS && null !== $cv && $cv <= SN_MR_POLLER_MAX_CV,
		);
	}
	usort( $out, static fn( $a, $b ) => $b['reads'] <=> $a['reads'] ?: strcmp( $a['family'] . $a['path'], $b['family'] . $b['path'] ) );
	return $out;
}

/**
 * Coefficient of variation (population stddev over mean), rounded to 3 places.
 *
 * @param array<int,int|float> $values
 * @return float|null null with fewer than two values or a zero mean.
 */
function snt_mr_cv( $values ) {
	$n = count( $values );
	if ( $n < 2 ) {
		return null;
	}
	$mean = array_sum( $values ) / $n;
	if ( $mean <= 0 ) {
		return null;
	}
	$var = 0.0;
	foreach ( $values as $v ) {
		$var += ( $v - $mean ) ** 2;
	}
	return round( sqrt( $var / $n ) / $mean, 3 );
}

/**
 * The same ai_rights count taken twice: once from the aggregate rows (the
 * summary's figure, surface class `rights`) and once from the rights-detail
 * rows (one row per read). The two datasets are written by different code
 * paths at the edge, so a gap between them is a sensor finding in itself.
 *
 * @param array $aggregate snt_mr_fetch( $days ) rows.
 * @param array $detail    snt_mr_fetch( $days, 'rights' ) rows.
 * @return array{aggregate:int,detail:int}
 */
function snt_mr_ai_rights_pair( $aggregate, $detail ) {
	$ai  = snt_mr_ai_training_families();
	$sum = static function ( $rows, $need_surface ) use ( $ai ) {
		$n = 0;
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && in_array( (string) ( $row['family'] ?? '' ), $ai, true )
				&& ( ! $need_surface || 'rights' === (string) ( $row['surface'] ?? '' ) ) ) {
				$n += max( 0, (int) ( $row['hits'] ?? 0 ) );
			}
		}
		return $n;
	};
	return array( 'aggregate' => $sum( $aggregate, true ), 'detail' => $sum( $detail, false ) );
}
