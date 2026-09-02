<?php
/**
 * Machine-reader daily series — the reshape between the sensor payload and the
 * analytics signal engine.
 *
 * The sensor already returns day grain: GET /_sn/rights-signals/machine-readers
 * yields `{family, surface, day, hits}` rows (days clamped 1..90), which
 * snt_mr_normalize_rows() has already allowlist-coerced. The dashboard
 * aggregates that grain away; nothing else did. This module keeps it.
 *
 * PURE: no WP calls, no clock, no I/O. Two calls over the same rows agree to
 * the integer. Callers supply the window; nothing here decides what "now" is.
 *
 * @package Signal_And_Noise_Tools
 * @since   13.76.0
 */

defined( 'ABSPATH' ) || exit;

/** Window the reader pipeline reads, in days. Matches the dashboard. */
const SN_MR_SERIES_WINDOW = 30;

/**
 * Days a family must appear on, out of SN_MR_SERIES_WINDOW, to be eligible.
 *
 * DERIVED FROM LIVE DATA (2026-09-02), not chosen. Days present across the 12
 * families with any traffic, sorted: 2, 9, 10, 11, 14 ... 23, 24, 31, 31, 31,
 * 31, 31. The distribution is BIMODAL with a nine-day gap — near-permanent
 * residents or sporadic visitors, nothing between 14 and 23 — so the threshold
 * only has to land in the empty region. It admits 7 families and 97.4% of
 * traffic, and drops 5 sporadic ones worth 2.6%.
 *
 * ONE rule, not two. With zero-fill, >= 20 present days out of 30 leaves at most
 * 10 zeros, so the median is guaranteed to fall among real values: a
 * days-present floor IMPLIES a non-zero median. A separate volume floor would be
 * a second knob measuring the same thing.
 *
 * A volume floor would also have been WRONG. amazon-ai: 9 days present, median
 * 160 when present — on a volume test it outranks openai, which is present every
 * day at a median of 8. There is no series there, only bursts. Presence is the
 * axis; size is what the statistics already handle.
 */
const SN_MR_SERIES_MIN_DAYS = 20;

/**
 * Every YYYY-MM-DD from $from to $to inclusive.
 *
 * UTC explicitly: ' UTC' on both bounds and gmdate() on the way out. A naive
 * strtotime() reads the session timezone, which silently shifts every bucket by
 * a day for half the world — the trap inc/ml-cadence.php already records.
 *
 * @param string $from YYYY-MM-DD.
 * @param string $to   YYYY-MM-DD.
 * @return string[] Ordered, oldest first. Empty when either bound is malformed
 *                  or the range inverts — never a guessed window.
 */
function snt_mr_day_range( $from, $to ) {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from )
		|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return array();
	}
	$a = strtotime( $from . ' 00:00:00 UTC' );
	$b = strtotime( $to . ' 00:00:00 UTC' );
	if ( false === $a || false === $b || $b < $a ) {
		return array();
	}
	$out = array();
	for ( $t = $a; $t <= $b; $t += DAY_IN_SECONDS ) {
		$out[] = gmdate( 'Y-m-d', $t );
	}
	return $out;
}

/**
 * One family's daily totals, ZERO-FILLED, in the shape the signal composers take.
 *
 * Zero-fill is load-bearing, not tidiness. A day with no rows is a real ZERO,
 * not a gap. Without it a crawler that stops simply produces a SHORTER series,
 * and "went quiet" — the most interesting reading on this data — becomes
 * structurally invisible. The fill is bounded by the window, so it can never
 * invent history before $from.
 *
 * @param array<int,array{family:string,surface:string,day:string,hits:int}> $rows Normalized.
 * @param string                                                            $family
 * @param string                                                            $from  YYYY-MM-DD.
 * @param string                                                            $to    YYYY-MM-DD.
 * @return array<int,array{views:int,day:string}> Oldest first.
 */
function snt_mr_daily_series( array $rows, $family, $from, $to ) {
	$days = snt_mr_day_range( $from, $to );
	if ( array() === $days ) {
		return array();
	}
	$by = array_fill_keys( $days, 0 );
	foreach ( $rows as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( (string) ( $row['family'] ?? '' ) !== (string) $family || ! isset( $by[ $day ] ) ) {
			continue;
		}
		$by[ $day ] += max( 0, (int) ( $row['hits'] ?? 0 ) );
	}
	$out = array();
	foreach ( $days as $day ) {
		// 'views' is the key the analytics composers read. The name is theirs,
		// not a claim that a crawler fetch is a pageview.
		$out[] = array( 'views' => (int) $by[ $day ], 'day' => $day );
	}
	return $out;
}

/**
 * Distinct days each family appears on, within the window.
 *
 * Counts DAYS, never rows: one family emits one row per surface per day, so a
 * row count would rank a family that touches six surfaces above one that touches
 * one, which is a statement about breadth, not presence.
 *
 * @return array<string,int> family => day count, descending, ties by family asc.
 */
function snt_mr_family_days( array $rows, $from, $to ) {
	$days = snt_mr_day_range( $from, $to );
	if ( array() === $days ) {
		return array();
	}
	$window = array_fill_keys( $days, true );
	$seen   = array();
	foreach ( $rows as $row ) {
		$fam = (string) ( $row['family'] ?? '' );
		$day = (string) ( $row['day'] ?? '' );
		if ( '' === $fam || ! isset( $window[ $day ] ) ) {
			continue;
		}
		if ( (int) ( $row['hits'] ?? 0 ) <= 0 ) {
			continue;
		}
		$seen[ $fam ][ $day ] = true;
	}
	$out = array();
	foreach ( $seen as $fam => $set ) {
		$out[ $fam ] = count( $set );
	}
	uksort(
		$out,
		static function ( $a, $b ) use ( $out ) {
			return ( $out[ $b ] <=> $out[ $a ] ) ?: strcmp( (string) $a, (string) $b );
		}
	);
	return $out;
}

/**
 * Families with enough presence to carry a statistic.
 *
 * @param int $min_days Defaults to the derived floor.
 * @return string[] Sorted by day count descending, then family ascending.
 */
function snt_mr_eligible_families( array $rows, $from, $to, $min_days = SN_MR_SERIES_MIN_DAYS ) {
	$out = array();
	foreach ( snt_mr_family_days( $rows, $from, $to ) as $fam => $n ) {
		if ( $n >= (int) $min_days ) {
			$out[] = $fam;
		}
	}
	return $out;
}
