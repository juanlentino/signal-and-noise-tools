<?php
/**
 * Signal & Noise — ML artifact layer (corpus build + related-index reader).
 *
 * The stage between the pure kernel (inc/ml-kernel.php) and its consumers:
 * walks the published corpus, scores every post pair with the kernel, and
 * stores each post's top matches in post meta so read paths never compute.
 * Implements `snt_ml_related_for_post()` to the ARTIFACT CONTRACT pinned in
 * inc/ml-pipelines.php: list of {post_id,score} rows / [] = real "nothing
 * related" ANSWER / null ONLY when artifacts were never built — zero and
 * null are different answers (the realtime-zero-vs-null rule).
 *
 * Rebuild triggers: any transition into or out of 'publish' for posts
 * (coalesced through a deduped single event, so a burst of edits builds
 * once) plus a daily recurring backstop. The single-event and recurring
 * hooks are DELIBERATELY separate — sharing one hook would leave
 * wp_next_scheduled() permanently truthy for the single events (the
 * inc/analytics-rollup.php lesson).
 *
 * The relatedness weight blend is filterable HERE (`snt_ml_related_weights`)
 * — the kernel takes weights as a plain argument and stays pure.
 *
 * @package SignalNoiseTools
 * @since 10.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_RELATED_META       = '_snt_ml_related';
const SNT_ML_CORPUS_META_OPT    = 'snt_ml_corpus_meta';
const SNT_ML_REBUILD_HOOK       = 'snt_ml_rebuild';        // Daily recurring backstop.
const SNT_ML_REBUILD_ASYNC_HOOK = 'snt_ml_rebuild_async';  // Coalesced publish-burst single event.
const SNT_ML_TOP_N              = 10;

if ( ! function_exists( 'snt_ml_extract_note_links' ) ) {
	/**
	 * Outbound internal /notes/ links from raw post_content, as slugs.
	 *
	 * Accepts absolute (home_url-prefixed) and site-relative hrefs; query
	 * strings and fragments are shed. External hosts and non-/notes/ internal
	 * paths carry no graph signal and are dropped.
	 *
	 * @param string $content Raw post_content.
	 * @return string[] Deduped slug list (order of first appearance).
	 */
	function snt_ml_extract_note_links( $content ) {
		if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
			return array();
		}
		$home  = untrailingslashit( home_url() );
		$slugs = array();
		foreach ( $m[1] as $href ) {
			if ( '' !== $home && 0 === strpos( $href, $home ) ) {
				$href = substr( $href, strlen( $home ) );
			}
			if ( preg_match( '#^/notes/([^/?\#]+)#', $href, $mm ) ) {
				$slugs[] = $mm[1];
			}
		}
		return array_values( array_unique( $slugs ) );
	}
}

if ( ! function_exists( 'snt_ml_build_corpus' ) ) {
	/**
	 * Full corpus build: tokenize every published post, score all pairs with
	 * the kernel, store each post's top SNT_ML_TOP_N matches in
	 * SNT_ML_RELATED_META, and stamp SNT_ML_CORPUS_META_OPT.
	 *
	 * Never silent: an EMPTY corpus is an ANSWER (ok:true, posts:0) and still
	 * stamps the option — "built over nothing" is a different state from
	 * "never built", and the reader depends on that distinction.
	 *
	 * @return array{ok:bool,posts:int,pairs:int,built_at:int}
	 */
	function snt_ml_build_corpus() {
		$posts    = snt_corpus_fetch_posts( 'publish', 'post' );
		$built_at = time();

		$docs    = array();
		$profile = array();
		$stamp   = array();
		foreach ( $posts as $post ) {
			$id           = (int) $post->ID;
			$content      = (string) ( $post->post_content ?? '' );
			$docs[ $id ]  = snt_ml_tokenize( $content );
			$profile[ $id ] = array(
				'slug'      => (string) ( $post->post_name ?? '' ),
				'tags'      => snt_corpus_term_names( $id, 'post_tag' ),
				'links_out' => snt_ml_extract_note_links( $content ),
			);
			$stamp[ $id ] = $id . ':' . (string) ( $post->post_modified ?? '' );
		}

		$stats   = snt_ml_corpus_stats( $docs );
		$vectors = array();
		foreach ( $docs as $id => $tokens ) {
			$vectors[ $id ] = snt_ml_tfidf_vector( $tokens, $stats );
		}

		/**
		 * Filters the relatedness weight blend (kernel defaults shown).
		 *
		 * @param array $weights { lexical, tags, direct_link, co_link }.
		 */
		$weights = apply_filters( 'snt_ml_related_weights', array(
			'lexical'     => 0.55,
			'tags'        => 0.25,
			'direct_link' => 0.15,
			'co_link'     => 0.05,
		) );

		$ids     = array_keys( $docs );
		$n       = count( $ids );
		$related = array_fill_keys( $ids, array() );
		$pairs   = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$a = $ids[ $i ];
				$b = $ids[ $j ];
				$score = snt_ml_related_score(
					snt_ml_cosine( $vectors[ $a ], $vectors[ $b ] ),
					snt_ml_graph_signals( $profile[ $a ], $profile[ $b ] ),
					$weights
				);
				$score = round( $score, 4 );
				$pairs++;
				if ( $score <= 0 ) {
					continue; // Zero signal is not relatedness: never stored, so [] stays a REAL answer.
				}
				$related[ $a ][] = array( 'post_id' => $b, 'score' => $score );
				$related[ $b ][] = array( 'post_id' => $a, 'score' => $score );
			}
		}

		foreach ( $related as $id => $rows ) {
			usort( $rows, static function ( $x, $y ) {
				if ( $x['score'] === $y['score'] ) {
					return $x['post_id'] <=> $y['post_id']; // Deterministic ties.
				}
				return $y['score'] <=> $x['score'];
			} );
			update_post_meta( $id, SNT_ML_RELATED_META, array_slice( $rows, 0, SNT_ML_TOP_N ) );
		}

		sort( $stamp );
		update_option( SNT_ML_CORPUS_META_OPT, array(
			'fingerprint' => md5( implode( '|', $stamp ) ),
			'built_at'    => $built_at,
			'posts'       => $n,
		), false );

		return array(
			'ok'       => true,
			'posts'    => $n,
			'pairs'    => $pairs,
			'built_at' => $built_at,
		);
	}
}

if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
	/**
	 * Related-index reader — implements the ARTIFACT CONTRACT in
	 * inc/ml-pipelines.php verbatim: score-descending {post_id,score} rows,
	 * never $post_id itself, [] = real "nothing related", null = not built.
	 *
	 * Rows are re-gated on publish status at READ time: a post unpublished
	 * since the last build must vanish from every list immediately, not at
	 * the next rebuild. A post with no meta under a stamped corpus (published
	 * seconds ago, rebuild still queued) reads as [] — absence of a row is
	 * not absence of the index.
	 *
	 * @param int $post_id Post being read.
	 * @param int $limit   Max rows (clamped 1..SNT_ML_TOP_N).
	 * @return array|null List of {post_id:int,score:float}, or null when unbuilt.
	 */
	function snt_ml_related_for_post( $post_id, $limit ) {
		$meta = get_option( SNT_ML_CORPUS_META_OPT, false );
		if ( ! is_array( $meta ) || ! isset( $meta['built_at'] ) ) {
			return null; // Never built — distinct from every empty answer below.
		}
		$post_id = (int) $post_id;
		$limit   = max( 1, min( SNT_ML_TOP_N, (int) $limit ) );

		$rows = get_post_meta( $post_id, SNT_ML_RELATED_META, true );
		if ( ! is_array( $rows ) ) {
			return array(); // Post not indexed (yet): an empty ANSWER, not "not built".
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['post_id'], $row['score'] ) ) {
				continue; // Malformed row: skip, never fabricate.
			}
			$rid = (int) $row['post_id'];
			if ( $rid <= 0 || $rid === $post_id || 'publish' !== get_post_status( $rid ) ) {
				continue;
			}
			$out[] = array(
				'post_id' => $rid,
				'score'   => (float) $row['score'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'snt_ml_schedule_rebuild' ) ) {
	/**
	 * Coalesced rebuild: one deduped single event absorbs a publish burst
	 * (34 posts build in milliseconds, but one build per burst beats five).
	 */
	function snt_ml_schedule_rebuild() {
		if ( ! wp_next_scheduled( SNT_ML_REBUILD_ASYNC_HOOK ) ) {
			wp_schedule_single_event( time() + 30, SNT_ML_REBUILD_ASYNC_HOOK );
		}
	}
}

if ( ! function_exists( 'snt_ml_on_transition' ) ) {
	/**
	 * Corpus-membership changes only: a transition INTO or OUT OF 'publish'
	 * for posts. publish→publish (a plain edit of a live post) also rebuilds
	 * — its body/tags/links feed the scores. draft→draft churn never does.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $post       WP_Post.
	 */
	function snt_ml_on_transition( $new_status, $old_status, $post ) {
		if ( 'post' !== (string) ( $post->post_type ?? '' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return; // Neither side published: the corpus is untouched.
		}
		snt_ml_schedule_rebuild();
	}
}
add_action( 'transition_post_status', 'snt_ml_on_transition', 10, 3 );

if ( ! function_exists( 'snt_ml_schedule_daily' ) ) {
	/**
	 * Daily recurring backstop (idempotent via wp_next_scheduled; hooked on
	 * init so it registers on front-end and WP-CLI requests too — the
	 * inc/analytics-rollup.php idiom).
	 */
	function snt_ml_schedule_daily() {
		if ( ! wp_next_scheduled( SNT_ML_REBUILD_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SNT_ML_REBUILD_HOOK );
		}
	}
}
add_action( 'init', 'snt_ml_schedule_daily' );
add_action( SNT_ML_REBUILD_HOOK, 'snt_ml_build_corpus' );
add_action( SNT_ML_REBUILD_ASYNC_HOOK, 'snt_ml_build_corpus' );
