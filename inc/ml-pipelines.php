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
			'related' => 'snt_ml_pipeline_related',
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
