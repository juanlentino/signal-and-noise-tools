<?php
/**
 * Signal & Noise — deterministic candidate generators (ML pipeline #3).
 *
 * Two proposal surfaces for the human-in-the-loop editing workflow — the
 * kernel PROPOSES, the AI drafts elsewhere, a person decides:
 *   - snt_ml_keyword_candidates() — corpus-aware TF-IDF ranking of a post's
 *     own terms (unigrams + adjacency bigrams) as focus-keyword/tag material;
 *   - snt_ml_link_candidates()    — related posts (from the prebuilt
 *     _snt_ml_related artifacts) the body does NOT already link to, as
 *     internal-link material.
 *
 * Both are CANDIDATE surfaces: read-only, deterministic, nothing here writes.
 * Corpus statistics span ALL five non-trash statuses (the cousin-scan walk) —
 * a term's rarity is judged against everything that could go live, not just
 * what already is. The zero-vs-null discipline holds throughout: an empty
 * BODY yields ok+[] (an empty body is an ANSWER), an unknown post is
 * snt_ml_no_post (404), and unbuilt artifacts are snt_ml_not_built (503) —
 * the same contract the related pipeline pins.
 *
 * Integration layer: WP calls allowed (post resolution, the corpus walk,
 * artifact meta); all arithmetic stays in the pure kernel. Registered in the
 * pipeline registry as 'extract-keywords' and 'link-candidates'
 * (inc/ml-pipelines.php).
 *
 * @package SignalNoiseTools
 * @since 10.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ML_KEYWORD_LIMIT_DEFAULT = 8;
const SNT_ML_KEYWORD_LIMIT_MAX     = 20;
const SNT_ML_KEYWORD_BIGRAM_BOOST  = 1.25;
const SNT_ML_LINK_LIMIT_DEFAULT    = 5;
const SNT_ML_LINK_LIMIT_MAX        = 10; // = SNT_ML_TOP_N: never promise more than the artifact stores.

if ( ! function_exists( 'snt_ml_candidate_post' ) ) {
	/**
	 * Resolve a candidate-generation target: a 'post' in one of the five
	 * corpus statuses. Anything else — unknown ID, trash, internal/other
	 * types — is snt_ml_no_post: outside the corpus there is no post to
	 * propose FOR.
	 *
	 * @param int $post_id Target post ID.
	 * @return object|WP_Error WP_Post on success, snt_ml_no_post (404) otherwise.
	 */
	function snt_ml_candidate_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post
			|| 'post' !== (string) ( $post->post_type ?? '' )
			|| ! in_array( (string) ( $post->post_status ?? '' ), SNT_CORPUS_STATUSES, true ) ) {
			return new WP_Error(
				'snt_ml_no_post',
				'No corpus post with that ID (post type "post", non-trash statuses only).',
				array( 'status' => 404 )
			);
		}
		return $post;
	}
}

if ( ! function_exists( 'snt_ml_keyword_candidates' ) ) {
	/**
	 * Corpus-aware TF-IDF keyword candidates for one post.
	 *
	 * Unigrams: every surviving term of the post's own body, weighted by its
	 * L2-normalized TF-IDF against corpus stats built over the SAME walk the
	 * cousin scan uses (all five non-trash statuses, 'post' type) — so a term
	 * common across the corpus (low idf) ranks below an equally frequent term
	 * unique to this post.
	 *
	 * Bigrams: adjacent pairs in the RAW word stream (pre-filter) where BOTH
	 * members survive tokenization, scored as the pair's summed tf-idf ×
	 * SNT_ML_KEYWORD_BIGRAM_BOOST, competing in the same ranked list. A
	 * stopword between two survivors breaks adjacency — "music of the ledger"
	 * yields no bigram; "music provenance" yields one. No bridging: a bigram
	 * is a phrase that literally appears, never a fabricated collocation.
	 *
	 * Ranked weight-descending (term-ascending tiebreak for determinism),
	 * weights rounded to 4dp at output.
	 *
	 * @param int $post_id Target post ID.
	 * @param int $limit   Max candidates, clamped 1..SNT_ML_KEYWORD_LIMIT_MAX (default 8).
	 * @return array|WP_Error { ok, post_id, candidates: list<{term,weight}>, count, limit }
	 *                        or snt_ml_no_post (404).
	 */
	function snt_ml_keyword_candidates( $post_id, $limit = SNT_ML_KEYWORD_LIMIT_DEFAULT ) {
		$limit = max( 1, min( SNT_ML_KEYWORD_LIMIT_MAX, (int) $limit ) );

		$post = snt_ml_candidate_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;
		$content = (string) ( $post->post_content ?? '' );
		$tokens  = snt_ml_tokenize( $content );
		if ( array() === $tokens ) {
			// Empty/markup-only body: an ANSWER (no terms to rank), never an error.
			return array(
				'ok'         => true,
				'post_id'    => $post_id,
				'candidates' => array(),
				'count'      => 0,
				'limit'      => $limit,
			);
		}

		// Corpus stats over the cousin-scan walk. Tokenless bodies carry no
		// lexical signal and are excluded, exactly as the cousin scan excludes
		// them. The target is normally IN the walk; if truncation ever drops
		// it, snt_ml_tfidf_vector's df=0 smoothing still vectors it.
		$docs = array();
		foreach ( snt_corpus_fetch_posts( 'any', 'post' ) as $corpus_post ) {
			$doc_tokens = snt_ml_tokenize( (string) ( $corpus_post->post_content ?? '' ) );
			if ( array() !== $doc_tokens ) {
				$docs[ (int) $corpus_post->ID ] = $doc_tokens;
			}
		}
		$stats  = snt_ml_corpus_stats( $docs );
		$vector = snt_ml_tfidf_vector( $tokens, $stats );

		$weights = $vector; // term => weight; bigram keys carry a space so they never collide.

		// Bigrams from the raw word stream — the same strip/lower/split steps
		// snt_ml_tokenize applies BEFORE its survival filter, kept in lockstep,
		// PLUS adjacency breaks (PR #412 review): a bigram must be a phrase
		// that literally appears, so tags and sentence-final punctuation
		// become a break sentinel BEFORE the split — otherwise the last word
		// of one sentence/heading and the first of the next read as adjacent
		// ("…begins. Ledger…" must never yield "begins ledger").
		$raw = preg_replace( '/<!--.*?-->/s', ' ', $content );
		$raw = preg_replace( '/<[^>]*>/', ' ¶ ', (string) $raw );
		$raw = preg_replace( '/[.!?;:,]+/u', ' ¶ ', (string) $raw );
		$raw = mb_strtolower( (string) $raw, 'UTF-8' );
		$parts = preg_split( '/[^\p{L}\p{N}¶]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$parts = is_array( $parts ) ? $parts : array();

		$stop     = snt_ml_stopwords();
		$survives = static function ( $w ) use ( $stop ) {
			return ! isset( $stop[ $w ] ) && mb_strlen( $w, 'UTF-8' ) >= 2;
		};
		$n_parts = count( $parts );
		for ( $i = 0; $i < $n_parts - 1; $i++ ) {
			$w1 = $parts[ $i ];
			$w2 = $parts[ $i + 1 ];
			if ( '¶' === $w1 || '¶' === $w2 ) {
				continue; // Adjacency break: never pair across a sentence/tag boundary.
			}
			if ( ! $survives( $w1 ) || ! $survives( $w2 ) ) {
				continue; // BOTH members must survive; no bridging across dropped words.
			}
			$term = $w1 . ' ' . $w2;
			if ( isset( $weights[ $term ] ) ) {
				continue; // Deduped: same phrase, same score.
			}
			$weights[ $term ] = ( ( $vector[ $w1 ] ?? 0.0 ) + ( $vector[ $w2 ] ?? 0.0 ) )
				* SNT_ML_KEYWORD_BIGRAM_BOOST;
		}

		// Sort on FULL precision (round only at output), term-asc tiebreak.
		uksort( $weights, static function ( $x, $y ) use ( $weights ) {
			$by_w = $weights[ $y ] <=> $weights[ $x ]; // Descending.
			return 0 !== $by_w ? $by_w : strcmp( $x, $y );
		} );

		$candidates = array();
		foreach ( $weights as $term => $weight ) {
			$candidates[] = array(
				'term'   => (string) $term,
				'weight' => round( (float) $weight, 4 ),
			);
			if ( count( $candidates ) >= $limit ) {
				break;
			}
		}

		return array(
			'ok'         => true,
			'post_id'    => $post_id,
			'candidates' => $candidates,
			'count'      => count( $candidates ),
			'limit'      => $limit,
		);
	}
}

if ( ! function_exists( 'snt_ml_link_candidates' ) ) {
	/**
	 * Internal-link candidates: the artifact layer's related posts MINUS the
	 * ones the body already links to and minus non-published targets.
	 *
	 * Reuses _snt_ml_related via snt_ml_related_for_post() under the pinned
	 * artifact contract: rows / [] = real empty ANSWER / null = not built →
	 * snt_ml_not_built (503), identical to the related pipeline. Already-linked
	 * targets are matched by slug against the body's internal /notes/ hrefs
	 * (snt_ml_extract_note_links — the same extractor the artifact build uses,
	 * so "already linked" here means exactly what the graph signals saw).
	 * The reader re-gates publish status; the fetch below re-checks it anyway
	 * so a stale row can never surface an unpublished slug.
	 *
	 * @param int $post_id Target post ID.
	 * @param int $limit   Max candidates, clamped 1..SNT_ML_LINK_LIMIT_MAX (default 5).
	 * @return array|WP_Error { ok, post_id, candidates: list<{post_id,title,slug,url,score}>, count, limit }
	 *                        or snt_ml_no_post (404) / snt_ml_not_built (503).
	 */
	function snt_ml_link_candidates( $post_id, $limit = SNT_ML_LINK_LIMIT_DEFAULT ) {
		$limit = max( 1, min( SNT_ML_LINK_LIMIT_MAX, (int) $limit ) );

		$post = snt_ml_candidate_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_id = (int) $post->ID;

		if ( ! function_exists( 'snt_ml_related_for_post' ) ) {
			return new WP_Error(
				'snt_ml_not_built',
				'ML artifacts are not built yet; the related index is unavailable.',
				array( 'status' => 503 )
			);
		}
		// Fetch the full stored depth, THEN subtract — an exclusion must open
		// a slot for the next-ranked row, not shorten the answer.
		$rows = snt_ml_related_for_post( $post_id, SNT_ML_LINK_LIMIT_MAX );
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

		$linked = snt_ml_extract_note_links( (string) ( $post->post_content ?? '' ) );

		$candidates = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['post_id'], $row['score'] ) ) {
				continue; // Malformed row: skip, never fabricate.
			}
			$target = get_post( (int) $row['post_id'] );
			if ( ! $target || 'publish' !== (string) ( $target->post_status ?? '' ) ) {
				continue; // Never propose linking to something a reader can't reach.
			}
			$slug = (string) ( $target->post_name ?? '' );
			if ( '' !== $slug && in_array( $slug, $linked, true ) ) {
				continue; // The body already links there — nothing to propose.
			}
			$candidates[] = array(
				'post_id' => (int) $target->ID,
				'title'   => (string) ( $target->post_title ?? '' ),
				'slug'    => $slug,
				// v10.19.0: the resolved permalink, so no consumer ever
				// hand-builds a path from the slug. (string) folds core's
				// false-for-unresolvable into '' — never a boolean in JSON.
				'url'     => (string) get_permalink( $target->ID ),
				'score'   => (float) $row['score'],
			);
			if ( count( $candidates ) >= $limit ) {
				break;
			}
		}

		return array(
			'ok'         => true,
			'post_id'    => $post_id,
			'candidates' => $candidates,
			'count'      => count( $candidates ),
			'limit'      => $limit,
		);
	}
}
