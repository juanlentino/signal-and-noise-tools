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
	$posts = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => 'publish',
		'numberposts'      => 200,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );
	if ( ! $posts ) {
		return new WP_Error( 'snt_ml_no_posts', __( 'No published posts to compare.', 'signal-and-noise-tools' ) );
	}

	// Embed everything first, so a mid-walk API failure cannot produce a
	// PARTIAL comparison that still returns a confident-looking number.
	$vectors = array();
	foreach ( $posts as $pid ) {
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

	$diffs = array();
	$rows  = array();
	foreach ( array_keys( $vectors ) as $pid ) {
		$tfidf_rows = snt_ml_related_for_post( (int) $pid, $depth );
		if ( null === $tfidf_rows ) {
			return new WP_Error( 'snt_ml_not_built', __( 'The ML artifacts have never been built, so there is no baseline to compare against.', 'signal-and-noise-tools' ) );
		}
		$tfidf_ids = array_map( static function ( $r ) {
			return (int) ( $r['post_id'] ?? 0 );
		}, (array) $tfidf_rows );
		$embed_ids = array_map( static function ( $r ) {
			return (int) $r['post_id'];
		}, snt_ml_embed_rank( $vectors, (int) $pid, $depth ) );

		$d = snt_ml_embed_diff( $tfidf_ids, $embed_ids );
		$diffs[] = $d;
		if ( $d['only_embedding'] ) {
			$rows[] = array(
				'post_id'        => (int) $pid,
				'title'          => (string) get_the_title( (int) $pid ),
				'only_embedding' => array_map( static function ( $id ) {
					return array( 'post_id' => (int) $id, 'title' => (string) get_the_title( (int) $id ) );
				}, $d['only_embedding'] ),
			);
		}
	}

	$summary = snt_ml_embed_summary( $diffs );
	$summary['depth']    = (int) $depth;
	$summary['embedded'] = count( $vectors );
	$summary['model']    = SNT_ML_EMBED_MODEL;
	return array( 'summary' => $summary, 'divergent' => $rows );
}
