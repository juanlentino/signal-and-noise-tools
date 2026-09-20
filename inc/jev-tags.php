<?php
/**
 * Signal & Noise Tools: tag fit (16.8.0; 16.9.2 reads the attached tags only).
 *
 * Tags are the one editorial field a published note can still change:
 * they are not prose, so a tag edit moves nothing the signature covers.
 * Jev reads each note against the tags it carries: one request per note,
 * one Score per attached tag (does the note touch what the tag names, so a
 * reader browsing the tag would find it relevant, or was the tag attached
 * for reach?). The tag's own description is what Jev reads, so a wrong
 * reading of a right tag is a description to fix. Weekly and on demand;
 * check 31 reads the stored pass.
 *
 * 16.9.2 dropped the other half, a Noul per tag the note does not carry.
 * The first live pass asked it 60 times a note and answered with the
 * corpus's broad facets on every note (Authorship on 44 of 69); what a note
 * carries is the owner's call, and the question was most of the tokens.
 *
 * @since 16.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_TAGS_OPTION  = 'sn_jev_tags';
const SN_JEV_TAGS_HOOK    = 'sn_jev_tags_weekly';
const SN_JEV_TAGS_OPENING = 1200;
// The misfit lines, read off two live passes. 16.9.1: 27 of 40 misfits at
// the 1.0 line sat at confidence 0 to 0.26, shrugs painted as verdicts.
// 16.9.2: the house tags by what a note TOUCHES, so the question now asks
// that, and a misfit is a tag whose subject is absent (under 0.5 of 2) read
// with confidence 0.7 or better. A short list Jev is sure about, or nothing.
const SN_JEV_TAG_MISFIT_BELOW      = 0.5;
const SN_JEV_TAG_MISFIT_CONFIDENCE = 0.7;

/** Every tag with its description, most used first, keyed by term id. */
function sn_jev_tag_pool() {
	$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC' ) );
	$pool  = array();
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		$pool[ (int) $t->term_id ] = array( 'name' => (string) $t->name, 'description' => trim( (string) $t->description ), 'count' => (int) $t->count );
	}
	return $pool;
}

/** The state: the note and its attached tags, keyed tID. PURE. */
function sn_jev_tags_state( $post, array $attached, array $pool ) {
	$plain = static function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$content = preg_replace( '/<!--.*?-->/s', '', strip_shortcodes( (string) ( $post->post_content ?? '' ) ) );
	$opening = $plain( $content );
	$opening = function_exists( 'mb_substr' ) ? mb_substr( $opening, 0, SN_JEV_TAGS_OPENING, 'UTF-8' ) : substr( $opening, 0, SN_JEV_TAGS_OPENING );
	$tags    = array();
	foreach ( $attached as $id ) {
		if ( isset( $pool[ $id ] ) ) {
			$tags[ 't' . (int) $id ] = array( 'name' => $pool[ $id ]['name'], 'description' => $pool[ $id ]['description'] );
		}
	}
	return array(
		'note' => array(
			'title'       => $plain( $post->post_title ?? '' ),
			'description' => function_exists( 'sn_seo_resolve_singular_description' ) ? $plain( sn_seo_resolve_singular_description( $post ) ) : $plain( $post->post_excerpt ?? '' ),
			'opening'     => $opening,
		),
		'tags' => $tags,
	);
}

/** One Score per attached tag. PURE. */
function sn_jev_tags_questions( array $attached ) {
	$q = array();
	foreach ( $attached as $id ) {
		$q[ 'a' . (int) $id ] = array(
			'type'         => 'score',
			'instructions' => array(
				'question' => 'Does `note` touch what `tags.t' . (int) $id . '` names, so that a reader browsing the tag would find the note relevant?',
				'note'     => 'Judge from the tag\'s name and description against the note\'s title, description and opening. A tag names a facet a note touches, not only its thesis: a note can carry several. The one thing to catch is a tag whose subject the note does not touch at all.',
			),
			'criteria'     => array(
				array( 'summary' => 'The subject the tag names is absent from the note; the tag is attached for reach or by habit', 'signals' => array( 'The tag\'s subject appears nowhere in the title, description or opening', 'Only a word overlaps with the tag\'s name' ) ),
				array( 'summary' => 'The note touches it: the subject is present as context, an example or a step in the argument, and a reader browsing the tag would find the note relevant', 'signals' => array( 'The subject is discussed, if briefly', 'The note would make sense in a list under this tag' ) ),
				array( 'summary' => 'The note is about it; the tag\'s description could describe the note', 'signals' => array( 'The claim is about the tag\'s subject', 'A reader browsing the tag would want this note first' ) ),
			),
		);
	}
	return $q;
}

/**
 * The rows: one per attached tag with Jev's score and confidence. PURE.
 *
 * @return array<int,array{id:int,name:string,score:float,confidence:float}>
 */
function sn_jev_tags_judge( array $answers, array $attached, array $pool ) {
	$rows = array();
	foreach ( $attached as $id ) {
		$a = $answers[ 'a' . (int) $id ] ?? null;
		if ( ! is_array( $a ) || 'score' !== (string) ( $a['type'] ?? '' ) || ! isset( $pool[ $id ] ) ) {
			continue;
		}
		$rows[] = array( 'id' => (int) $id, 'name' => $pool[ $id ]['name'], 'score' => round( (float) $a['score'], 2 ), 'confidence' => round( (float) ( $a['confidence'] ?? 0 ), 2 ) );
	}
	return $rows;
}

/**
 * An attached tag Jev read as attached for reach: its subject absent (under
 * the line), read with enough confidence to be a verdict rather than a shrug.
 *
 * @param array $t A stored attached row {score, confidence}.
 * @return bool
 */
function sn_jev_tag_is_misfit( $t ) {
	return (float) ( $t['score'] ?? 2 ) < SN_JEV_TAG_MISFIT_BELOW && (float) ( $t['confidence'] ?? 0 ) >= SN_JEV_TAG_MISFIT_CONFIDENCE;
}

/**
 * The pass: one request per published or scheduled note, one option.
 *
 * @return array{ok:bool,judged:int,failed:int,misfits:int,error:string}
 */
function sn_jev_tags_sync() {
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'misfits' => 0, 'error' => 'no-key' );
	}
	$pool    = sn_jev_tag_pool();
	$notes   = array();
	$judged  = 0;
	$failed  = 0;
	$tokens  = 0;
	$misfits = 0;
	$err     = '';
	foreach ( sn_jev_note_ids() as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			continue;
		}
		$attached = array_values( array_filter( array_map( 'intval', (array) wp_get_post_tags( (int) $id, array( 'fields' => 'ids' ) ) ), static function ( $t ) use ( $pool ) {
			return isset( $pool[ $t ] );
		} ) );
		if ( array() === $attached ) {
			continue; // Nothing to read; what a note carries is the owner's call.
		}
		$r = sn_jev_ask( sn_jev_tags_state( $post, $attached, $pool ), sn_jev_tags_questions( $attached ), 'tags' );
		if ( ! $r['ok'] ) {
			$failed++;
			$err = (string) $r['error'];
			if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
				break;
			}
			continue;
		}
		$judged++;
		$tokens += (int) ( $r['usage']['input_tokens'] ?? 0 );
		$rows    = sn_jev_tags_judge( $r['answers'], $attached, $pool );
		foreach ( $rows as $row ) {
			if ( sn_jev_tag_is_misfit( $row ) ) {
				$misfits++;
			}
		}
		$notes[ (int) $id ] = array( 'title' => (string) $post->post_title, 'attached' => $rows );
	}
	update_option( SN_JEV_TAGS_OPTION, array(
		'synced_at'  => time(),
		'tags'       => count( $pool ),
		'notes'      => $notes,
		'usage'      => array( 'requests' => $judged + $failed, 'input_tokens' => $tokens ),
		'last_error' => '' !== $err ? gmdate( 'c' ) . ' ' . $err : '',
	), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'misfits' => $misfits, 'error' => $err );
}

/**
 * The rows the Tags leaf paints and the apply handler allows: per flagged
 * note, the attached tags Jev read as attached for reach. PURE given the
 * stored pass.
 *
 * @return array<int,array{title:string,remove:array}>
 */
function sn_jev_tags_rows( $data ) {
	$rows = array();
	foreach ( (array) ( $data['notes'] ?? array() ) as $id => $n ) {
		$remove = array_values( array_filter( (array) ( $n['attached'] ?? array() ), 'sn_jev_tag_is_misfit' ) );
		if ( array() === $remove ) {
			continue;
		}
		$rows[ (int) $id ] = array( 'title' => (string) ( $n['title'] ?? '' ), 'remove' => $remove );
	}
	return $rows;
}

/** A note that only TOUCHES a tag scores under this; at or above it, the note is about it. */
const SN_JEV_TAG_ABOUT_AT = 1.0;

/**
 * The pass pivoted per tag: what a reader of that tag's archive gets. Every
 * note carrying the tag with Jev's score, the mean, and the notes under
 * SN_JEV_TAG_ABOUT_AT (they touch the tag; a reader browsing it may find
 * them beside the point). No lines, no boxes: a reading of the archives.
 * PURE given the stored pass.
 *
 * @return array<int,array{id:int,name:string,notes:int,mean:float,touching:array<int,array{post_id:int,title:string,score:float,confidence:float}>}> By touching share, descending.
 */
function sn_jev_tags_by_tag( $data ) {
	$tags = array();
	foreach ( (array) ( $data['notes'] ?? array() ) as $pid => $n ) {
		foreach ( (array) ( $n['attached'] ?? array() ) as $t ) {
			$id = (int) ( $t['id'] ?? 0 );
			if ( ! isset( $tags[ $id ] ) ) {
				$tags[ $id ] = array( 'id' => $id, 'name' => (string) ( $t['name'] ?? '' ), 'notes' => 0, 'sum' => 0.0, 'touching' => array() );
			}
			$score = (float) ( $t['score'] ?? 0 );
			++$tags[ $id ]['notes'];
			$tags[ $id ]['sum'] += $score;
			if ( $score < SN_JEV_TAG_ABOUT_AT ) {
				$tags[ $id ]['touching'][] = array( 'post_id' => (int) $pid, 'title' => (string) ( $n['title'] ?? '' ), 'score' => round( $score, 2 ), 'confidence' => round( (float) ( $t['confidence'] ?? 0 ), 2 ) );
			}
		}
	}
	$out = array();
	foreach ( $tags as $t ) {
		usort( $t['touching'], static fn( $a, $b ) => $a['score'] <=> $b['score'] );
		$out[] = array( 'id' => $t['id'], 'name' => $t['name'], 'notes' => $t['notes'], 'mean' => round( $t['sum'] / max( 1, $t['notes'] ), 2 ), 'touching' => $t['touching'] );
	}
	usort( $out, static function ( $a, $b ) {
		$sa = count( $a['touching'] ) / max( 1, $a['notes'] );
		$sb = count( $b['touching'] ) / max( 1, $b['notes'] );
		return $sb <=> $sa ?: $b['notes'] <=> $a['notes'] ?: strcmp( $a['name'], $b['name'] );
	} );
	return $out;
}

/**
 * Drop removed (post, term) pairs from the stored pass so the leaf does not
 * re-list what the owner just did; the next pass re-reads everything.
 *
 * @param array<int,int[]> $removed post id => term ids removed.
 */
function sn_jev_tags_forget( array $removed ) {
	$data = sn_jev_tags_data();
	if ( null === $data ) {
		return;
	}
	foreach ( $removed as $pid => $ids ) {
		if ( ! isset( $data['notes'][ $pid ]['attached'] ) ) {
			continue;
		}
		$data['notes'][ $pid ]['attached'] = array_values( array_filter( (array) $data['notes'][ $pid ]['attached'], static function ( $t ) use ( $ids ) {
			return ! in_array( (int) ( $t['id'] ?? 0 ), array_map( 'intval', (array) $ids ), true );
		} ) );
	}
	update_option( SN_JEV_TAGS_OPTION, $data, false );
}

/** The stored pass, or null. */
function sn_jev_tags_data() {
	$d = get_option( SN_JEV_TAGS_OPTION, null );
	return is_array( $d ) && isset( $d['notes'] ) ? $d : null;
}

add_action( SN_JEV_TAGS_HOOK, 'sn_jev_tags_sync' );
add_action( 'init', function () {
	if ( function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready() && ! wp_next_scheduled( SN_JEV_TAGS_HOOK ) ) {
		wp_schedule_event( time() + 2700, 'weekly', SN_JEV_TAGS_HOOK );
	}
}, 20 );
