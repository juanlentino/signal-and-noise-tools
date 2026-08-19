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
 * The variant the readout recommends and the pair list describes.
 *
 * ONE constant because these are the same fact in three places: the badge on
 * the table, which ranking the divergent list is built from, and what a future
 * swap would adopt. Hardcoding the string separately is how a screen ends up
 * showing numbers for one ranking and pairs from another.
 *
 * WHY 'centered' AND NOT 'centered_mutual', measured 2026-08-19 on 33 published
 * notes: mutual filtering took hub share to 15.2% but DROPPED the clearest
 * evidence for the whole arc — "The pen is not the notary" and "The gate is not
 * the signature" make the identical argument in almost no shared vocabulary,
 * and mutual k-NN discards it because the relation is asymmetric (one note
 * considers the other close; the reverse has five closer). Asymmetry is normal
 * in a corpus of restatements, not an error to filter out.
 *
 * Centering alone: hub share 69.7% -> 30.3% (a 2.3x improvement) while keeping
 * 56.4% divergence against 53.0%. It removes the shared-subject mass that
 * CAUSES hubness rather than deleting the pairs hubness produces.
 */
const SNT_ML_EMBED_RECOMMENDED = 'centered';

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

/**
 * Walk the corpus and produce the number item 8 turns on.
 *
 * THE ORCHESTRATOR that v11.22.0 forgot: the pure pieces above shipped with
 * nothing calling them, so the instrument existed and could not be read.
 *
 * Compares against `snt_ml_related_for_post()` — the REAL artifact the site
 * serves — not a freshly recomputed TF-IDF. A reimplementation would measure my
 * arithmetic against my arithmetic; the question is whether the SHIPPED ranking
 * misses pairs, so the shipped ranking is the baseline.
 *
 * @param int $depth How many ranked slots to compare per note.
 * @return array|WP_Error
 */
function snt_ml_embedding_compare_corpus( $depth = 5 ) {
	if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
		return new WP_Error( 'snt_ml_no_kernel', __( 'The ML artifact reader is unavailable.', 'signal-and-noise-tools' ) );
	}
	if ( ! snt_ml_embed_configured() ) {
		return new WP_Error( 'snt_ml_embed_unconfigured', __( 'Set the Workers AI token on the AI tab first.', 'signal-and-noise-tools' ) );
	}
	// THREE STATUS SETS, because the three roles are not the same question and
	// collapsing them into one filter is what hid 40% of the corpus in the first
	// run (33 of 55 notes measured; the 22 scheduled ones never seen).
	//
	//  CENTROID  publish + future — centering subtracts the corpus's shared mass,
	//            and 22 unseen notes move exactly that.
	//  SOURCES   publish only — a scheduled note has no _snt_ml_related artifact
	//            yet, so scoring it would diff against an EMPTY baseline and
	//            report 100% divergence: an artifact of indexing, not a miss.
	//  TARGETS   publish only — Related Notes cannot link a reader to a note that
	//            has not been published (snt_ml_related_for_post enforces this).
	$embed_ids = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => array( 'publish', 'future' ),
		'numberposts'      => 400,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );
	$posts = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => 'publish',
		'numberposts'      => 400,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );
	if ( ! $posts ) {
		return new WP_Error( 'snt_ml_no_posts', __( 'No published posts to compare.', 'signal-and-noise-tools' ) );
	}

	// Embed everything first, so a mid-walk API failure cannot produce a
	// PARTIAL comparison that still returns a confident-looking number.
	$vectors = array();
	foreach ( $embed_ids as $pid ) {
		// The CANONICAL hash (inc/corpus-inspect.php), not a local md5. Two
		// different definitions of "has this content changed" would drift, and
		// the cache would go stale silently — the vector staying attached to
		// text it no longer describes.
		$hash = snt_corpus_content_hash( (string) get_post_field( 'post_content', (int) $pid ) );
		$vec = snt_ml_embedding_for_post( (int) $pid, $hash );
		if ( is_wp_error( $vec ) ) {
			return $vec;
		}
		if ( is_array( $vec ) && $vec ) {
			$vectors[ (int) $pid ] = $vec;
		}
	}
	if ( count( $vectors ) < 2 ) {
		return new WP_Error( 'snt_ml_too_few_vectors', __( 'Fewer than two posts embedded; nothing to compare.', 'signal-and-noise-tools' ) );
	}

	// The TF-IDF baseline, read once — the artifact the site actually serves.
	$tfidf = array();
	foreach ( $published_sources = array_map( 'intval', $posts ) as $pid ) {
		$rows_t = snt_ml_related_for_post( (int) $pid, $depth );
		if ( null === $rows_t ) {
			return new WP_Error( 'snt_ml_not_built', __( 'The ML artifacts have never been built, so there is no baseline to compare against.', 'signal-and-noise-tools' ) );
		}
		$tfidf[ (int) $pid ] = array_map( static function ( $r ) {
			return (int) ( $r['post_id'] ?? 0 );
		}, (array) $rows_t );
	}

	// THREE variants, measured side by side rather than one adopted on faith.
	// The first run reported 59.4% divergence and could not see that one note
	// occupied half the results; hub stats are now part of every variant.
	// Centre over the WHOLE corpus (published + scheduled), then rank within the
	// publishable subset. The centroid is the thing the scheduled notes belong
	// in; the results are the thing they must stay out of.
	$centered = snt_ml_vec_center_all( $vectors );
	$published = array_map( 'intval', $posts );
	$vec_pub   = array_intersect_key( $vectors, array_flip( $published ) );
	$ctr_pub   = array_intersect_key( $centered, array_flip( $published ) );
	$rank_raw = array();
	$rank_ctr = array();
	foreach ( array_keys( $vec_pub ) as $pid ) {
		$rank_raw[ (int) $pid ] = array_map( static function ( $r ) { return (int) $r['post_id']; }, snt_ml_embed_rank( $vec_pub, (int) $pid, $depth ) );
		$rank_ctr[ (int) $pid ] = array_map( static function ( $r ) { return (int) $r['post_id']; }, snt_ml_embed_rank( $ctr_pub, (int) $pid, $depth ) );
	}
	$rank_mut = snt_ml_embed_mutual( $rank_ctr );

	$variants = array(
		'raw'              => $rank_raw,
		'centered'         => $rank_ctr,
		'centered_mutual'  => $rank_mut,
	);
	$out = array();
	$divergent = array();
	foreach ( $variants as $name => $ranked ) {
		$diffs = array();
		foreach ( $ranked as $pid => $ids ) {
			$d = snt_ml_embed_diff( $tfidf[ $pid ] ?? array(), $ids );
			$diffs[] = $d;
			if ( SNT_ML_EMBED_RECOMMENDED === $name && $d['only_embedding'] ) {
				$divergent[] = array(
					'post_id'        => (int) $pid,
					'title'          => (string) get_the_title( (int) $pid ),
					'only_embedding' => array_map( static function ( $id ) {
						return array( 'post_id' => (int) $id, 'title' => (string) get_the_title( (int) $id ) );
					}, $d['only_embedding'] ),
				);
			}
		}
		$sum          = snt_ml_embed_summary( $diffs );
		$sum['hub']   = snt_ml_embed_hub_stats( $ranked );
		$sum['depth'] = (int) $depth;
		$sum['model'] = SNT_ML_EMBED_MODEL;
		$out[ $name ] = $sum;
	}
	$out['raw']['embedded'] = count( $vectors );
	$scope = array(
		'embedded_total'   => count( $vectors ),          // publish + future
		'scored_sources'   => count( $vec_pub ),          // publish only
		'scheduled_in_centroid' => count( $vectors ) - count( $vec_pub ),
	);
	// `divergent` describes the RECOMMENDED variant, so the table on screen and
	// the numbers beside it can never be describing different rankings.
	return array( 'variants' => $out, 'divergent' => $divergent, 'recommended' => SNT_ML_EMBED_RECOMMENDED, 'scope' => $scope );
}

/**
 * How concentrated a set of rankings is on a few targets.
 *
 * The metric the first run LACKED. Divergence said 59.4% and said nothing about
 * one note occupying half the results — quality and disagreement are different
 * questions, and only one of them had a number.
 *
 * @param array $ranked post_id => int[] target ids
 * @return array
 */
function snt_ml_embed_hub_stats( $ranked ) {
	$freq = array();
	$slots = 0;
	foreach ( (array) $ranked as $targets ) {
		foreach ( (array) $targets as $t ) {
			$t = (int) $t;
			$freq[ $t ] = ( $freq[ $t ] ?? 0 ) + 1;
			++$slots;
		}
	}
	if ( ! $freq ) {
		return array( 'top_target' => 0, 'top_count' => 0, 'hub_share' => 0.0, 'distinct_targets' => 0, 'sources' => 0 );
	}
	arsort( $freq );
	$top_id    = (int) array_key_first( $freq );
	$top_count = (int) $freq[ $top_id ];
	$sources   = count( (array) $ranked );
	return array(
		'top_target'       => $top_id,
		'top_count'        => $top_count,
		// Share of SOURCE notes whose results include the single most frequent
		// target. 17/33 = 0.515 is what the raw run produced.
		'hub_share'        => $sources > 0 ? round( $top_count / $sources, 4 ) : 0.0,
		'distinct_targets' => count( $freq ),
		'sources'          => $sources,
		'slots'            => $slots,
	);
}

/**
 * Keep only reciprocated pairs.
 *
 * A hub survives raw ranking because everything points AT it; it rarely points
 * back at everything in return. Requiring the relationship to be mutual removes
 * the asymmetry that hubness is made of, without any threshold to tune.
 *
 * @param array $ranked post_id => int[] target ids
 * @return array post_id => int[]
 */
function snt_ml_embed_mutual( $ranked ) {
	$ranked = (array) $ranked;
	$out    = array();
	foreach ( $ranked as $src => $targets ) {
		$keep = array();
		foreach ( (array) $targets as $t ) {
			$back = (array) ( $ranked[ (int) $t ] ?? array() );
			if ( in_array( (int) $src, array_map( 'intval', $back ), true ) ) {
				$keep[] = (int) $t;
			}
		}
		$out[ (int) $src ] = $keep;
	}
	return $out;
}
