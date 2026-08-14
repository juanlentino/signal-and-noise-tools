<?php
/**
 * Signal & Noise — reading paths (R4 4B, ML pipeline #10): the reader side.
 *
 * The build side is one additive line in inc/ml-artifacts.php (each stored
 * cluster gains a 'path' — the deterministic chain snt_ml_cluster_path()
 * computes while the vectors are in memory). This file is everything that
 * READS it: the per-post resolver and the pipeline gate. Render lives in
 * inc/ml-paths-render.php; the THEME places the shortcode (the related-notes
 * pattern — plugin owns the renderer, theme owns the placement).
 *
 * THE THREE-WAY CONTRACT (realtime-zero-vs-null, one answer wider):
 *   null → paths are NOT BUILT. Covers both "no artifact at all" and "artifact
 *          built by pre-11.3.0 code" — an old artifact has clusters but no
 *          'path' keys, and an absent ordering is unknown, never "no path".
 *          Self-heals on the next rebuild (publish transition or the daily
 *          backstop).
 *   []   → a REAL "this note is on no path": the corpus built, the note is a
 *          singleton or unclustered. The chain-of-one rule travelling.
 *   row  → {label, position (1-based), total, prev, next} — prev/next are
 *          post ids or null at the chain's ends.
 *
 * Read path never computes: one option read, zero kernel calls, zero HTTP.
 *
 * @package SignalNoiseTools
 * @since 11.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'snt_ml_path_for_post' ) ) {
	/**
	 * Resolve the reading path a post sits on.
	 *
	 * @param int $post_id Post id.
	 * @return array|null Null = not built; array() = on no path; else the row
	 *                    {label:string, position:int, total:int,
	 *                     prev:int|null, next:int|null, members:int[]}.
	 */
	function snt_ml_path_for_post( $post_id ) {
		$post_id  = (int) $post_id;
		$clusters = function_exists( 'snt_ml_topics_get' ) ? snt_ml_topics_get() : null;
		if ( null === $clusters ) {
			return null;
		}
		$seen_path_key = false;
		foreach ( $clusters as $cluster ) {
			if ( ! is_array( $cluster ) || ! array_key_exists( 'path', $cluster ) ) {
				continue; // Pre-11.3.0 row: carries no ordering.
			}
			$seen_path_key = true;
			$path          = array_map( 'intval', (array) $cluster['path'] );
			$pos           = array_search( $post_id, $path, true );
			if ( false === $pos || count( $path ) < 2 ) {
				continue;
			}
			return array(
				'label'    => (string) ( $cluster['label'] ?? '' ),
				'position' => $pos + 1,
				'total'    => count( $path ),
				'prev'     => $pos > 0 ? $path[ $pos - 1 ] : null,
				'next'     => $pos < count( $path ) - 1 ? $path[ $pos + 1 ] : null,
				'members'  => $path,
			);
		}
		if ( ! $seen_path_key && array() !== $clusters ) {
			// Clusters exist but NONE carries an ordering: the artifact predates
			// the path field. Unknown, never "no path" — the next rebuild heals it.
			return null;
		}
		return array();
	}
}

if ( ! function_exists( 'snt_ml_pipeline_reading_path' ) ) {
	/**
	 * 'reading-path' pipeline: the chain one post sits on.
	 *
	 * Thin gate over snt_ml_path_for_post(). Mirrors the 'related' pipeline's
	 * envelope discipline: not-built is a 503 the caller can distinguish from
	 * the REAL "on no path" answer, which is ok:true with path:null.
	 *
	 * @param array $args { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	function snt_ml_pipeline_reading_path( $args ) {
		$args    = (array) $args;
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'snt_ml_invalid_args',
				'reading-path pipeline requires a positive integer post_id.',
				array( 'status' => 400 )
			);
		}
		$row = snt_ml_path_for_post( $post_id );
		if ( null === $row ) {
			return new WP_Error(
				'snt_ml_not_built',
				'ML artifacts are not built yet (or predate the path field); the reading paths are unavailable.',
				array( 'status' => 503 )
			);
		}
		return array(
			'ok'   => true,
			'path' => array() === $row ? null : $row,
		);
	}
}
