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

/**
 * posts_clauses: shape the theme's search query. Untouched unless the query
 * carries sn_notes_search => true AND the ranking is non-empty.
 *
 * WHERE: the existing clause is kept whole and OR-ed with the ranked ids;
 * the OR branch re-applies publish + no-password, so widening can never
 * out-scope the query it widens (a note unpublished since the last rebuild
 * stays out).
 *
 * ORDER BY: FIELD(ID, …ids reversed…) DESC in front of whatever the theme
 * ordered by. FIELD() returns the 1-based position or 0; with the list
 * reversed the best-ranked id has the highest position, DESC puts it first,
 * and every unranked row (0) sorts after all of them, in the theme's order.
 *
 * @param array  $clauses
 * @param object $query   WP_Query (or a stand-in exposing get()).
 * @return array
 */
function snt_search_posts_clauses( $clauses, $query = null ) {
	if ( ! is_array( $clauses ) || ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
		return $clauses;
	}
	if ( true !== $query->get( 'sn_notes_search' ) ) {
		return $clauses;
	}
	$ids = snt_search_rank_notes( (string) $query->get( 's' ) );
	if ( array() === $ids ) {
		return $clauses;
	}
	global $wpdb;
	$t    = isset( $wpdb->posts ) ? (string) $wpdb->posts : 'wp_posts';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are int-cast, table is $wpdb->posts
	$list = implode( ',', array_map( 'intval', $ids ) ); // int-cast: the only thing that reaches SQL

	$where = (string) ( $clauses['where'] ?? '' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are int-cast, table is $wpdb->posts
	$clauses['where'] = " AND ( (1=1{$where}) OR ( {$t}.ID IN ({$list}) AND {$t}.post_status = 'publish' AND {$t}.post_password = '' ) )";

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are int-cast, table is $wpdb->posts
	$field   = 'FIELD(' . $t . '.ID, ' . implode( ',', array_reverse( array_map( 'intval', $ids ) ) ) . ') DESC';
	$orderby = trim( (string) ( $clauses['orderby'] ?? '' ) );
	$clauses['orderby'] = '' === $orderby ? $field : $field . ', ' . $orderby;
	return $clauses;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'posts_clauses', 'snt_search_posts_clauses', 10, 2 );
}

/**
 * The evidence snippet for one ranked note: the first sentence holding the
 * rarest query token the reader typed, escaped, every query token wrapped
 * in <mark>, cut at a word boundary. Anything else — a LIKE-only note, a
 * page, an unknown post, no matching sentence — returns the excerpt it was
 * given, so the theme's default stands.
 *
 * Prose comes from sn_prov_normalize_v2(), the normaliser the ledger
 * signs: no block markup reaches a snippet by construction.
 *
 * @param string $excerpt The theme's excerpt (the fallback).
 * @param int    $post_id
 * @param string $term
 * @return string HTML-safe: text escaped, <mark> the only tag.
 */
function snt_search_snippet( $excerpt, $post_id, $term ) {
	$post_id = (int) $post_id;
	$term    = trim( (string) $term );
	if ( $post_id <= 0 || '' === $term || ! function_exists( 'snt_ml_tokenize' ) ) {
		return $excerpt;
	}
	if ( ! in_array( $post_id, snt_search_rank_notes( $term ), true ) ) {
		return $excerpt;
	}
	$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	if ( ! $post || '' === trim( (string) ( $post->post_content ?? '' ) ) ) {
		return $excerpt;
	}
	$prose = function_exists( 'sn_prov_normalize_v2' )
		? sn_prov_normalize_v2( (string) $post->post_content )
		: wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', ' ', (string) $post->post_content ) );
	$prose = trim( preg_replace( '/\s+/u', ' ', (string) $prose ) );
	if ( '' === $prose ) {
		return $excerpt;
	}

	// Rarest query token first: the strongest evidence the reader typed.
	$tokens = array_values( array_unique( snt_ml_tokenize( $term ) ) );
	if ( array() === $tokens ) {
		return $excerpt;
	}
	$index = function_exists( 'snt_ml_search_index' ) ? snt_ml_search_index() : null;
	$idf   = is_array( $index ) && isset( $index['stats']['idf'] ) ? (array) $index['stats']['idf'] : array();
	usort( $tokens, static function ( $a, $b ) use ( $idf ) {
		return ( (float) ( $idf[ $b ] ?? 0 ) <=> (float) ( $idf[ $a ] ?? 0 ) ) ?: strcmp( $a, $b );
	} );

	$sentence = '';
	foreach ( $tokens as $token ) {
		if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $token, '/' ) . '(?![\p{L}\p{N}])/iu', $prose, $m, PREG_OFFSET_CAPTURE ) ) {
			$sentence = function_exists( 'snt_corpus_integrity_sentence_at' )
				? snt_corpus_integrity_sentence_at( $prose, (int) $m[0][1] )
				: $prose;
			break;
		}
	}
	if ( '' === $sentence ) {
		return $excerpt;
	}
	// sentence_at() caps at 280 chars with its own "...": strip that before
	// the word cap below so the two ellipses never stack.
	$sentence = (string) preg_replace( '/(?:\.\.\.|…)$/u', '', $sentence );
	if ( '' === $sentence ) {
		return $excerpt; // includes an invalid-UTF-8 sentence, which the /u strip returns as null
	}

	// Cap at the excerpt length, at a word boundary.
	$max_words = (int) apply_filters( 'excerpt_length', 55 );
	$words     = preg_split( '/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $words ) && count( $words ) > $max_words ) {
		$sentence = implode( ' ', array_slice( $words, 0, $max_words ) ) . '…';
	}

	// Escape first, then mark whole words — tokens are letters/digits only,
	// so escaping cannot split one.
	$safe = esc_html( $sentence );
	foreach ( $tokens as $token ) {
		// The lookbehind also excludes & and #: after esc_html an apostrophe
		// is &#039; and "039" is a legal token — without this a <mark> lands
		// inside the entity.
		$safe = preg_replace( '/(?<![\p{L}\p{N}&#])(' . preg_quote( $token, '/' ) . ')(?![\p{L}\p{N}])/iu', '<mark>$1</mark>', $safe );
	}
	return wp_kses( $safe, array( 'mark' => array() ) );
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sn_notes_search_snippet', 'snt_search_snippet', 10, 3 );
}
