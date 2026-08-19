<?php
/**
 * Signal & Noise Tools — TF-IDF vs embeddings, measured (item 8, slice 1).
 *
 * THE INSTRUMENT, not the swap. Item 8 rests on one claim: that lexical cosine
 * has a recall ceiling on a corpus that restates one argument in changing
 * vocabulary. Plausible — and still a claim. This computes both rankings over
 * the same corpus and reports where they DISAGREE, because agreement proves
 * nothing either way and the disagreements are the entire evidence.
 *
 * WHAT A RESULT MEANS, stated before any number exists so it cannot be read
 * backwards afterwards:
 *   - large `only_embedding` sets  -> the ceiling is real; the swap is justified
 *   - near-total overlap          -> TF-IDF was already finding these pairs, and
 *                                    adopting a hosted neural model would break
 *                                    four public claims to buy very little
 * Neither outcome is a failure of this slice. Not knowing was.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank every other post against one post, by embedding cosine.
 *
 * @param array $vectors post_id => float[]
 * @param int   $post_id
 * @param int   $limit
 * @return array<int,array{post_id:int,score:float}>
 */
function snt_ml_embed_rank( $vectors, $post_id, $limit = 5 ) {
	$post_id = (int) $post_id;
	if ( ! isset( $vectors[ $post_id ] ) ) {
		return array();
	}
	$self  = $vectors[ $post_id ];
	$rows  = array();
	foreach ( $vectors as $other => $vec ) {
		if ( (int) $other === $post_id ) {
			continue;
		}
		$rows[] = array( 'post_id' => (int) $other, 'score' => round( snt_ml_vec_cosine( $self, $vec ), 4 ) );
	}
	usort( $rows, static function ( $a, $b ) {
		// Tie-break on post_id so a rerun is byte-identical; an unstable sort
		// would make the comparison look noisy for reasons that are not the
		// method under test.
		return ( $b['score'] <=> $a['score'] ) ?: ( $a['post_id'] <=> $b['post_id'] );
	} );
	return array_slice( $rows, 0, max( 1, (int) $limit ) );
}

/**
 * Compare the two rankings for one post.
 *
 * @param array $tfidf_ids Ranked post ids from the existing kernel.
 * @param array $embed_ids Ranked post ids from embeddings.
 * @return array
 */
function snt_ml_embed_diff( $tfidf_ids, $embed_ids ) {
	$t = array_values( array_unique( array_map( 'intval', (array) $tfidf_ids ) ) );
	$e = array_values( array_unique( array_map( 'intval', (array) $embed_ids ) ) );
	$overlap = array_values( array_intersect( $t, $e ) );
	return array(
		'tfidf'          => $t,
		'embedding'      => $e,
		'overlap'        => count( $overlap ),
		'only_tfidf'     => array_values( array_diff( $t, $e ) ),
		// The load-bearing column: pairs embeddings found that lexical cosine
		// did not. If this is empty across the corpus, item 8's premise is false
		// FOR THIS CORPUS, whatever is true of the method in general.
		'only_embedding' => array_values( array_diff( $e, $t ) ),
	);
}

/**
 * Aggregate the per-post diffs into the one number the decision turns on.
 *
 * @param array $diffs From snt_ml_embed_diff(), one per post.
 * @return array
 */
function snt_ml_embed_summary( $diffs ) {
	$posts = 0; $overlap = 0; $only_e = 0; $only_t = 0; $slots = 0;
	foreach ( (array) $diffs as $d ) {
		++$posts;
		$overlap += (int) ( $d['overlap'] ?? 0 );
		$only_e  += count( (array) ( $d['only_embedding'] ?? array() ) );
		$only_t  += count( (array) ( $d['only_tfidf'] ?? array() ) );
		$slots   += count( (array) ( $d['embedding'] ?? array() ) );
	}
	return array(
		'posts'            => $posts,
		'ranked_slots'     => $slots,
		'agreed'           => $overlap,
		'only_embedding'   => $only_e,
		'only_tfidf'       => $only_t,
		// Share of ranked slots where embeddings surfaced something lexical
		// cosine missed. THIS is the number item 8 stands or falls on.
		'divergence'       => $slots > 0 ? round( $only_e / $slots, 4 ) : 0.0,
	);
}
