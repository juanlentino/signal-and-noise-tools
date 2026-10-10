<?php
/**
 * Signal & Noise Tools: Machine Readers, the daily series (series: "day").
 *
 * The same rows the summary folds into window figures, folded per UTC day
 * instead, so every daily field sums to its window figure by construction.
 * The sensor's window is rolling (NOW() minus N days), so it touches N+1 UTC
 * dates and the first and last are partial; both say so. Days with no rows
 * are zeros, not gaps. Counts only: no user agents, no paths, no networks.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-day series. PURE.
 *
 * @param array      $rows    Aggregate rows (family, surface, purpose, first_party, day, hits).
 * @param array|null $totals  Totals-view rows {day, hits} when the window total is exact; null to sum the aggregate.
 * @param int        $days    The window, 1-90.
 * @param int        $now     Unix time the window ends at.
 * @param bool       $has_tax Whether the window carries the purpose taxonomy (purposes / first_party are null otherwise).
 * @return array<int,array> Oldest first.
 */
function snt_mr_daily_series( array $rows, $totals, $days, $now, $has_tax = true ) {
	$ai_set = snt_mr_ai_training_families();
	$by_day = array();
	for ( $t = $now - (int) $days * DAY_IN_SECONDS; gmdate( 'Y-m-d', $t ) <= gmdate( 'Y-m-d', $now ); $t += DAY_IN_SECONDS ) {
		$by_day[ gmdate( 'Y-m-d', $t ) ] = array( 'rows' => array(), 'total' => 0 );
	}
	// A row dated outside the computed range (a cached read across midnight)
	// still lands on its own date, so the sums hold. A row whose day the
	// normalizer blanked joins the first date rather than becoming a date.
	$first = (string) array_key_first( $by_day );
	foreach ( $rows as $r ) {
		$k                        = (string) ( $r['day'] ?? '' ) ?: $first;
		$by_day[ $k ]           ??= array( 'rows' => array(), 'total' => 0 );
		$by_day[ $k ]['rows'][]   = $r;
		$by_day[ $k ]['total']   += null === $totals ? (int) ( $r['hits'] ?? 0 ) : 0;
	}
	foreach ( null === $totals ? array() : $totals as $r ) {
		$k                       = (string) ( $r['day'] ?? '' ) ?: $first;
		$by_day[ $k ]          ??= array( 'rows' => array(), 'total' => 0 );
		$by_day[ $k ]['total'] += (int) ( $r['hits'] ?? 0 );
	}
	ksort( $by_day );
	$list = static function ( $sums, $key ) {
		$out = array();
		foreach ( $sums as $k => $hits ) {
			$out[] = array( $key => (string) $k, 'hits' => (int) $hits );
		}
		return $out;
	};
	$out  = array();
	$last = count( $by_day ) - 1;
	foreach ( array_keys( $by_day ) as $i => $day ) {
		$ai = 0;
		$fp = 0;
		$pu = array();
		$ai_rows = array();
		foreach ( $by_day[ $day ]['rows'] as $r ) {
			$hits = (int) ( $r['hits'] ?? 0 );
			if ( in_array( (string) ( $r['family'] ?? '' ), $ai_set, true ) ) {
				$ai       += $hits;
				$ai_rows[] = $r;
			}
			if ( ! empty( $r['first_party'] ) ) {
				$fp += $hits;
				continue;
			}
			$p        = (string) ( $r['purpose'] ?? 'unknown' );
			$pu[ $p ] = ( $pu[ $p ] ?? 0 ) + $hits;
		}
		arsort( $pu );
		$entry = array(
			'day'         => (string) $day,
			'total'       => (int) $by_day[ $day ]['total'],
			'first_party' => $has_tax ? $fp : null,
			'ai_training' => $ai,
			'purposes'    => $has_tax ? $list( $pu, 'purpose' ) : null,
			'ai_surfaces' => $list( snt_mr_sum_hits_by( $ai_rows, 'surface' ), 'surface' ),
		);
		if ( 0 === $i || $last === $i ) {
			$entry['partial'] = true;
		}
		$out[] = $entry;
	}
	return $out;
}
