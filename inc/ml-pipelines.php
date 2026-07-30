<?php
/**
 * Signal & Noise — ML pipeline registry (thin WP glue over inc/ml-kernel.php).
 *
 * The composition seam the site-wide ML surface routes through: a filterable
 * slug => callable map plus one dispatcher. All arithmetic lives in the pure
 * kernel; this file is allowed apply_filters / WP_Error and nothing heavier.
 * No REST routes here — callers (abilities, admin, future stages) invoke
 * snt_ml_run() directly.
 *
 * @package SignalNoiseTools
 * @since 10.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_ml_pipelines' ) ) {
	/**
	 * Registered pipelines: slug => callable( array $args ): array|WP_Error.
	 *
	 * @return array<string,callable> Filterable via 'snt_ml_pipelines'.
	 */
	function snt_ml_pipelines() {
		$pipelines = array(
			'related'          => 'snt_ml_pipeline_related',
			'near-duplicates'  => 'snt_ml_pipeline_near_duplicates', // v10.16.0: cousin pairs (inc/ml-cousins.php).
			'extract-keywords' => 'snt_ml_pipeline_extract_keywords', // v10.17.0: TF-IDF keyword candidates (inc/ml-candidates.php).
			'link-candidates'  => 'snt_ml_pipeline_link_candidates',  // v10.17.0: unlinked related-note candidates (inc/ml-candidates.php).
			'topic-clusters'   => 'snt_ml_pipeline_topic_clusters',   // v10.21.0: the stored topic partition (inc/ml-artifacts.php).
		);
		return apply_filters( 'snt_ml_pipelines', $pipelines );
	}
}

if ( ! function_exists( 'snt_ml_run' ) ) {
	/**
	 * Resolve a pipeline slug through the registry and run it.
	 *
	 * @param string $pipeline Registry slug.
	 * @param array  $args     Pipeline arguments (shape is per-pipeline).
	 * @return array|WP_Error Pipeline result, or snt_ml_unknown_pipeline (404).
	 */
	function snt_ml_run( $pipeline, $args = array() ) {
		$pipelines = snt_ml_pipelines();
		$pipeline  = (string) $pipeline;
		if ( ! isset( $pipelines[ $pipeline ] ) || ! is_callable( $pipelines[ $pipeline ] ) ) {
			return new WP_Error(
				'snt_ml_unknown_pipeline',
				sprintf( 'Unknown ML pipeline "%s".', $pipeline ),
				array( 'status' => 404 )
			);
		}
		return call_user_func( $pipelines[ $pipeline ], (array) $args );
	}
}

if ( ! function_exists( 'snt_ml_pipeline_related' ) ) {
	/**
	 * 'related' pipeline: ranked related posts from prebuilt artifacts.
	 *
	 * ── ARTIFACT CONTRACT (the next stage implements to THIS) ────────────────
	 *
	 * This pipeline delegates to `snt_ml_related_for_post( int $post_id,
	 * int $limit )`, which the artifact layer must define as:
	 *   - returns a LIST (ordered, score-descending) of
	 *     array( 'post_id' => int, 'score' => float ) rows, at most $limit,
	 *     never containing $post_id itself;
	 *   - returns array() for a real "nothing related" answer (a valid result,
	 *     NOT an error — empty is an ANSWER);
	 *   - returns null ONLY when the artifacts have not been built yet
	 *     (index absent/stale-empty), which this wrapper maps to WP_Error
	 *     'snt_ml_not_built' — never conflate "no matches" with "not built";
	 *   - may itself return a WP_Error, passed through verbatim.
	 * While the fn does not exist at all (this stage), absence ≡ not built.
	 *
	 * @param array $args { @type int $post_id Required. @type int $limit Default 4, clamped 1..10. }
	 * @return array|WP_Error { ok: true, related: list<{post_id,score}> } or
	 *                        snt_ml_invalid_args (400) / snt_ml_not_built (503).
	 */
	function snt_ml_pipeline_related( $args ) {
		$args    = (array) $args;
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'snt_ml_invalid_args',
				'related pipeline requires a positive integer post_id.',
				array( 'status' => 400 )
			);
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 4;
		$limit = max( 1, min( 10, $limit ) );

		if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
			return new WP_Error(
				'snt_ml_not_built',
				'ML artifacts are not built yet; the related index is unavailable.',
				array( 'status' => 503 )
			);
		}
		$rows = snt_ml_related_for_post( $post_id, $limit );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( null === $rows ) {
			return new WP_Error(
				'snt_ml_not_built',
				'ML artifacts are not built yet; the related index is unavailable.',
				array( 'status' => 503 )
			);
		}
		$related = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['post_id'], $row['score'] ) ) {
				continue; // Malformed artifact row: skip, never fabricate.
			}
			$related[] = array(
				'post_id' => (int) $row['post_id'],
				'score'   => (float) $row['score'],
			);
			if ( count( $related ) >= $limit ) {
				break;
			}
		}
		return array(
			'ok'      => true,
			'related' => $related,
		);
	}
}

if ( ! function_exists( 'snt_ml_pipeline_near_duplicates' ) ) {
	/**
	 * 'near-duplicates' pipeline: cousin pairs across the full 'post' corpus.
	 *
	 * Thin argument gate over snt_ml_cousin_pairs() (inc/ml-cousins.php),
	 * which owns the corpus walk, the byte-exact/empty-body exclusions, and
	 * the 0.3..0.95 threshold clamp. A non-numeric threshold falls back to
	 * the default rather than casting (a (float) cast of garbage would
	 * silently mean 0.0 → clamped to 0.3 — a surprise widening, not a scan).
	 *
	 * @param array $args { @type float $threshold Default 0.6, clamped 0.3..0.95 by the impl. }
	 * @return array|WP_Error Envelope from snt_ml_cousin_pairs(), or
	 *                        snt_ml_unavailable (500) when the module is not loaded.
	 */
	function snt_ml_pipeline_near_duplicates( $args ) {
		$args = (array) $args;
		if ( ! function_exists( 'snt_ml_cousin_pairs' ) ) {
			return new WP_Error(
				'snt_ml_unavailable',
				'Cousin-detection module (inc/ml-cousins.php) is not loaded.',
				array( 'status' => 500 )
			);
		}
		$threshold = isset( $args['threshold'] ) && is_numeric( $args['threshold'] )
			? (float) $args['threshold']
			: SNT_ML_COUSIN_THRESHOLD_DEFAULT;
		return snt_ml_cousin_pairs( $threshold );
	}
}

if ( ! function_exists( 'snt_ml_pipeline_extract_keywords' ) ) {
	/**
	 * 'extract-keywords' pipeline (v10.17.0): argument gate over
	 * snt_ml_keyword_candidates() (inc/ml-candidates.php), which owns the
	 * corpus walk, the bigram rule, and the 1..20 limit clamp. Mirrors the
	 * related pipeline's post_id contract: missing/non-positive → 400.
	 *
	 * @param array $args { @type int $post_id Required. @type int $limit Default 8, clamped 1..20 by the impl. }
	 * @return array|WP_Error Envelope from snt_ml_keyword_candidates(), or
	 *                        snt_ml_invalid_args (400) / snt_ml_unavailable (500).
	 */
	function snt_ml_pipeline_extract_keywords( $args ) {
		$args = (array) $args;
		if ( ! function_exists( 'snt_ml_keyword_candidates' ) ) {
			return new WP_Error(
				'snt_ml_unavailable',
				'Candidate-generation module (inc/ml-candidates.php) is not loaded.',
				array( 'status' => 500 )
			);
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'snt_ml_invalid_args',
				'extract-keywords pipeline requires a positive integer post_id.',
				array( 'status' => 400 )
			);
		}
		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] )
			? (int) $args['limit']
			: SNT_ML_KEYWORD_LIMIT_DEFAULT;
		return snt_ml_keyword_candidates( $post_id, $limit );
	}
}

if ( ! function_exists( 'snt_ml_pipeline_link_candidates' ) ) {
	/**
	 * 'link-candidates' pipeline (v10.17.0): argument gate over
	 * snt_ml_link_candidates() (inc/ml-candidates.php), which owns the
	 * artifact read (503 when unbuilt — the related pipeline's contract),
	 * the already-linked/unpublished exclusions, and the 1..10 limit clamp.
	 *
	 * @param array $args { @type int $post_id Required. @type int $limit Default 5, clamped 1..10 by the impl. }
	 * @return array|WP_Error Envelope from snt_ml_link_candidates(), or
	 *                        snt_ml_invalid_args (400) / snt_ml_unavailable (500).
	 */
	function snt_ml_pipeline_link_candidates( $args ) {
		$args = (array) $args;
		if ( ! function_exists( 'snt_ml_link_candidates' ) ) {
			return new WP_Error(
				'snt_ml_unavailable',
				'Candidate-generation module (inc/ml-candidates.php) is not loaded.',
				array( 'status' => 500 )
			);
		}
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'snt_ml_invalid_args',
				'link-candidates pipeline requires a positive integer post_id.',
				array( 'status' => 400 )
			);
		}
		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] )
			? (int) $args['limit']
			: SNT_ML_LINK_LIMIT_DEFAULT;
		return snt_ml_link_candidates( $post_id, $limit );
	}
}

if ( ! function_exists( 'snt_ml_pipeline_topic_clusters' ) ) {
	/**
	 * 'topic-clusters' pipeline (v10.21.0): the STORED topic partition — reads
	 * the artifact, never recomputes (the build owns the walk; this surface is
	 * a read). 503 while unbuilt, per the related pipeline's exact contract.
	 *
	 * @param array $args Unused; present for the dispatcher signature.
	 * @return array|WP_Error { ok, clusters, cluster_count, built_at } or
	 *                        snt_ml_not_built (503) / snt_ml_unavailable (500).
	 */
	function snt_ml_pipeline_topic_clusters( $args ) {
		if ( ! function_exists( 'snt_ml_topics_get' ) ) {
			return new WP_Error(
				'snt_ml_unavailable',
				'ML artifacts module (inc/ml-artifacts.php) is not loaded.',
				array( 'status' => 500 )
			);
		}
		$clusters = snt_ml_topics_get();
		if ( null === $clusters ) {
			return new WP_Error(
				'snt_ml_not_built',
				'ML artifacts are not built yet; the topic index is unavailable.',
				array( 'status' => 503 )
			);
		}
		$stored = get_option( SNT_ML_TOPICS_OPT );
		return array(
			'ok'            => true,
			'clusters'      => $clusters,
			'cluster_count' => count( $clusters ),
			'built_at'      => is_array( $stored ) ? (int) ( $stored['built_at'] ?? 0 ) : 0,
		);
	}
}
