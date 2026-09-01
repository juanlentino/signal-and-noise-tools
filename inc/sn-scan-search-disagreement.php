<?php
/**
 * Signal & Noise Tools — sn-scan scan_type "search_disagreement"
 * (measurement weave, Phase 3 — docs/proposals/measurement-weave-2026-08-31.md).
 *
 * The instrument neither the corpus nor Search Console can build alone:
 * TF-IDF keyword candidates say what a post IS ABOUT; Google says what it IS
 * FOUND FOR. Where those disagree is the finding. Three readings, three
 * detectors, and they are different problems:
 *
 *  - no_impressions   "about X, found for nothing" — a post of real length that
 *                     earned zero impressions in the synced window. Indexation
 *                     or a contentless-page choke point; the cross-exam says which.
 *  - thin_but_found   "found for X, about nothing" — a thin post earning real
 *                     impressions. The best refresh candidate on the site.
 *  - query_unclaimed  "found for Y, about nothing (site-wide)" — a site-level
 *                     query with impressions that no post's keyword candidates
 *                     claim. The page-level "about X, found for Y" reading is
 *                     NOT derivable from stored data: the sync pulls the page
 *                     and query dimensions SEPARATELY, never page×query, so a
 *                     query cannot be attributed to a page without a new fetch.
 *                     This detector is the honest site-level substitute and
 *                     says so in its triggers_on.
 *
 * Join rule (Rule 1 of the weave): BOTH sides go through sn_path_join_key().
 * A stored page path and a permalink that spell the same page differently
 * would otherwise join to nothing and read as "no impressions" — the quiet
 * failure this scan exists to name, manufactured by the scan itself.
 *
 * No apply path: a disagreement is fixed by editing, so apply_hint is null.
 *
 * @package SignalNoiseTools
 * @since 13.57.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Heuristic readings, not measurements — below every rule-based scan type. */
const SNT_SN_SCAN_CONF_SEARCH_DISAGREEMENT = 0.6;

/** A post shorter than this is "thin"; one at least this long "has content". */
const SNT_SEARCH_THIN_WORDS = 300;

/** Query tokens shorter than this never claim a match (stopword-length noise). */
const SNT_SEARCH_QUERY_TOKEN_MIN = 4;

/**
 * PURE core. Every input is pre-normalized so a test drives it without WP.
 *
 * @param array<int,array{id:int,path:string,word_count:int}> $posts    Published posts in scope.
 * @param array<string,array>                                 $pages    GSC rows keyed by sn_path_join_key().
 * @param array<int,array>                                    $queries  GSC query rows {key, impressions, clicks, position}.
 * @param array<int,string[]>|null                            $keywords post id => keyword terms; null = pipeline unavailable.
 * @return array{candidates:array,keyword_pipeline:bool}
 */
function snt_search_disagreement_impl( $posts, $pages, $queries, $keywords ) {
	$candidates = array();
	$claimed    = array(); // lowercase keyword terms across the whole scope.

	foreach ( $posts as $p ) {
		$path  = (string) ( $p['path'] ?? '' );
		$words = (int) ( $p['word_count'] ?? 0 );
		$id    = (int) ( $p['id'] ?? 0 );
		if ( '' === $path || $id <= 0 ) {
			continue;
		}
		$row = isset( $pages[ $path ] ) && is_array( $pages[ $path ] ) ? $pages[ $path ] : null;
		$imp = null === $row ? 0 : (int) ( $row['impressions'] ?? 0 );

		if ( 0 === $imp && $words >= SNT_SEARCH_THIN_WORDS ) {
			$candidates[] = snt_search_disagreement_candidate( 'no_impressions', $path, $id, $imp, $words,
				'A post of real length with zero impressions in the synced window. Not a ranking problem: Google is not showing it at all. Read search_crossexam to tell indexation from a contentless-page choke point.' );
		} elseif ( $imp >= SNT_GSC_DRIFT_MIN_IMPRESSIONS && $words < SNT_SEARCH_THIN_WORDS ) {
			$candidates[] = snt_search_disagreement_candidate( 'thin_but_found', $path, $id, $imp, $words,
				'Thin content earning real impressions: Google already surfaces it. The best refresh candidate on the site — expand what it is found for.' );
		}
		if ( is_array( $keywords ) ) {
			foreach ( (array) ( $keywords[ $id ] ?? array() ) as $term ) {
				foreach ( preg_split( '/\s+/', strtolower( trim( (string) $term ) ) ) ?: array() as $tok ) {
					if ( strlen( $tok ) >= SNT_SEARCH_QUERY_TOKEN_MIN ) {
						$claimed[ $tok ] = true;
					}
				}
			}
		}
	}

	if ( is_array( $keywords ) ) {
		foreach ( (array) $queries as $q ) {
			$text = strtolower( trim( (string) ( $q['key'] ?? '' ) ) );
			$imp  = (int) ( $q['impressions'] ?? 0 );
			if ( '' === $text || $imp < SNT_GSC_DRIFT_MIN_IMPRESSIONS ) {
				continue;
			}
			$tokens = array_values( array_filter( preg_split( '/\s+/', $text ) ?: array(), static function ( $t ) {
				return strlen( $t ) >= SNT_SEARCH_QUERY_TOKEN_MIN;
			} ) );
			if ( array() === $tokens ) {
				continue; // Nothing long enough to test: not evidence either way.
			}
			$hit = false;
			foreach ( $tokens as $t ) {
				if ( isset( $claimed[ $t ] ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				$candidates[] = array(
					'target_identity'     => $text,
					'content_fingerprint' => md5( 'query_unclaimed|' . $text . '|' . $imp ),
					'targets'             => array( array( 'query' => $text, 'impressions' => $imp ) ),
					'confidence'          => SNT_SN_SCAN_CONF_SEARCH_DISAGREEMENT,
					'evidence'            => array(
						'detector'    => 'query_unclaimed',
						'impressions' => $imp,
						'clicks'      => (int) ( $q['clicks'] ?? 0 ),
						'position'    => round( (float) ( $q['position'] ?? 0 ), 1 ),
						'note'        => 'Google shows the site for this query and no post\'s keyword candidates contain any of its words. SITE-LEVEL: the sync stores page and query dimensions separately, so the page it lands on is unknown without a new fetch.',
					),
					'apply_hint'          => null,
				);
			}
		}
	}

	return array( 'candidates' => $candidates, 'keyword_pipeline' => is_array( $keywords ) );
}

/** One page-level candidate. */
function snt_search_disagreement_candidate( $detector, $path, $post_id, $impressions, $words, $note ) {
	return array(
		'target_identity'     => $path,
		'content_fingerprint' => md5( $detector . '|' . $path . '|' . $impressions . '|' . $words ),
		'targets'             => array( array( 'post_id' => (int) $post_id, 'path' => $path ) ),
		'confidence'          => SNT_SN_SCAN_CONF_SEARCH_DISAGREEMENT,
		'evidence'            => array(
			'detector'    => $detector,
			'impressions' => (int) $impressions,
			'word_count'  => (int) $words,
			'note'        => $note,
		),
		'apply_hint'          => null,
	);
}

/**
 * The sn-scan adapter. Never synced → WP_Error 503 (a skip must not become an
 * empty candidate list — empty means "measured, clean").
 *
 * @param int[]|null $allowed_ids null = every published post; [] = none.
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_search_disagreement( $allowed_ids ) {
	if ( ! function_exists( 'snt_gsc_data' ) || ! function_exists( 'sn_path_join_key' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Search Console store or path join key not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$data = snt_gsc_data();
	if ( null === $data ) {
		return new WP_Error( 'snt_search_not_synced', __( 'Search Console has never synced: the scan cannot measure, and "nothing found" would be a lie.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}
	$pages = array();
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $m ) {
		$key = sn_path_join_key( (string) $path );
		if ( '' !== $key && is_array( $m ) ) {
			$pages[ $key ] = $m;
		}
	}

	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' );
	if ( is_array( $allowed_ids ) ) {
		if ( array() === $allowed_ids ) {
			return array( 'candidates' => array(), 'posts_examined' => 0, 'posts_skipped' => 0, 'truncated' => false );
		}
		$args['post__in'] = array_map( 'intval', $allowed_ids );
	}
	$ids      = function_exists( 'get_posts' ) ? (array) get_posts( $args ) : array();
	$posts    = array();
	$keywords = function_exists( 'snt_ml_keyword_candidates' ) ? array() : null;
	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			continue;
		}
		$text    = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $post->post_content ) : wp_strip_all_tags( (string) $post->post_content );
		$posts[] = array(
			'id'         => (int) $post->ID,
			'path'       => sn_path_join_key( (string) get_permalink( (int) $post->ID ) ),
			'word_count' => function_exists( 'snt_word_count' ) ? (int) snt_word_count( $text ) : (int) str_word_count( $text ),
		);
		if ( is_array( $keywords ) ) {
			$k = snt_ml_keyword_candidates( (int) $post->ID, SNT_ML_KEYWORD_LIMIT_MAX );
			$keywords[ (int) $post->ID ] = ( is_array( $k ) && ! empty( $k['candidates'] ) )
				? array_map( static function ( $c ) { return (string) ( $c['term'] ?? '' ); }, (array) $k['candidates'] )
				: array();
		}
	}

	$r = snt_search_disagreement_impl( $posts, $pages, (array) ( $data['queries'] ?? array() ), $keywords );
	return array(
		'candidates'     => $r['candidates'],
		'posts_examined' => count( $posts ),
		'posts_skipped'  => 0,
		'truncated'      => false,
	);
}
