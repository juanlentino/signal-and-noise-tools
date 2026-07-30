<?php
/**
 * Signal & Noise — ML kernel (pure computational core).
 *
 * The primitives a site-wide pipeline registry (inc/ml-pipelines.php)
 * composes: tokenizer, corpus statistics, TF-IDF, cosine, BM25, and the
 * graph-signal / blended relatedness scorers. Everything here is arithmetic
 * over arrays the caller already fetched.
 *
 * ── PURE MODULE — the load-bearing constraint ────────────────────────────────
 *
 * ZERO WordPress calls, zero globals, zero I/O (model: inc/analytics-derive.php).
 * Tests require() this real file directly, so the asserted behaviour IS the
 * shipped behaviour — and tests/ml-kernel.php grep-pins this file's text free
 * of WP function names. Filterability (e.g. relatedness weights) is the
 * INTEGRATION layer's job: pure fns take overrides as plain arguments.
 * Declarations are function_exists-guarded so a double-require never fatals.
 *
 * @package SignalNoiseTools
 * @since 10.14.0
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
		$idf_map = isset( $stats['idf'] ) && is_array( $stats['idf'] ) ? $stats['idf'] : array();
		$avg_len = isset( $stats['avg_length'] ) ? (float) $stats['avg_length'] : 0.0;
		$doc_len = count( $doc_tokens );
		if ( $avg_len <= 0.0 ) {
			$avg_len = (float) $doc_len; // Degenerate corpus: neutral length norm.
		}
		$tf_map = array_count_values( $doc_tokens );
		$score  = 0.0;
		foreach ( array_unique( $query_tokens ) as $term ) {
			$tf = $tf_map[ $term ] ?? 0;
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

if ( ! function_exists( 'snt_ml_graph_signals' ) ) {
	/**
	 * Link/taxonomy graph signals between two posts.
	 *
	 * @param array $post_a { @type string[] $tags @type string[] $links_out (slugs) @type string $slug }
	 * @param array $post_b Same shape.
	 * @return array {
	 *     @type float $tag_overlap Jaccard of tag sets.
	 *     @type int   $direct_link 1 when either post links to the other's slug.
	 *     @type float $co_link     Jaccard of outbound-link sets.
	 * }
	 */
	function snt_ml_graph_signals( array $post_a, array $post_b ) {
		$tags_a  = isset( $post_a['tags'] ) && is_array( $post_a['tags'] ) ? $post_a['tags'] : array();
		$tags_b  = isset( $post_b['tags'] ) && is_array( $post_b['tags'] ) ? $post_b['tags'] : array();
		$links_a = isset( $post_a['links_out'] ) && is_array( $post_a['links_out'] ) ? $post_a['links_out'] : array();
		$links_b = isset( $post_b['links_out'] ) && is_array( $post_b['links_out'] ) ? $post_b['links_out'] : array();
		$slug_a  = isset( $post_a['slug'] ) ? (string) $post_a['slug'] : '';
		$slug_b  = isset( $post_b['slug'] ) ? (string) $post_b['slug'] : '';

		$direct = ( '' !== $slug_b && in_array( $slug_b, $links_a, true ) )
			|| ( '' !== $slug_a && in_array( $slug_a, $links_b, true ) );

		return array(
			'tag_overlap' => snt_ml_jaccard( $tags_a, $tags_b ),
			'direct_link' => $direct ? 1 : 0,
			'co_link'     => snt_ml_jaccard( $links_a, $links_b ),
		);
	}
}

if ( ! function_exists( 'snt_ml_related_score' ) ) {
	/**
	 * Blended relatedness: lexical cosine + graph signals.
	 *
	 * Weights arrive as a plain argument — the integration layer runs the
	 * filter and passes the result here; this fn stays pure.
	 *
	 * @param float      $cos     Lexical cosine (snt_ml_cosine).
	 * @param array      $signals snt_ml_graph_signals() output.
	 * @param array|null $weights Optional { lexical, tags, direct_link, co_link };
	 *                            missing keys fall back to the defaults below.
	 * @return float Weighted blend (0..1 under the default weights).
	 */
	function snt_ml_related_score( $cos, array $signals, $weights = null ) {
		$defaults = array(
			'lexical'     => 0.55,
			'tags'        => 0.25,
			'direct_link' => 0.15,
			'co_link'     => 0.05,
		);
		$w = is_array( $weights ) ? array_merge( $defaults, $weights ) : $defaults;

		return (float) $w['lexical'] * (float) $cos
			+ (float) $w['tags'] * (float) ( $signals['tag_overlap'] ?? 0.0 )
			+ (float) $w['direct_link'] * (float) ( $signals['direct_link'] ?? 0 )
			+ (float) $w['co_link'] * (float) ( $signals['co_link'] ?? 0.0 );
	}
}
