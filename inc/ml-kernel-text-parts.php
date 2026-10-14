<?php
/**
 * Signal & Noise: ML kernel, the text primitives (stopwords, the tokenizer, corpus statistics, TF-IDF, cosine, BM25 and its tf form, Jaccard).
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

if ( ! function_exists( 'snt_ml_stopwords' ) ) {
	/**
	 * Small built-in English stopword list (deliberately conservative: function
	 * words only, so domain terms like "signal" or "noise" always survive).
	 *
	 * @return array<string,true> Stopword => true, for O(1) lookup.
	 */
	function snt_ml_stopwords() {
		static $map = null;
		if ( null === $map ) {
			$words = array(
				'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for',
				'from', 'had', 'has', 'have', 'if', 'in', 'into', 'is', 'it',
				'its', 'no', 'not', 'of', 'on', 'or', 'so', 'than', 'that',
				'the', 'their', 'then', 'there', 'these', 'they', 'this', 'to',
				'was', 'we', 'were', 'what', 'when', 'which', 'will', 'with',
				'you', 'your',
			);
			$map   = array_fill_keys( $words, true );
		}
		return $map;
	}
}

if ( ! function_exists( 'snt_ml_tokenize' ) ) {
	/**
	 * Deterministic unicode-safe tokenizer.
	 *
	 * Accepts raw post_content (Gutenberg block comments + HTML tags are
	 * stripped first) or already-stripped plain text — stripping a clean
	 * string is a no-op, so callers need not care which they hold. Lowercases
	 * (mb-aware), splits on any non-letter/non-digit run, drops the built-in
	 * stopwords and tokens shorter than 2 characters.
	 *
	 * @param string $text Raw or pre-stripped text.
	 * @return string[] Ordered token list (duplicates preserved — TF matters).
	 */
	function snt_ml_tokenize( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array();
		}
		// Block comments BEFORE tags: `<!-- wp:x {"a":1} -->` carries JSON the
		// tag-stripper would otherwise leak as tokens.
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		// Non-prose CONTAINERS drop whole, contents included, BEFORE the tag
		// stripper — that pass removes tags but keeps their text, and style/
		// script text is not prose. Found live (v11.3.1): an inline SVG
		// figure's <style> block dominated a cluster's vocabulary, and the
		// first reader-facing label read "currentcolor · fill". The SVG's
		// visible <text> is prose and survives the ordinary tag strip.
		$text = preg_replace( '/<(style|script)\b[^>]*>.*?<\/\1\s*>/is', ' ', $text );
		$text = preg_replace( '/<[^>]*>/', ' ', $text );
		$text = mb_strtolower( $text, 'UTF-8' );

		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			return array(); // Invalid UTF-8: no tokens beats a PHP warning.
		}

		$stop   = snt_ml_stopwords();
		$tokens = array();
		foreach ( $parts as $part ) {
			if ( isset( $stop[ $part ] ) || mb_strlen( $part, 'UTF-8' ) < 2 ) {
				continue;
			}
			$tokens[] = $part;
		}
		return $tokens;
	}
}

if ( ! function_exists( 'snt_ml_corpus_stats' ) ) {
	/**
	 * Corpus-level statistics for TF-IDF and BM25.
	 *
	 * IDF is smoothed — log((N+1)/(df+1)) + 1 — so a term present in EVERY
	 * doc still weighs exactly 1.0 (never 0, never negative) and an unseen
	 * term (df 0) stays finite.
	 *
	 * @param array<int|string,string[]> $docs Doc id => token array.
	 * @return array {
	 *     @type int                     $doc_count   N.
	 *     @type string[]                $vocab       Distinct terms.
	 *     @type array<string,int>       $df          Term => docs containing it.
	 *     @type array<string,float>     $idf         Term => smoothed idf.
	 *     @type array<int|string,int>   $doc_lengths Doc id => token count.
	 *     @type float                   $avg_length  Mean doc length (0.0 when empty).
	 * }
	 */
	function snt_ml_corpus_stats( array $docs ) {
		$df      = array();
		$lengths = array();
		foreach ( $docs as $id => $tokens ) {
			$tokens         = is_array( $tokens ) ? $tokens : array();
			$lengths[ $id ] = count( $tokens );
			foreach ( array_unique( $tokens ) as $term ) {
				$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
			}
		}
		$n   = count( $docs );
		$idf = array();
		foreach ( $df as $term => $count ) {
			$idf[ $term ] = log( ( $n + 1 ) / ( $count + 1 ) ) + 1.0;
		}
		return array(
			'doc_count'   => $n,
			'vocab'       => array_keys( $df ),
			'df'          => $df,
			'idf'         => $idf,
			'doc_lengths' => $lengths,
			'avg_length'  => $n > 0 ? array_sum( $lengths ) / $n : 0.0,
		);
	}
}

if ( ! function_exists( 'snt_ml_tfidf_vector' ) ) {
	/**
	 * Sparse L2-normalized TF-IDF vector for one document.
	 *
	 * Terms absent from the stats get the df=0 smoothed idf, so out-of-corpus
	 * docs (e.g. a fresh draft scored against the built corpus) still vector.
	 *
	 * @param string[] $tokens Document tokens.
	 * @param array    $stats  snt_ml_corpus_stats() output.
	 * @return array<string,float> Term => weight; unit L2 norm (empty on no tokens).
	 */
	function snt_ml_tfidf_vector( array $tokens, array $stats ) {
		if ( array() === $tokens ) {
			return array();
		}
		$idf_map = isset( $stats['idf'] ) && is_array( $stats['idf'] ) ? $stats['idf'] : array();
		$n       = isset( $stats['doc_count'] ) ? (int) $stats['doc_count'] : 0;
		$oov_idf = log( ( $n + 1 ) / 1.0 ) + 1.0; // df = 0 under the same smoothing.

		$vector = array();
		foreach ( array_count_values( $tokens ) as $term => $tf ) {
			$vector[ $term ] = $tf * ( $idf_map[ $term ] ?? $oov_idf );
		}
		$norm = sqrt( array_sum( array_map( static function ( $w ) { return $w * $w; }, $vector ) ) );
		if ( $norm <= 0.0 ) {
			return array();
		}
		foreach ( $vector as $term => $weight ) {
			$vector[ $term ] = $weight / $norm;
		}
		return $vector;
	}
}

if ( ! function_exists( 'snt_ml_cosine' ) ) {
	/**
	 * Sparse-safe cosine similarity.
	 *
	 * @param array<string,float> $a Sparse vector.
	 * @param array<string,float> $b Sparse vector.
	 * @return float 0.0 when either vector is empty or zero-normed.
	 */
	function snt_ml_cosine( array $a, array $b ) {
		if ( array() === $a || array() === $b ) {
			return 0.0;
		}
		// Iterate the smaller map; only shared terms contribute to the dot.
		if ( count( $b ) < count( $a ) ) {
			list( $a, $b ) = array( $b, $a );
		}
		$dot = 0.0;
		foreach ( $a as $term => $weight ) {
			if ( isset( $b[ $term ] ) ) {
				$dot += $weight * $b[ $term ];
			}
		}
		$norm_a = sqrt( array_sum( array_map( static function ( $w ) { return $w * $w; }, $a ) ) );
		$norm_b = sqrt( array_sum( array_map( static function ( $w ) { return $w * $w; }, $b ) ) );
		if ( $norm_a <= 0.0 || $norm_b <= 0.0 ) {
			return 0.0;
		}
		return $dot / ( $norm_a * $norm_b );
	}
}

if ( ! function_exists( 'snt_ml_bm25_score' ) ) {
	/**
	 * Okapi BM25 score of one document against a query.
	 *
	 * Uses the corpus' smoothed idf (always positive, so no negative-idf
	 * clamping is needed). Query term multiplicity is ignored — each distinct
	 * query term contributes once, standard for short queries.
	 *
	 * @param string[] $query_tokens Query tokens.
	 * @param string[] $doc_tokens   Document tokens.
	 * @param array    $stats        snt_ml_corpus_stats() output.
	 * @param float    $k1           TF saturation (default 1.2).
	 * @param float    $b            Length-normalization strength (default 0.75).
	 * @return float 0.0 when nothing overlaps or the doc is empty.
	 */
	function snt_ml_bm25_score( array $query_tokens, array $doc_tokens, array $stats, $k1 = 1.2, $b = 0.75 ) {
		if ( array() === $query_tokens || array() === $doc_tokens ) {
			return 0.0;
		}
		return snt_ml_bm25_score_tf( $query_tokens, array_count_values( $doc_tokens ), count( $doc_tokens ), $stats, $k1, $b );
	}
}

if ( ! function_exists( 'snt_ml_bm25_score_tf' ) ) {
	/**
	 * BM25 from a term-frequency map and a token length — the form a stored
	 * index holds (v14.0.0, notes search). snt_ml_bm25_score() delegates
	 * here, so the two can never disagree.
	 *
	 * @param string[]          $query_tokens Query tokens.
	 * @param array<string,int> $tf_map       Term => count in the document.
	 * @param int               $doc_len      Total token count of the document.
	 * @param array             $stats        snt_ml_corpus_stats() output (idf + avg_length are read).
	 * @param float             $k1           TF saturation (default 1.2).
	 * @param float             $b            Length-normalization strength (default 0.75).
	 * @return float 0.0 when nothing overlaps or the doc is empty.
	 */
	function snt_ml_bm25_score_tf( array $query_tokens, array $tf_map, $doc_len, array $stats, $k1 = 1.2, $b = 0.75 ) {
		$doc_len = (int) $doc_len;
		if ( array() === $query_tokens || array() === $tf_map || $doc_len <= 0 ) {
			return 0.0;
		}
		$idf_map = isset( $stats['idf'] ) && is_array( $stats['idf'] ) ? $stats['idf'] : array();
		$avg_len = isset( $stats['avg_length'] ) ? (float) $stats['avg_length'] : 0.0;
		if ( $avg_len <= 0.0 ) {
			$avg_len = (float) $doc_len; // Degenerate corpus: neutral length norm.
		}
		$score = 0.0;
		foreach ( array_unique( $query_tokens ) as $term ) {
			$tf = (int) ( $tf_map[ $term ] ?? 0 );
			if ( 0 === $tf || ! isset( $idf_map[ $term ] ) ) {
				continue; // Out-of-corpus query terms carry no ranking signal.
			}
			$score += $idf_map[ $term ] * ( $tf * ( $k1 + 1 ) )
				/ ( $tf + $k1 * ( 1 - $b + $b * $doc_len / $avg_len ) );
		}
		return $score;
	}
}

if ( ! function_exists( 'snt_ml_jaccard' ) ) {
	/**
	 * Jaccard similarity of two string sets (duplicates collapsed).
	 *
	 * @param string[] $a Set A.
	 * @param string[] $b Set B.
	 * @return float 0.0 on an empty union (never a division warning).
	 */
	function snt_ml_jaccard( array $a, array $b ) {
		$a = array_unique( $a );
		$b = array_unique( $b );
		$union = count( array_unique( array_merge( $a, $b ) ) );
		if ( 0 === $union ) {
			return 0.0;
		}
		return count( array_intersect( $a, $b ) ) / $union;
	}
}

