<?php
/**
 * Signal & Noise Tools — Jev over the notes: the daily judgement pass.
 *
 * One request per note (a request evaluates ONE state; the fan-out is many
 * questions about that one note, answered in parallel), over every published
 * and scheduled note, stored in one option. Nothing here runs at scan time:
 * health check 30 (inc/health-check-jev-notes.php) and the `jev-notes`
 * ability read the option, the same shape as the Search Console and Bing
 * syncs. No key, no schedule, no verdicts; the check reads skipped.
 *
 * The questions are the prose judgements the deterministic checks cannot
 * make. Check 28 accepts a search title by SHAPE ("Aphorism: two words");
 * Jev is asked whether a person would type it. The rubric levels are concrete
 * descriptions, not degrees (jev-1.13 reads literally), the state is four
 * short fields (accuracy falls with irrelevant state), and nothing asks Jev
 * to count or compare dates (it cannot).
 *
 * @since 16.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_DATA_OPTION  = 'sn_jev_notes';
const SN_JEV_SYNC_HOOK    = 'sn_jev_notes_daily';
const SN_JEV_OPENING_MAX  = 600;

/** Readiness: a key is stored. The sync keeps its schedule equal to this. */
function sn_jev_is_ready() {
	return function_exists( 'sn_jev_key' ) && '' !== sn_jev_key();
}

/**
 * The questions, PURE. Keys are stable: the stored verdicts and the check
 * read them by name.
 *
 * @since 16.3.0
 */
function sn_jev_note_questions() {
	return array(
		'title_query'      => array(
			'type'         => 'score',
			'instructions' => 'Read search_title as the title tag a search engine shows for an article about the subject in description and opening. Rate how closely search_title matches what a person would type into a search engine to find that article.',
			'criteria'     => array(
				'search_title is an aphorism, slogan or wordplay; a person looking for an article on this subject would not type these words',
				'search_title names the subject in plain words, but a person searching would phrase the need differently or with different key terms',
				'search_title reads like what a person would type into a search engine to find exactly this article: the subject in the words a searcher uses',
			),
		),
		'description_says' => array(
			'type'         => 'score',
			'instructions' => 'Rate description as the summary a search result shows under the title.',
			'criteria'     => array(
				'description is missing, a fragment, or repeats the title without adding what the article says',
				'description says what the article is about but not what it argues or concludes',
				'description states the subject and the argument in one or two complete sentences a reader could act on',
			),
		),
		'opening_names'    => array(
			'type'         => 'noul',
			'instructions' => 'Do the first two sentences of opening name the subject a reader would have searched for, in plain words, rather than opening with an anecdote, a question or a metaphor?',
		),
	);
}

/**
 * A note's state: four short fields. PURE given the post and its meta.
 *
 * @since 16.3.0
 */
function sn_jev_note_state( $post ) {
	$plain = static function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$title   = $plain( $post->post_title ?? '' );
	$seo     = function_exists( 'sn_post_settings_get_seo_title' ) ? $plain( sn_post_settings_get_seo_title( (int) $post->ID ) ) : '';
	$desc    = function_exists( 'sn_seo_resolve_singular_description' ) ? $plain( sn_seo_resolve_singular_description( $post ) ) : $plain( $post->post_excerpt ?? '' );
	$content = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( (string) ( $post->post_content ?? '' ) ) : (string) ( $post->post_content ?? '' );
	$content = preg_replace( '/<!--.*?-->/s', '', $content );
	$opening = $plain( $content );
	if ( function_exists( 'mb_substr' ) ) {
		$opening = mb_substr( $opening, 0, SN_JEV_OPENING_MAX, 'UTF-8' );
	} else {
		$opening = substr( $opening, 0, SN_JEV_OPENING_MAX );
	}
	return array(
		'title'        => $title,
		'search_title' => '' !== $seo ? $seo : $title,
		'description'  => $desc,
		'opening'      => $opening,
	);
}

/**
 * The stored verdict for one note, from the answers. PURE. Scores are on the
 * rubric's 1..3 positions; the floor decides `sure`.
 *
 * @since 16.3.0
 */
function sn_jev_verdict_shape( array $answers ) {
	$t = $answers['title_query'] ?? array();
	$d = $answers['description_says'] ?? array();
	$o = $answers['opening_names'] ?? array();
	return array(
		'title'       => array( 'score' => (float) ( $t['score'] ?? 0 ), 'confidence' => (float) ( $t['confidence'] ?? 0 ), 'sure' => array() !== $t && sn_jev_is_sure( $t ) ),
		'description' => array( 'score' => (float) ( $d['score'] ?? 0 ), 'confidence' => (float) ( $d['confidence'] ?? 0 ), 'sure' => array() !== $d && sn_jev_is_sure( $d ) ),
		'opening'     => array( 'noul' => (float) ( $o['noul'] ?? 0 ) ),
	);
}

/** The stored pass, or null when nothing has synced. */
function sn_jev_data() {
	$d = get_option( SN_JEV_DATA_OPTION, null );
	return is_array( $d ) && isset( $d['synced_at'] ) ? $d : null;
}

/** Every published and scheduled note, ids only. */
function sn_jev_note_ids() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * One request per note, every note, one option. A note whose request fails
 * keeps its previous verdict with the error beside it; the pass records its
 * own usage (Jev's tokens are not Anthropic's and do not enter that budget).
 *
 * @since 16.3.0
 * @return array{ok:bool,judged:int,failed:int,error:string}
 */
function sn_jev_sync() {
	if ( ! sn_jev_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'error' => 'no-key' );
	}
	$prev      = sn_jev_data();
	$notes     = is_array( $prev['notes'] ?? null ) ? $prev['notes'] : array();
	$questions = sn_jev_note_questions();
	$judged    = 0;
	$failed    = 0;
	$tokens    = 0;
	$last_err  = '';
	foreach ( sn_jev_note_ids() as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$r = sn_jev_ask( sn_jev_note_state( $post ), $questions );
		if ( ! $r['ok'] ) {
			$failed++;
			$last_err = (string) $r['error'];
			$notes[ $id ] = array_merge( is_array( $notes[ $id ] ?? null ) ? $notes[ $id ] : array( 'verdict' => null, 'at' => 0 ), array( 'title' => (string) $post->post_title, 'error' => gmdate( 'c' ) . ' ' . $last_err ) );
			if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
				break; // a refused key refuses every note; stop spending requests.
			}
			continue;
		}
		$judged++;
		$tokens      += (int) ( $r['usage']['input_tokens'] ?? 0 );
		$notes[ $id ] = array( 'title' => (string) $post->post_title, 'verdict' => sn_jev_verdict_shape( $r['answers'] ), 'at' => time(), 'error' => '' );
	}
	update_option( SN_JEV_DATA_OPTION, array(
		'synced_at'  => time(),
		'model'      => SN_JEV_MODEL,
		'notes'      => $notes,
		'usage'      => array( 'requests' => $judged + $failed, 'input_tokens' => $tokens ),
		'last_error' => '' !== $last_err ? gmdate( 'c' ) . ' ' . $last_err : '',
	), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'error' => $last_err );
}
add_action( SN_JEV_SYNC_HOOK, 'sn_jev_sync' );
add_action( 'init', function () {
	if ( sn_jev_is_ready() && ! wp_next_scheduled( SN_JEV_SYNC_HOOK ) ) {
		wp_schedule_event( time() + 900, 'daily', SN_JEV_SYNC_HOOK );
	}
}, 20 );
