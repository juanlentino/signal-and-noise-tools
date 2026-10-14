<?php
/**
 * Signal & Noise: ML kernel, the corpus drift and the cluster path.
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

if ( ! function_exists( 'snt_ml_corpus_drift' ) ) {
	/**
	 * Per-term vocabulary movement between two periods of the corpus.
	 *
	 * The mirror snt_ml_cosine() cannot be: cosine returns ONE scalar, which
	 * tells a writer their vocabulary changed and nothing about WHAT changed.
	 * This returns four disjoint lists, because the four cases are editorially
	 * different questions:
	 *
	 * - risen / fallen — the term is present in BOTH periods and moved.
	 * - entered        — no presence before, present after. Not a delta from
	 *                    zero: the term had no share to move from.
	 * - silenced       — present before, no presence after. Likewise.
	 *
	 * A term present in both periods at the SAME share appears in no list at
	 * all. No movement is not a movement of zero, and padding the lists with
	 * stationary terms would bury the finding.
	 *
	 * THE THIN GATE: either period below $min_docs returns verdict 'thin' with
	 * every list empty — a distinct answer from "no drift". A term appearing in
	 * one note and then two has not risen; the corpus is too small for the word
	 * to mean anything. Same discipline as snt_ml_cadence_deviation_robust()
	 * reporting SPAN: a window that saw almost nothing must be able to say so
	 * rather than publish a confident number. The bucket sizes are reported
	 * either way, so the caller can render WHY it refused.
	 *
	 * Deterministic: every list sorts by magnitude descending, ties broken on
	 * the term ascending — never on array insertion or hash order.
	 *
	 * @param array<int|string,string[]> $before   Doc id => tokens, earlier period.
	 * @param array<int|string,string[]> $after    Doc id => tokens, later period.
	 * @param int                        $min_docs Floor per period (default 5).
	 * @param int                        $top      Max rows per list (default 12).
	 * @return array {
	 *     @type string $verdict  'ok' | 'thin'.
	 *     @type array  $docs     {before:int, after:int}.
	 *     @type array  $risen    list of {term, before, after, delta}.
	 *     @type array  $fallen   list of {term, before, after, delta}.
	 *     @type array  $entered  list of {term, after}.
	 *     @type array  $silenced list of {term, before}.
	 * }
	 */
	function snt_ml_corpus_drift( array $before, array $after, $min_docs = 5, $top = 12 ) {
		$a = snt_ml_doc_share( $before );
		$b = snt_ml_doc_share( $after );

		$empty = array(
			'verdict'  => 'thin',
			'docs'     => array( 'before' => $a['docs'], 'after' => $b['docs'] ),
			'risen'    => array(),
			'fallen'   => array(),
			'entered'  => array(),
			'silenced' => array(),
		);
		$min_docs = max( 0, (int) $min_docs );
		if ( $a['docs'] < $min_docs || $b['docs'] < $min_docs ) {
			return $empty;
		}

		$risen    = array();
		$fallen   = array();
		$entered  = array();
		$silenced = array();

		foreach ( $b['shares'] as $term => $after_share ) {
			if ( ! array_key_exists( $term, $a['shares'] ) ) {
				$entered[] = array( 'term' => (string) $term, 'after' => $after_share );
				continue;
			}
			// Both operands are floats by snt_ml_doc_share()'s cast, so the
			// strict 0.0 comparison below is sound. Belt-and-braces cast
			// anyway: this fn also accepts share maps a future caller built
			// by hand, and int-typed shares would resurrect the leak.
			$delta = (float) $after_share - (float) $a['shares'][ $term ];
			if ( 0.0 === $delta ) {
				continue;
			}
			$row = array(
				'term'   => (string) $term,
				'before' => $a['shares'][ $term ],
				'after'  => $after_share,
				'delta'  => $delta,
			);
			if ( $delta > 0.0 ) {
				$risen[] = $row;
			} else {
				$fallen[] = $row;
			}
		}
		foreach ( $a['shares'] as $term => $before_share ) {
			if ( ! array_key_exists( $term, $b['shares'] ) ) {
				$silenced[] = array( 'term' => (string) $term, 'before' => $before_share );
			}
		}

		// Ties break on the term so the output is stable across PHP versions
		// and insertion orders — a mirror that reshuffles between runs reads as
		// drift that did not happen.
		$by = function ( $key, $desc ) {
			return function ( $x, $y ) use ( $key, $desc ) {
				$cmp = $x[ $key ] < $y[ $key ] ? -1 : ( $x[ $key ] > $y[ $key ] ? 1 : 0 );
				if ( 0 !== $cmp ) {
					return $desc ? -$cmp : $cmp;
				}
				return strcmp( $x['term'], $y['term'] );
			};
		};
		usort( $risen, $by( 'delta', true ) );
		usort( $fallen, $by( 'delta', false ) );
		usort( $entered, $by( 'after', true ) );
		usort( $silenced, $by( 'before', true ) );

		$top = max( 0, (int) $top );
		return array(
			'verdict'  => 'ok',
			'docs'     => array( 'before' => $a['docs'], 'after' => $b['docs'] ),
			'risen'    => array_slice( $risen, 0, $top ),
			'fallen'   => array_slice( $fallen, 0, $top ),
			'entered'  => array_slice( $entered, 0, $top ),
			'silenced' => array_slice( $silenced, 0, $top ),
		);
	}
}

if ( ! function_exists( 'snt_ml_cluster_path' ) ) {
	/**
	 * A deterministic reading chain through one cluster (R4 4B).
	 *
	 * The stored partition holds membership and a label — no geometry. This is
	 * the ordering: start at the most CENTRAL member (highest summed cosine to
	 * the rest — the note a reader can enter cold), then repeatedly step to the
	 * most similar unvisited member (greedy nearest-neighbour — consecutive
	 * notes should share the most vocabulary). An outlier therefore lands at
	 * the chain's end, never mid-flow.
	 *
	 * Sequencing, not personalization: pure arithmetic over the vectors, the
	 * same chain for every reader, recomputed only when the artifact rebuilds.
	 *
	 * Every tie breaks on the LOWEST id (centrality ties and step ties alike),
	 * so an unchanged corpus can never flap its chains between rebuilds.
	 *
	 * A chain of one is NO chain: fewer than two members with vectors returns
	 * array() — the singleton exclusion travelling with the geometry.
	 *
	 * @param array<int,array<string,float>> $vectors Sparse vectors keyed by doc id.
	 * @param array<int,int>                 $members Cluster member ids.
	 * @return int[] Ordered member ids; array() when no chain exists.
	 */
	function snt_ml_cluster_path( $vectors, $members ) {
		$ids = array();
		foreach ( (array) $members as $id ) {
			if ( isset( $vectors[ $id ] ) ) {
				$ids[] = (int) $id;
			}
		}
		sort( $ids );
		$n = count( $ids );
		if ( $n < 2 ) {
			return array();
		}

		// Pairwise cosines once; the walk below only reads.
		$sim = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$c = snt_ml_cosine( $vectors[ $ids[ $i ] ], $vectors[ $ids[ $j ] ] );
				$sim[ $ids[ $i ] ][ $ids[ $j ] ] = $c;
				$sim[ $ids[ $j ] ][ $ids[ $i ] ] = $c;
			}
		}

		// Central member: highest summed similarity; ties to the lowest id
		// (the ascending $ids walk with a strict > keeps the first seen).
		$start = $ids[0];
		$best  = -1.0;
		foreach ( $ids as $id ) {
			$total = array_sum( $sim[ $id ] ?? array() );
			if ( $total > $best ) {
				$best  = $total;
				$start = $id;
			}
		}

		$path    = array( $start );
		$visited = array( $start => true );
		$at      = $start;
		while ( count( $path ) < $n ) {
			$next      = null;
			$next_best = -1.0;
			foreach ( $ids as $id ) {
				if ( isset( $visited[ $id ] ) ) {
					continue;
				}
				$c = $sim[ $at ][ $id ] ?? 0.0;
				if ( $c > $next_best ) {
					$next_best = $c;
					$next      = $id;
				}
			}
			$path[]           = $next;
			$visited[ $next ] = true;
			$at               = $next;
		}
		return $path;
	}
}
