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
			'instructions' => array(
				'question' => 'Rate `query_part` as the words a person would type into a search engine to find this article. The article\'s subject is described by `description`.',
				'note'     => 'Judge `query_part` only. `title` is the article\'s heading and is not being rated; `search_title` is shown for context.',
			),
			'criteria'     => array(
				array(
					'summary' => '`query_part` is a slogan, an aphorism or wordplay; nobody looking for this subject would type it',
					'signals' => array( 'A metaphor or a turn of phrase', 'Names no subject a searcher has a word for', 'Reads like a headline meant to intrigue' ),
				),
				array(
					'summary' => '`query_part` names the subject in plain words, but a searcher would phrase the need differently or use other key terms',
					'signals' => array( 'The topic is clear but the wording is the author\'s, not a searcher\'s', 'Missing the term a person would actually search (a product, a standard, a practice)' ),
				),
				array(
					'summary' => '`query_part` is close to what a person would type to find exactly this article',
					'signals' => array( 'Plain nouns a searcher uses', 'Names the subject and the angle', 'Could be pasted into a search box as is' ),
				),
			),
		),
		'description_says' => array(
			'type'         => 'score',
			'instructions' => array(
				'question' => 'Rate `description` as the summary a search result shows under the title.',
				'note'     => 'A description that only names the topic is level two; a description that states what the article argues or concludes is level three.',
			),
			'criteria'     => array(
				array(
					'summary' => '`description` is missing, a fragment, or repeats `title` without adding what the article says',
					'signals' => array( 'Empty', 'Under a full sentence', 'The same words as the title' ),
				),
				array(
					'summary' => '`description` says what the article is about but not what it argues',
					'signals' => array( 'Names the topic', 'No claim, no conclusion, no position', 'Could describe several different articles on the topic' ),
				),
				array(
					'summary' => '`description` states the subject and the argument in one or two complete sentences',
					'signals' => array( 'A claim a reader could agree or disagree with', 'A conclusion or a distinction', 'Could describe only this article' ),
				),
			),
		),
		'opening_names'    => array(
			'type'         => 'noul',
			'instructions' => 'Do the first two sentences of `opening` name the subject a reader would have searched for?',
			'criteria'     => array(
				'true'  => array( 'what' => 'The subject is named in plain words within the first two sentences.', 'examples' => array( 'Provenance records do not survive lossy encoding, and this note explains why.' ) ),
				'false' => array( 'what' => 'The opening starts with an anecdote, a question, a metaphor or a scene, and the subject is not named in them.', 'examples' => array( 'It was 3 a.m. when the engineer noticed the file had changed.' ) ),
			),
		),
	);
}

/**
 * A note's state: five short fields. PURE given the post and its meta.
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
	$search = '' !== $seo ? $seo : $title;
	return array(
		'title'        => $title,
		'search_title' => $search,
		// 16.3.3: the half Jev is asked to rate. "Aphorism: plain words" carries
		// the aphorism in front, and the first pass read the compound
		// literally (0.5 on 45 of 69 titles, sure on none); the rubric's
		// level zero names an aphorism, so it hedged. The query is the part
		// after the colon when the front matches the title; else the whole.
		'query_part'   => sn_jev_query_part( $search, $title ),
		'description'  => $desc,
		'opening'      => $opening,
	);
}

/**
 * The query half of a search title. PURE. "Aphorism: plain words" whose
 * front is the note's own title yields "plain words"; anything else is
 * returned whole.
 *
 * @since 16.3.3
 */
function sn_jev_query_part( $search_title, $title ) {
	$search_title = trim( (string) $search_title );
	$title        = trim( (string) $title );
	$at           = strpos( $search_title, ': ' );
	if ( false === $at ) {
		return $search_title;
	}
	$norm = static function ( $t ) {
		return strtolower( preg_replace( '/\s+/', ' ', trim( $t, " \t\n\r\0\x0B:.?!" ) ) );
	};
	$front = substr( $search_title, 0, $at );
	$rest  = trim( substr( $search_title, $at + 2 ) );
	return ( '' !== $rest && $norm( $front ) === $norm( $title ) ) ? $rest : $search_title;
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
		$r = sn_jev_ask( sn_jev_note_state( $post ), $questions, 'notes' );
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
