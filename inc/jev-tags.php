<?php
/**
 * Signal & Noise Tools — tag fit (16.8.0).
 *
 * Tags are the one editorial field a published note can still change:
 * they are not prose, so a tag edit moves nothing the signature covers.
 * Jev reads each note against its tags and against the tags it does not
 * carry: one request per note, one Score per attached tag (does the note
 * argue what the tag names, or was the tag attached for reach?) and one
 * Noul per candidate tag (would a reader browsing that tag expect this
 * note?). The tag's own description is what Jev reads, so a wrong reading
 * of a right tag is a description to fix. Weekly and on demand; check 31
 * reads the stored pass.
 *
 * @since 16.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_TAGS_OPTION        = 'sn_jev_tags';
const SN_JEV_TAGS_HOOK          = 'sn_jev_tags_weekly';
const SN_JEV_TAG_MISFIT_BELOW   = 1.0; // an attached tag under "touches it"
const SN_JEV_TAG_MISSING_AT     = 0.6; // a candidate tag the pass KEEPS (the vocabulary reading)
// 16.9.1, from the first live pass (69 notes, 216 attached tags): 27 of 40
// misfits sat at 0.5 to 0.99 with confidence 0 to 0.26, shrugs painted as
// verdicts; 171 adds at 0.6 were four umbrella tags suggested on 15 to 25
// notes each. A misfit is a score under the line AT a confidence; a per-note
// add starts at 0.8; a tag Jev would add to a third of the corpus is a
// vocabulary finding, said once, never a row per note.
const SN_JEV_TAG_MISFIT_CONFIDENCE = 0.5;
const SN_JEV_TAG_ADD_AT            = 0.8;
const SN_JEV_TAG_UMBRELLA_SHARE    = 1 / 3;
const SN_JEV_TAGS_CANDIDATES    = 60;  // candidates per note, most-used first
const SN_JEV_TAGS_OPENING       = 1200;

/** Every tag with its description, most used first, keyed by term id. */
function sn_jev_tag_pool() {
	$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC' ) );
	$pool  = array();
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		$pool[ (int) $t->term_id ] = array( 'name' => (string) $t->name, 'description' => trim( (string) $t->description ), 'count' => (int) $t->count );
	}
	return $pool;
}

/** The state: the note and every tag in play, keyed tID. PURE. */
function sn_jev_tags_state( $post, array $attached, array $candidates, array $pool ) {
	$plain = static function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$content = preg_replace( '/<!--.*?-->/s', '', strip_shortcodes( (string) ( $post->post_content ?? '' ) ) );
	$opening = $plain( $content );
	$opening = function_exists( 'mb_substr' ) ? mb_substr( $opening, 0, SN_JEV_TAGS_OPENING, 'UTF-8' ) : substr( $opening, 0, SN_JEV_TAGS_OPENING );
	$tags    = array();
	foreach ( array_merge( $attached, $candidates ) as $id ) {
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

/** One Score per attached tag, one Noul per candidate. PURE. */
function sn_jev_tags_questions( array $attached, array $candidates ) {
	$q = array();
	foreach ( $attached as $id ) {
		$q[ 'a' . (int) $id ] = array(
			'type'         => 'score',
			'instructions' => array(
				'question' => 'Does `note` argue about what `tags.t' . (int) $id . '` names?',
				'note'     => 'Judge from the tag\'s name and description against the note\'s title, description and opening. A tag can be a real subject and still not be this note\'s.',
			),
			'criteria'     => array(
				array( 'summary' => 'The note does not address what the tag names; the tag is attached for reach or by habit', 'signals' => array( 'The tag\'s subject never appears in the argument', 'Only a word overlaps' ) ),
				array( 'summary' => 'The note touches it in passing; a reader browsing the tag would find it beside the point', 'signals' => array( 'Mentioned once, not argued', 'The subject is context, not the claim' ) ),
				array( 'summary' => 'The note argues about it; a reader browsing the tag would want this note', 'signals' => array( 'The claim is about the tag\'s subject', 'The tag\'s description could describe the note' ) ),
			),
		);
	}
	foreach ( $candidates as $id ) {
		$q[ 'c' . (int) $id ] = array(
			'type'         => 'noul',
			'instructions' => 'Would a reader browsing `tags.t' . (int) $id . '` expect to find `note` under it, because the note argues about what the tag names?',
			'criteria'     => array(
				'true'  => array( 'what' => 'The note\'s claim is about the tag\'s subject as its description states it.', 'examples' => array( 'A note arguing that detection scores cannot be verified, under a tag described as "why provenance beats detection".' ) ),
				'false' => array( 'what' => 'The subject is adjacent, mentioned, or absent.', 'examples' => array( 'A note about signing masters, under a tag described as "streaming royalties".' ) ),
			),
		);
	}
	return $q;
}

/**
 * The rows. PURE.
 *
 * @return array{attached:array,missing:array}
 */
function sn_jev_tags_judge( array $answers, array $attached, array $candidates, array $pool ) {
	$rows = array( 'attached' => array(), 'missing' => array() );
	foreach ( $attached as $id ) {
		$a = $answers[ 'a' . (int) $id ] ?? null;
		if ( ! is_array( $a ) || 'score' !== (string) ( $a['type'] ?? '' ) || ! isset( $pool[ $id ] ) ) {
			continue;
		}
		$rows['attached'][] = array( 'id' => (int) $id, 'name' => $pool[ $id ]['name'], 'score' => round( (float) $a['score'], 2 ), 'confidence' => round( (float) ( $a['confidence'] ?? 0 ), 2 ) );
	}
	foreach ( $candidates as $id ) {
		$a = $answers[ 'c' . (int) $id ] ?? null;
		if ( ! is_array( $a ) || 'noul' !== (string) ( $a['type'] ?? '' ) || ! isset( $pool[ $id ] ) ) {
			continue;
		}
		$noul = round( (float) $a['noul'], 2 );
		if ( $noul >= SN_JEV_TAG_MISSING_AT ) {
			$rows['missing'][] = array( 'id' => (int) $id, 'name' => $pool[ $id ]['name'], 'noul' => $noul );
		}
	}
	usort( $rows['missing'], static function ( $x, $y ) {
		return $y['noul'] <=> $x['noul'];
	} );
	return $rows;
}

/**
 * An attached tag Jev read as attached for reach: under the line, and read
 * with enough confidence to be a verdict rather than a shrug.
 *
 * @param array $t A stored attached row {score, confidence}.
 * @return bool
 */
function sn_jev_tag_is_misfit( $t ) {
	return (float) ( $t['score'] ?? 2 ) < SN_JEV_TAG_MISFIT_BELOW && (float) ( $t['confidence'] ?? 0 ) >= SN_JEV_TAG_MISFIT_CONFIDENCE;
}

/**
 * A missing tag worth a row on the note: the pass keeps candidates from
 * SN_JEV_TAG_MISSING_AT, the note lists them from SN_JEV_TAG_ADD_AT.
 *
 * @param array $t A stored missing row {noul}.
 * @return bool
 */
function sn_jev_tag_is_add( $t ) {
	return (float) ( $t['noul'] ?? 0 ) >= SN_JEV_TAG_ADD_AT;
}

/**
 * Umbrella tags: the ones Jev would add to SN_JEV_TAG_UMBRELLA_SHARE of the
 * notes read or more, at the stored line. A tag that fits a third of the
 * corpus is a category, or a description to narrow; it is one finding, not
 * twenty-five rows. PURE given the stored pass.
 *
 * @return array<int,array{id:int,name:string,suggested:int,attached:int,notes:int}> By suggested, descending.
 */
function sn_jev_tags_umbrellas( $data ) {
	$notes = (array) ( $data['notes'] ?? array() );
	$total = count( $notes );
	if ( 0 === $total ) {
		return array();
	}
	$by = array();
	foreach ( $notes as $n ) {
		foreach ( (array) ( $n['missing'] ?? array() ) as $t ) {
			$id = (int) ( $t['id'] ?? 0 );
			$by[ $id ] = ( $by[ $id ] ?? array( 'id' => $id, 'name' => (string) ( $t['name'] ?? '' ), 'suggested' => 0, 'attached' => 0, 'notes' => $total ) );
			++$by[ $id ]['suggested'];
		}
	}
	foreach ( $notes as $n ) {
		foreach ( (array) ( $n['attached'] ?? array() ) as $t ) {
			$id = (int) ( $t['id'] ?? 0 );
			if ( isset( $by[ $id ] ) ) {
				++$by[ $id ]['attached'];
			}
		}
	}
	$out = array_values( array_filter( $by, static function ( $r ) use ( $total ) {
		return $r['suggested'] >= $total * SN_JEV_TAG_UMBRELLA_SHARE;
	} ) );
	usort( $out, static function ( $x, $y ) {
		return $y['suggested'] <=> $x['suggested'] ?: strcmp( $x['name'], $y['name'] );
	} );
	return $out;
}

/**
 * The pass: one request per published or scheduled note, one option.
 *
 * @return array{ok:bool,judged:int,failed:int,misfits:int,missing:int,error:string}
 */
function sn_jev_tags_sync() {
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'misfits' => 0, 'missing' => 0, 'error' => 'no-key' );
	}
	$pool    = sn_jev_tag_pool();
	$notes   = array();
	$judged  = 0;
	$failed  = 0;
	$tokens  = 0;
	$misfits = 0;
	$missing = 0;
	$err     = '';
	foreach ( sn_jev_note_ids() as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			continue;
		}
		$attached = array_values( array_filter( array_map( 'intval', (array) wp_get_post_tags( (int) $id, array( 'fields' => 'ids' ) ) ), static function ( $t ) use ( $pool ) {
			return isset( $pool[ $t ] );
		} ) );
		$candidates = array_slice( array_values( array_diff( array_keys( $pool ), $attached ) ), 0, SN_JEV_TAGS_CANDIDATES );
		if ( array() === $attached && array() === $candidates ) {
			continue;
		}
		$r = sn_jev_ask( sn_jev_tags_state( $post, $attached, $candidates, $pool ), sn_jev_tags_questions( $attached, $candidates ), 'tags' );
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
		$rows    = sn_jev_tags_judge( $r['answers'], $attached, $candidates, $pool );
		foreach ( $rows['attached'] as $row ) {
			if ( sn_jev_tag_is_misfit( $row ) ) {
				$misfits++;
			}
		}
		$missing           += count( array_filter( $rows['missing'], 'sn_jev_tag_is_add' ) );
		$notes[ (int) $id ] = array( 'title' => (string) $post->post_title, 'attached' => $rows['attached'], 'missing' => $rows['missing'] );
	}
	update_option( SN_JEV_TAGS_OPTION, array(
		'synced_at'  => time(),
		'tags'       => count( $pool ),
		'notes'      => $notes,
		'usage'      => array( 'requests' => $judged + $failed, 'input_tokens' => $tokens ),
		'last_error' => '' !== $err ? gmdate( 'c' ) . ' ' . $err : '',
	), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'misfits' => $misfits, 'missing' => $missing, 'error' => $err );
}

/**
 * The rows the Tags leaf paints and the apply handler allows: per flagged
 * note, the attached tags Jev read as attached for reach (score under 1,
 * confidence 0.5 or better) and the tags a reader would expect (0.8+).
 * PURE given the stored pass.
 *
 * @return array<int,array{title:string,remove:array,add:array}>
 */
function sn_jev_tags_rows( $data ) {
	$rows = array();
	foreach ( (array) ( $data['notes'] ?? array() ) as $id => $n ) {
		$remove = array_values( array_filter( (array) ( $n['attached'] ?? array() ), 'sn_jev_tag_is_misfit' ) );
		$add    = array_values( array_filter( (array) ( $n['missing'] ?? array() ), 'sn_jev_tag_is_add' ) );
		if ( array() === $remove && array() === $add ) {
			continue;
		}
		$rows[ (int) $id ] = array( 'title' => (string) ( $n['title'] ?? '' ), 'remove' => $remove, 'add' => $add );
	}
	return $rows;
}

/**
 * Drop applied (post, term) pairs from the stored pass so the leaf does not
 * re-list what the owner just did; the next pass re-reads everything.
 *
 * @param array<int,int[]> $assigned post id => term ids added.
 * @param array<int,int[]> $removed  post id => term ids removed.
 */
function sn_jev_tags_forget( array $assigned, array $removed ) {
	$data = sn_jev_tags_data();
	if ( null === $data ) {
		return;
	}
	foreach ( array( 'missing' => $assigned, 'attached' => $removed ) as $key => $map ) {
		foreach ( $map as $pid => $ids ) {
			if ( ! isset( $data['notes'][ $pid ][ $key ] ) ) {
				continue;
			}
			$data['notes'][ $pid ][ $key ] = array_values( array_filter( (array) $data['notes'][ $pid ][ $key ], static function ( $t ) use ( $ids ) {
				return ! in_array( (int) ( $t['id'] ?? 0 ), array_map( 'intval', (array) $ids ), true );
			} ) );
		}
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
