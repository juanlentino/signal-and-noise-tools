<?php
/**
 * Signal & Noise — draft-time echoes (ML pipeline #2, editor side).
 *
 * The cousin scan (inc/ml-cousins.php) answers "which pairs in the corpus are
 * near-duplicates?" — an all-pairs question, asked from the Health tab, about
 * work that already exists. This answers the writer's question instead: "what
 * have I already written that this is close to?", asked while the draft is
 * still a choice.
 *
 * A CORRECTION worth recording, because the planning note had it backwards:
 * snt_corpus_fetch_posts( 'any', 'post' ) already walks ALL five non-trash
 * statuses, so a SAVED draft is very much in the corpus and already cousins.
 * The gap was never that the draft is invisible to the computation — it is that
 * the computation was only ever surfaced as a corpus-wide pair list, which is
 * the wrong shape and the wrong moment for someone mid-sentence.
 *
 * Three constraints this module holds:
 *
 *   1. NOTHING IN THE READER'S BROWSER. This is the ML family's standing never.
 *      Nothing here hooks a front-end action; the only caller is a read ability
 *      behind manage_options. inc/ml-related-render.php must never reference it.
 *   2. NO NEW MODEL. Same kernel, same TF-IDF, same cosine as the cousin scan.
 *   3. SILENCE BEATS A BAD MATCH. Below the threshold the answer is "nothing",
 *      never the least-bad row in the corpus. A writer who is told everything
 *      echoes something stops reading the panel.
 *
 * @package SignalNoiseTools
 * @since 10.77.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Echo threshold, deliberately BELOW the cousin threshold (0.6).
 *
 * This is a judgment, not a measurement, and it is filterable because of that.
 * The reasoning: a cousin pair is two finished notes, where real overlap shows
 * up as a high cosine. A draft in progress covers only part of the ground its
 * finished twin covers, so the same underlying overlap reads lower — and the
 * moment worth catching is early, when changing course is still cheap. Set it
 * at 0.6 and the panel goes quiet exactly when it would have been most useful.
 */
const SNT_ML_ECHO_THRESHOLD_DEFAULT = 0.45;

/** Never return more than this many echoes, however many clear the bar. */
const SNT_ML_ECHO_MAX = 5;

/**
 * The existing notes a draft most echoes.
 *
 * @param int         $post_id   The draft being written. Excluded from its own
 *                               comparison corpus — a saved draft is IN the
 *                               corpus, and without this it would match itself
 *                               at cosine 1.0 and drown everything else.
 * @param string|null $content   Editor content to score. Null reads the saved
 *                               body, so the ability works before the first
 *                               autosave carries anything.
 * @param float|null  $threshold Minimum cosine. Null uses the default; clamped
 *                               to the cousin bounds so the two surfaces cannot
 *                               drift into different notions of "similar".
 * @param int         $limit     Maximum echoes returned (capped at SNT_ML_ECHO_MAX).
 * @return array{ok:bool,echoes:array,echo_count:int,threshold:float,posts_compared:int,reason:string,scanned_at:int}
 */
function snt_ml_draft_echoes( $post_id = 0, $content = null, $threshold = null, $limit = 3 ) {
	$post_id   = (int) $post_id;
	$threshold = ( null === $threshold ) ? SNT_ML_ECHO_THRESHOLD_DEFAULT : (float) $threshold;
	$threshold = max( SNT_ML_COUSIN_THRESHOLD_MIN, min( SNT_ML_COUSIN_THRESHOLD_MAX, $threshold ) );
	$limit     = max( 1, min( SNT_ML_ECHO_MAX, (int) $limit ) );

	$empty = array(
		'ok'             => true,
		'echoes'         => array(),
		'echo_count'     => 0,
		'threshold'      => $threshold,
		'posts_compared' => 0,
		'reason'         => '',
		'scanned_at'     => time(),
	);

	$posts = snt_corpus_fetch_posts( 'any', 'post' );

	// Resolve the draft body. An explicit '' is a real value meaning "the editor
	// is empty" — only NULL means "read what is saved", so array_key_exists
	// semantics rather than a truthiness test.
	if ( null === $content ) {
		$content = '';
		foreach ( $posts as $post ) {
			if ( (int) $post->ID === $post_id ) {
				$content = (string) ( $post->post_content ?? '' );
				break;
			}
		}
	}
	$content = (string) $content;

	$draft_tokens = snt_ml_tokenize( $content );
	if ( array() === $draft_tokens ) {
		// No lexical signal: an empty editor, or a body that is all markup.
		// Cosine is undefined here, not zero — say so instead of returning a
		// confident "nothing echoes this".
		$empty['reason'] = 'no_lexical_signal';
		return $empty;
	}

	// Comparison corpus: every other post with real lexical signal.
	$rows = array();
	$docs = array();
	foreach ( $posts as $post ) {
		$id = (int) $post->ID;
		if ( $id === $post_id ) {
			continue; // Never echo yourself.
		}
		$body = (string) ( $post->post_content ?? '' );
		if ( '' === snt_corpus_content_hash( $body ) ) {
			continue;
		}
		$tokens = snt_ml_tokenize( $body );
		if ( array() === $tokens ) {
			continue;
		}
		$docs[ $id ] = $tokens;
		$rows[ $id ] = array(
			'post_id' => $id,
			'title'   => (string) ( $post->post_title ?? '' ),
			'slug'    => (string) ( $post->post_name ?? '' ),
			'status'  => (string) ( $post->post_status ?? '' ),
		);
	}

	if ( array() === $docs ) {
		$empty['reason'] = 'empty_corpus';
		return $empty;
	}

	// IDF is computed over the corpus PLUS the draft, so the draft's own terms
	// are weighted on the same footing as everything it is compared against.
	// Scoring the draft against stats it did not contribute to would quietly
	// inflate any term the corpus happens not to use.
	$stats_docs              = $docs;
	$stats_docs['__draft__'] = $draft_tokens;
	$stats                   = snt_ml_corpus_stats( $stats_docs );

	$draft_vector = snt_ml_tfidf_vector( $draft_tokens, $stats );

	$echoes = array();
	foreach ( $docs as $id => $tokens ) {
		$cos = round( snt_ml_cosine( $draft_vector, snt_ml_tfidf_vector( $tokens, $stats ) ), 4 );
		if ( $cos < $threshold ) {
			continue; // Silence beats the least-bad match.
		}
		$echoes[] = array(
			'post_id' => $rows[ $id ]['post_id'],
			'title'   => $rows[ $id ]['title'],
			'slug'    => $rows[ $id ]['slug'],
			'status'  => $rows[ $id ]['status'],
			'cosine'  => $cos,
		);
	}

	usort( $echoes, static function ( $x, $y ) {
		$by_cos = $y['cosine'] <=> $x['cosine']; // Descending.
		return 0 !== $by_cos ? $by_cos : $x['post_id'] <=> $y['post_id'];
	} );

	$total  = count( $echoes );
	$echoes = array_slice( $echoes, 0, $limit );

	return array(
		'ok'             => true,
		'echoes'         => $echoes,
		'echo_count'     => count( $echoes ),
		'threshold'      => $threshold,
		'posts_compared' => count( $docs ),
		// A cap that hides rows says so, per the no-silent-truncation rule.
		'reason'         => ( $total > count( $echoes ) ) ? 'truncated_to_limit' : '',
		'scanned_at'     => time(),
	);
}
