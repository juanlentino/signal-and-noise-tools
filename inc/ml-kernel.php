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

if ( ! function_exists( 'snt_ml_topic_clusters' ) ) {
	/**
	 * Deterministic topic clustering: connected components over the cosine
	 * graph (v10.21.0, pipeline #4). Two documents connect when their cosine
	 * meets the threshold (inclusive); a topic is a component, not a clique —
	 * A~B and B~C chain into one topic even when A and C sit apart. No k, no
	 * seeds, no randomness: the same corpus always yields the same partition.
	 *
	 * Singletons are excluded — a topic needs at least two notes.
	 *
	 * @param array<int,array<string,float>> $vectors   Sparse L2 vectors keyed by document id.
	 * @param float                          $threshold Cosine floor, inclusive.
	 * @return array<int,array<int,int>> Clusters: members ascending; list
	 *                                   ordered size-descending, then first
	 *                                   member ascending. Empty input => [].
	 */
	function snt_ml_topic_clusters( $vectors, $threshold = 0.35 ) {
		$ids = array_keys( (array) $vectors );
		sort( $ids ); // Canonical walk order — determinism does not depend on input order.
		$n = count( $ids );
		if ( $n < 2 ) {
			return array();
		}

		$parent = array();
		foreach ( $ids as $id ) {
			$parent[ $id ] = $id;
		}
		$find = static function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ]; // Path halving.
				$x            = $parent[ $x ];
			}
			return $x;
		};

		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( snt_ml_cosine( $vectors[ $ids[ $i ] ], $vectors[ $ids[ $j ] ] ) >= $threshold ) {
					$ra = $find( $ids[ $i ] );
					$rb = $find( $ids[ $j ] );
					if ( $ra !== $rb ) {
						$parent[ max( $ra, $rb ) ] = min( $ra, $rb );
					}
				}
			}
		}

		$components = array();
		foreach ( $ids as $id ) {
			$components[ $find( $id ) ][] = $id; // $ids sorted => members arrive ascending.
		}

		$clusters = array();
		foreach ( $components as $members ) {
			if ( count( $members ) >= 2 ) {
				$clusters[] = $members;
			}
		}
		usort( $clusters, static function ( $a, $b ) {
			$by_size = count( $b ) <=> count( $a );
			return 0 !== $by_size ? $by_size : ( $a[0] <=> $b[0] );
		} );
		return $clusters;
	}
}

if ( ! function_exists( 'snt_ml_cluster_label' ) ) {
	/**
	 * Deterministic cluster label: the top terms by summed member weight,
	 * middot-joined. Equal weights tie-break alphabetically, so a label can
	 * never flap between rebuilds of an unchanged corpus.
	 *
	 * @param array<int,array<string,float>> $vectors Sparse vectors keyed by document id.
	 * @param array<int,int>                 $members Cluster member ids.
	 * @param int                            $terms   Label term count.
	 * @return string '' when no member carries any term.
	 */
	function snt_ml_cluster_label( $vectors, $members, $terms = 2 ) {
		$sum = array();
		foreach ( (array) $members as $id ) {
			foreach ( (array) ( $vectors[ $id ] ?? array() ) as $term => $weight ) {
				$sum[ $term ] = ( $sum[ $term ] ?? 0.0 ) + (float) $weight;
			}
		}
		if ( array() === $sum ) {
			return '';
		}
		uksort( $sum, static function ( $a, $b ) use ( $sum ) {
			if ( $sum[ $a ] === $sum[ $b ] ) {
				return strcmp( $a, $b );
			}
			return $sum[ $b ] <=> $sum[ $a ];
		} );
		return implode( ' · ', array_slice( array_keys( $sum ), 0, max( 1, (int) $terms ) ) );
	}
}

if ( ! function_exists( 'snt_ml_median' ) ) {
	/**
	 * Median of a numeric list (even counts average the two middle values).
	 *
	 * @since 10.32.0
	 * @param array<int,int|float> $values Numbers; non-numerics are dropped.
	 * @return float|null Null when nothing numeric was supplied.
	 */
	function snt_ml_median( $values ) {
		$nums = array();
		foreach ( (array) $values as $v ) {
			if ( is_numeric( $v ) ) {
				$nums[] = (float) $v;
			}
		}
		if ( array() === $nums ) {
			return null;
		}
		sort( $nums );
		$n   = count( $nums );
		$mid = intdiv( $n, 2 );
		return 0 === $n % 2 ? ( $nums[ $mid - 1 ] + $nums[ $mid ] ) / 2.0 : $nums[ $mid ];
	}
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
