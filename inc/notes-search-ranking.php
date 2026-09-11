<?php
/**
 * Signal & Noise Tools — notes search served by the kernel (v13.111.0).
 *
 * The theme's /notes/?s= query is WordPress's every-word LIKE, in date order.
 * This module ranks NOTES with the kernel's BM25 over the search index the
 * corpus build writes (inc/ml-artifacts.php), and shapes the theme's
 * EXISTING WP_Query through posts_clauses: the WHERE is widened to admit
 * kernel-ranked ids, the ORDER BY gets FIELD() in rank order in front of the
 * date. Pages and LIKE-only notes follow in date order exactly as before.
 *
 * The theme opts in with one query var, sn_notes_search => true, and takes
 * the evidence snippet through one filter, sn_notes_search_snippet. With the
 * plugin off, both are inert: today's search, byte for byte.
 *
 * Spec: docs/proposals/2026-09-11-notes-search-kernel-design.md.
 *
 * @package SignalNoiseTools
 * @since 13.111.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard cap on ranked ids reaching SQL — FIELD() with hundreds of args is fine, thousands is not. */
const SNT_SEARCH_RANK_CAP = 500;

/**
 * Rank the notes corpus against a search term. Memoised per request (one
 * search page = one term = several callers: the clauses hook and every row's
 * snippet).
 *
 * @param string $term  The search term as the theme sanitised it.
 * @param bool   $reset Tests only: clear the memo.
 * @return int[] Note ids, best first; empty when nothing can be ranked.
 */
function snt_search_rank_notes( $term, $reset = false ) {
	static $memo = array();
	if ( $reset ) {
		$memo = array();
		return array();
	}
	$key = (string) $term;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$ids   = array();
	$index = function_exists( 'snt_ml_search_index' ) ? snt_ml_search_index() : null;
	$query = function_exists( 'snt_ml_tokenize' ) ? array_values( array_unique( snt_ml_tokenize( $key ) ) ) : array();

	if ( is_array( $index ) && array() !== $query && function_exists( 'snt_ml_bm25_score_tf' ) ) {
		$scores = array();
		foreach ( (array) $index['docs'] as $id => $doc ) {
			$score = snt_ml_bm25_score_tf( $query, (array) ( $doc['tf'] ?? array() ), (int) ( $doc['len'] ?? 0 ), (array) $index['stats'] );
			if ( $score > 0.0 ) {
				$scores[ (int) $id ] = $score;
			}
		}
		// Score DESC, then ID DESC: the index carries no dates, and a higher
		// id is a newer note — no DB read inside the ranking.
		uksort( $scores, static function ( $a, $b ) use ( $scores ) {
			return ( $scores[ $b ] <=> $scores[ $a ] ) ?: ( $b <=> $a );
		} );
		$ids = array_keys( $scores );
	}

	/**
	 * Replace the ranked id list. The plugin's usual seam; nothing else here
	 * is filterable.
	 *
	 * @param int[]  $ids  Ranked note ids, best first.
	 * @param string $term The search term.
	 */
	$ids = (array) apply_filters( 'snt_search_ranking_ids', $ids, $key );
	$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $i ) { return $i > 0; } ) );

	$memo[ $key ] = array_slice( $ids, 0, SNT_SEARCH_RANK_CAP );
	return $memo[ $key ];
}
