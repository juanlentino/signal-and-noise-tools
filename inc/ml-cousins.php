<?php
/**
 * Signal & Noise — near-duplicate COUSIN detection (ML pipeline #2).
 *
 * The exact scan (inc/corpus-inspect.php, snt_corpus_duplicate_scan) catches
 * byte-identical bodies only — a duplicated-then-lightly-edited post escapes
 * it. Cousins = high LEXICAL similarity without hash equality: every body is
 * tokenized with the kernel (inc/ml-kernel.php), vectored as L2-normalized
 * TF-IDF against the corpus' own stats, and all pairs at/above the cosine
 * threshold are returned — EXCLUDING byte-exact pairs (same
 * snt_corpus_content_hash — those are the exact scan's finding, not a
 * cousin) and excluding empty bodies (two blank drafts are not cousins).
 *
 * The walk spans ALL five non-trash statuses via snt_corpus_fetch_posts(),
 * for the same reason the exact scan does: pre-publish collision checking —
 * a scheduled cousin of a published post must be findable BEFORE it goes
 * live. Computed on demand, NO caching: 34 posts is milliseconds, and the
 * workflow is fix-then-rescan-to-confirm — a stale cached "cousins found"
 * after the fix would be a false alarm (the exact scan is uncached for the
 * identical reason).
 *
 * Integration layer: WP calls allowed (the corpus walk); all arithmetic
 * stays in the pure kernel. Registered in the pipeline registry as
 * 'near-duplicates' (inc/ml-pipelines.php).
 *
 * @package SignalNoiseTools
 * @since 10.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Threshold bounds: below 0.3 cosine is topical noise, above 0.95 the pair
// is close enough that the exact scan's hash question is the real one.
const SNT_ML_COUSIN_THRESHOLD_MIN     = 0.3;
const SNT_ML_COUSIN_THRESHOLD_MAX     = 0.95;
const SNT_ML_COUSIN_THRESHOLD_DEFAULT = 0.6;

/**
 * Near-duplicate cousin pairs across the full 'post' corpus.
 *
 * Pair shape: { a: {post_id,title,slug,status}, b: {same}, cosine: float 4dp },
 * a/b ordered post_id-ascending within the pair, list sorted cosine-descending
 * (post-id tiebreak for determinism). No post_type parameter this release —
 * the corpus is 'post' by construction, like the ML artifact build.
 *
 * @param float $threshold Minimum cosine, clamped 0.3..0.95 (default 0.6).
 * @return array{ok:bool,pairs:array,pair_count:int,threshold:float,posts_scanned:int,truncated:bool,scanned_at:int}
 */
function snt_ml_cousin_pairs( $threshold = SNT_ML_COUSIN_THRESHOLD_DEFAULT ) {
	$threshold = max( SNT_ML_COUSIN_THRESHOLD_MIN, min( SNT_ML_COUSIN_THRESHOLD_MAX, (float) $threshold ) );

	$posts = snt_corpus_fetch_posts( 'any', 'post' );

	$rows   = array(); // post_id => pair-member row.
	$hashes = array(); // post_id => exact-content hash.
	$docs   = array(); // post_id => kernel tokens.
	foreach ( $posts as $post ) {
		$content = (string) ( $post->post_content ?? '' );
		$hash    = snt_corpus_content_hash( $content );
		if ( '' === $hash ) {
			continue; // Empty/whitespace-only bodies never cousin.
		}
		$tokens = snt_ml_tokenize( $content );
		if ( array() === $tokens ) {
			continue; // Markup-only body: zero lexical signal, cosine undefined.
		}
		$id            = (int) $post->ID;
		$rows[ $id ]   = array(
			'post_id' => $id,
			'title'   => (string) ( $post->post_title ?? '' ),
			'slug'    => (string) ( $post->post_name ?? '' ),
			'status'  => (string) ( $post->post_status ?? '' ),
		);
		$hashes[ $id ] = $hash;
		$docs[ $id ]   = $tokens;
	}

	$stats   = snt_ml_corpus_stats( $docs );
	$vectors = array();
	foreach ( $docs as $id => $tokens ) {
		$vectors[ $id ] = snt_ml_tfidf_vector( $tokens, $stats );
	}

	$ids   = array_keys( $vectors );
	$n     = count( $ids );
	$pairs = array();
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$a = $ids[ $i ];
			$b = $ids[ $j ];
			if ( $hashes[ $a ] === $hashes[ $b ] ) {
				continue; // Byte-exact: the exact scan's finding, not a cousin.
			}
			$cos = round( snt_ml_cosine( $vectors[ $a ], $vectors[ $b ] ), 4 );
			if ( $cos < $threshold ) {
				continue;
			}
			// a = lower post_id: deterministic pair orientation regardless of
			// the corpus walk's date ordering.
			if ( $a > $b ) {
				list( $a, $b ) = array( $b, $a );
			}
			$pairs[] = array(
				'a'      => $rows[ $a ],
				'b'      => $rows[ $b ],
				'cosine' => $cos,
			);
		}
	}

	usort( $pairs, static function ( $x, $y ) {
		$by_cos = $y['cosine'] <=> $x['cosine']; // Descending.
		if ( 0 !== $by_cos ) {
			return $by_cos;
		}
		$by_a = $x['a']['post_id'] <=> $y['a']['post_id'];
		return 0 !== $by_a ? $by_a : $x['b']['post_id'] <=> $y['b']['post_id'];
	} );

	return array(
		'ok'            => true,
		'pairs'         => $pairs,
		'pair_count'    => count( $pairs ),
		'threshold'     => $threshold,
		'posts_scanned' => count( $posts ),
		// SNT_CORPUS_MAX_LIST is a memory guard, never a silent cap — report it.
		'truncated'     => count( $posts ) >= SNT_CORPUS_MAX_LIST,
		'scanned_at'    => time(),
	);
}
