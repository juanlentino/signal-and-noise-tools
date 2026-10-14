<?php
/**
 * Signal & Noise: ML kernel, the cadence and share math (the robust and plain cadence deviations, the document share).
 *
 * Split from inc/ml-kernel.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_ml_cadence_deviation_robust' ) ) {
	/**
	 * Cadence deviation, burst-resistant (v10.32.0). Same question as
	 * snt_ml_cadence_deviation — how surprising is the CURRENT gap? — asked
	 * with order statistics instead of moments: the MEDIAN interval is the
	 * expectation and the MAD (median absolute deviation, scaled by the
	 * 1.4826 normal-consistency constant) is the spread.
	 *
	 * Why: mean/σ has a breakdown point of 0 — one burst of tightly-spaced
	 * firings drags the expectation down AND collapses the spread, so the
	 * next ordinary quiet spell z-scores into the double digits. Median/MAD
	 * has a breakdown point of 50%: the burst has to be more than half the
	 * window before it moves the verdict.
	 *
	 * Also reports SPAN — the wall-clock reach of the window — because a
	 * fixed-count window says nothing about how much time it observed, and
	 * callers must be able to refuse to trust a window that only saw a
	 * weekend.
	 *
	 * Honest unknowns preserved verbatim from the EWMA sibling: fewer than
	 * five events is null, and a history with NO spread at all makes surprise
	 * unquantifiable (z null — never infinity, never zero). Note the scale
	 * fallback below: "no spread" means every interval identical, not merely
	 * a zero MAD.
	 *
	 * @since 10.32.0
	 * @param array<int,int|float> $events Unix timestamps of past events.
	 * @param int|float            $now    The observation instant.
	 * @return array{intervals:int,median:float,mad:float,scale:float,span:float,current_gap:float,z:float|null}|null
	 */
	function snt_ml_cadence_deviation_robust( $events, $now ) {
		$ts = array();
		foreach ( (array) $events as $t ) {
			if ( is_numeric( $t ) ) {
				$ts[] = (float) $t;
			}
		}
		if ( count( $ts ) < 5 ) {
			return null; // Too little history: unknown, not a verdict.
		}
		sort( $ts );

		$intervals = array();
		$n         = count( $ts );
		for ( $i = 1; $i < $n; $i++ ) {
			$intervals[] = $ts[ $i ] - $ts[ $i - 1 ];
		}

		$median = (float) snt_ml_median( $intervals );
		$devs   = array();
		foreach ( $intervals as $x ) {
			$devs[] = abs( $x - $median );
		}
		$mad = (float) snt_ml_median( $devs );
		if ( $mad > 0.0 ) {
			$scale = 1.4826 * $mad; // MAD → σ-equivalent for normally distributed intervals.
		} else {
			// MAD is exactly 0 as soon as a strict MAJORITY of intervals
			// repeat — far easier than the mean/σ path's "every interval
			// identical", and real cron produces bit-identical gaps by the
			// dozen. Treating those as unquantifiable would be a WORSE blind
			// spot than the math this replaces, so fall back to the mean
			// absolute deviation (sqrt(pi/2) scales it to a σ-equivalent).
			// A perfectly rigid history still yields 0 and stays an honest
			// unknown — the documented metronome posture is untouched.
			$scale = sqrt( M_PI / 2.0 ) * ( array_sum( $devs ) / count( $devs ) );
		}
		$gap = (float) $now - $ts[ $n - 1 ];

		return array(
			'intervals'   => count( $intervals ),
			'median'      => $median,
			'mad'         => $mad,
			'scale'       => $scale,
			'span'        => $ts[ $n - 1 ] - $ts[0],
			'current_gap' => $gap,
			'z'           => $scale > 0.0 ? ( $gap - $median ) / $scale : null,
		);
	}
}

if ( ! function_exists( 'snt_ml_cadence_deviation' ) ) {
	/**
	 * Cadence deviation (v10.22.0, pipeline #5): how surprising is the CURRENT
	 * gap, given the rhythm of past events? EWMA over the inter-event
	 * intervals (alpha-weighted, seeded on the first interval) supplies the
	 * expected gap; a z-score of (now - last event) against the plain
	 * population spread quantifies the surprise. Deterministic: same events +
	 * same now, same verdict; input order is canonicalized away.
	 *
	 * Honest unknowns, never numbers: fewer than five events is too little
	 * history (null verdict), and a zero-spread metronome history makes
	 * surprise unquantifiable (z null — never infinity, never zero).
	 *
	 * @param array<int,int|float> $events Unix timestamps of past events.
	 * @param int|float            $now    The observation instant.
	 * @param float                $alpha  EWMA smoothing factor.
	 * @return array{intervals:int,ewma:float,std:float,current_gap:float,z:float|null}|null
	 */
	function snt_ml_cadence_deviation( $events, $now, $alpha = 0.3 ) {
		$ts = array();
		foreach ( (array) $events as $t ) {
			if ( is_numeric( $t ) ) {
				$ts[] = (float) $t;
			}
		}
		if ( count( $ts ) < 5 ) {
			return null; // Too little history: unknown, not a verdict.
		}
		sort( $ts );

		$intervals = array();
		$n         = count( $ts );
		for ( $i = 1; $i < $n; $i++ ) {
			$intervals[] = $ts[ $i ] - $ts[ $i - 1 ];
		}

		$alpha = (float) $alpha;
		$ewma  = $intervals[0];
		$m     = count( $intervals );
		for ( $i = 1; $i < $m; $i++ ) {
			$ewma = $alpha * $intervals[ $i ] + ( 1.0 - $alpha ) * $ewma;
		}

		$mean = array_sum( $intervals ) / $m;
		$var  = 0.0;
		foreach ( $intervals as $x ) {
			$var += ( $x - $mean ) * ( $x - $mean );
		}
		$var /= $m;
		$std  = sqrt( $var );

		$gap = (float) $now - $ts[ $n - 1 ];

		return array(
			'intervals'   => $m,
			'ewma'        => $ewma,
			'std'         => $std,
			'current_gap' => $gap,
			'z'           => $std > 0.0 ? ( $gap - $ewma ) / $std : null,
		);
	}
}

if ( ! function_exists( 'snt_ml_doc_share' ) ) {
	/**
	 * Document share per term: the fraction of documents in this bucket that
	 * contain the term at least once.
	 *
	 * WHY SHARE AND NOT TF-IDF (the load-bearing choice for drift): idf is
	 * computed with N = the documents in that same call (snt_ml_corpus_stats),
	 * so a term's tf-idf weight is relative to its OWN bucket. Comparing one
	 * period's weights against another's would compare two different scales and
	 * report movement that is an artefact of how many notes each period held.
	 * Document share is on one scale across every bucket. It is also robust to a
	 * single verbose note repeating a word — the noise that dominates at small N.
	 *
	 * A term absent from the bucket is ABSENT from the returned map, never a 0.0
	 * entry: absent and zero are different answers, and the caller classifies
	 * them differently (entered/silenced versus movement).
	 *
	 * @param array<int|string,string[]> $docs Doc id => token array.
	 * @return array {
	 *     @type array<string,float> $shares Term => docs containing it / docs.
	 *     @type int                 $docs   Bucket size (0 => shares is empty).
	 * }
	 */
	function snt_ml_doc_share( array $docs ) {
		$n = count( $docs );
		if ( 0 === $n ) {
			return array( 'shares' => array(), 'docs' => 0 );
		}
		$df = array();
		foreach ( $docs as $tokens ) {
			$tokens = is_array( $tokens ) ? $tokens : array();
			foreach ( array_unique( $tokens ) as $term ) {
				$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
			}
		}
		$shares = array();
		foreach ( $df as $term => $count ) {
			// The float cast is LOAD-BEARING: PHP's / returns an INT when
			// evenly divisible, so a 5-of-5 share would be int 1, its delta
			// int 0, and the caller's strict 0.0 no-movement check would miss
			// it — every stationary term at a whole-number share would leak
			// into risen/fallen. Caught by the ml-drift suite's stationary pin.
			$shares[ $term ] = (float) ( $count / $n );
		}
		return array( 'shares' => $shares, 'docs' => $n );
	}
}

