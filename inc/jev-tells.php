<?php
/**
 * Signal & Noise Tools — the anti-tell pass (16.7.0).
 *
 * The voice playbook bans a short list of constructions because they read
 * as generated prose: em dashes, tricolons built for rhythm, anaphora,
 * the symmetric "It is not X. It is Y." pair, "not just X but Y", hedge
 * clusters, "quietly" as an intensifier, three same-shaped sentences in a
 * row, and a closer that restates the thesis. The ones a regex can see are
 * counted here without a model. The ones that need a reading (is this
 * three-part list a rhythm or three things?) go to Jev, one request per
 * note with three Nouls per paragraph and one for the closer. On every
 * save of an unpublished note; the reading is stored on the post for the
 * pre-publish panel. A corpus pass over the published notes is on demand
 * and is a reading only: notes are never edited after publication.
 *
 * @since 16.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_TELLS_META      = '_sn_jev_tells';
const SN_JEV_TELLS_OPTION    = 'sn_jev_tells';
const SN_JEV_TELLS_LINE      = 0.6;
const SN_JEV_TELLS_MAX_PARAS = 24;
const SN_JEV_TELLS_MIN_CHARS = 40;

/**
 * The paragraphs of a note as plain text. PURE. Block markup in, one
 * string per <p> out; headings, lists and quotes are not judged.
 *
 * @return array<int,string>
 */
function sn_jev_tells_paragraphs( $content ) {
	$content = preg_replace( '/<!--.*?-->/s', '', (string) $content );
	if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
		return array();
	}
	$out = array();
	foreach ( $m[1] as $raw ) {
		$t   = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' ) ) );
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $t, 'UTF-8' ) : strlen( $t );
		if ( $len >= SN_JEV_TELLS_MIN_CHARS ) {
			$out[] = $t;
		}
		if ( count( $out ) >= SN_JEV_TELLS_MAX_PARAS ) {
			break;
		}
	}
	return $out;
}

/**
 * The tells a regex can see, over the whole note. PURE. No model.
 *
 * @return array<int,array{tell:string,count:int}>
 */
function sn_jev_tells_deterministic( array $paragraphs ) {
	$rows  = array();
	$count = static function ( $tell, $re ) use ( &$rows, $paragraphs ) {
		$n = 0;
		foreach ( $paragraphs as $p ) {
			$n += preg_match_all( $re, $p );
		}
		if ( $n > 0 ) {
			$rows[] = array( 'tell' => $tell, 'count' => $n );
		}
	};
	$count( 'em_dash', '/\x{2014}/u' );
	$count( 'quietly', '/\bquietly\b/iu' );
	$count( 'not_just', '/\bnot (?:just|only|merely)\b.{1,80}?\bbut\b/iu' );
	$count( 'hedge_cluster', '/(?:\b(?:could|might|may|perhaps|potentially|possibly|seems?|arguably)\b[^.!?]{0,40}){2,}/iu' );
	// Uniform rhythm: three consecutive sentences within 15% of one another's length.
	$uniform = 0;
	foreach ( $paragraphs as $p ) {
		$s = preg_split( '/(?<=[.!?])\s+/u', $p );
		$l = array_values( array_filter( array_map( 'strlen', (array) $s ) ) );
		for ( $i = 2; $i < count( $l ); $i++ ) {
			$max = max( $l[ $i ], $l[ $i - 1 ], $l[ $i - 2 ] );
			$min = min( $l[ $i ], $l[ $i - 1 ], $l[ $i - 2 ] );
			if ( $min >= 40 && ( $max - $min ) / $max <= 0.15 ) {
				$uniform++;
				break;
			}
		}
	}
	if ( $uniform > 0 ) {
		$rows[] = array( 'tell' => 'uniform_rhythm', 'count' => $uniform );
	}
	return $rows;
}

/** The state: paragraphs keyed pN, the last named. PURE. */
function sn_jev_tells_state( array $paragraphs ) {
	$p = array();
	foreach ( array_values( $paragraphs ) as $i => $t ) {
		$p[ 'p' . ( $i + 1 ) ] = $t;
	}
	return array( 'paragraphs' => $p, 'last' => 'p' . count( $paragraphs ) );
}

/** Three Nouls per paragraph and one for the closer. PURE. */
function sn_jev_tells_questions( $n ) {
	$q = array();
	for ( $i = 1; $i <= (int) $n; $i++ ) {
		$ref = '`paragraphs.p' . $i . '`';
		$q[ 'p' . $i . '_tricolon' ] = array(
			'type'         => 'noul',
			'instructions' => 'Does ' . $ref . ' contain a three-part list built for rhythm (the beat of three) rather than because there are exactly three things to name?',
			'criteria'     => array(
				'true'  => array( 'what' => 'Three parallel items, phrases or clauses whose number is a cadence, not a count.', 'examples' => array( 'the structure, the rhythm, and the weight' ) ),
				'false' => array( 'what' => 'No three-part series, or three items that are the actual three things (three named parties, three dated events).', 'examples' => array( 'ISRC, ISWC and IPI' ) ),
			),
		);
		$q[ 'p' . $i . '_anaphora' ] = array(
			'type'         => 'noul',
			'instructions' => 'Do two or more consecutive sentences or clauses in ' . $ref . ' open with the same word or phrase for rhetorical effect?',
			'criteria'     => array(
				'true'  => array( 'what' => 'Repeated openings that build a cadence.', 'examples' => array( 'It signs the file. It signs the claim. It signs nothing else.' ) ),
				'false' => array( 'what' => 'Openings vary, or a repeat is grammatical necessity (the same subject in two plain sentences).', 'examples' => array( 'The label filed the claim. The distributor paid it.' ) ),
			),
		);
		$q[ 'p' . $i . '_symmetric' ] = array(
			'type'         => 'noul',
			'instructions' => 'Does ' . $ref . ' use the symmetric pair "It is not X. It is Y." or "not because A but because B" or "this is not X, it is Y" as its point?',
			'criteria'     => array(
				'true'  => array( 'what' => 'A denial followed by its mirror as the point.', 'examples' => array( 'This is not a technical problem. It is a trust problem.' ) ),
				'false' => array( 'what' => 'A plain negation with no mirror, or a contrast stated once without the two-beat shape.', 'examples' => array( 'The score is not a signature; a signature can be checked by anyone.' ) ),
			),
		);
	}
	if ( (int) $n > 0 ) {
		$q['closer'] = array(
			'type'         => 'noul',
			'instructions' => 'Does `paragraphs.p' . (int) $n . '` (the last paragraph) end by restating the piece\'s thesis in elevated language, rather than on a substantive point, an open question or a piece of evidence?',
			'criteria'     => array(
				'true'  => array( 'what' => 'A summarising flourish that adds nothing the piece did not already argue.', 'examples' => array( 'In the end, provenance is a foundation, and foundations are what last.' ) ),
				'false' => array( 'what' => 'The last sentence carries a new fact, a concrete consequence, or a question left open.', 'examples' => array( 'The next release will say which of the two the platform chose.' ) ),
			),
		);
	}
	return $q;
}

/**
 * The rows at or above the line. PURE.
 *
 * @return array<int,array{p:int,tell:string,noul:float,excerpt:string}>
 */
function sn_jev_tells_judge( array $answers, array $paragraphs ) {
	$rows = array();
	foreach ( $answers as $key => $a ) {
		if ( 'noul' !== (string) ( $a['type'] ?? '' ) ) {
			continue;
		}
		$noul = round( (float) $a['noul'], 2 );
		if ( $noul < SN_JEV_TELLS_LINE ) {
			continue;
		}
		if ( 'closer' === $key ) {
			$p    = count( $paragraphs );
			$tell = 'closer';
		} elseif ( preg_match( '/^p(\d+)_(tricolon|anaphora|symmetric)$/', (string) $key, $m ) ) {
			$p    = (int) $m[1];
			$tell = $m[2];
		} else {
			continue;
		}
		if ( $p < 1 || $p > count( $paragraphs ) ) {
			continue; // an answer for a paragraph that was not sent
		}
		$text   = (string) ( $paragraphs[ $p - 1 ] ?? '' );
		$rows[] = array( 'p' => $p, 'tell' => $tell, 'noul' => $noul, 'excerpt' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 80, 'UTF-8' ) : substr( $text, 0, 80 ) );
	}
	usort( $rows, static function ( $x, $y ) {
		return $x['p'] <=> $y['p'] ?: $y['noul'] <=> $x['noul'];
	} );
	return $rows;
}

/**
 * Judge one note now and store the reading on it. Skips the request when
 * the paragraphs have not changed; the deterministic tells are recomputed
 * every time (they cost nothing).
 */
function sn_jev_tells_check( $post_id, $force = false ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return array( 'error' => 'not-a-note' );
	}
	$paragraphs = sn_jev_tells_paragraphs( $post->post_content );
	$det        = sn_jev_tells_deterministic( $paragraphs );
	$hash       = md5( wp_json_encode( $paragraphs ) );
	$prev       = json_decode( (string) get_post_meta( (int) $post_id, SN_JEV_TELLS_META, true ), true );
	$store      = static function ( array $record ) use ( $post_id ) {
		update_post_meta( (int) $post_id, SN_JEV_TELLS_META, wp_json_encode( $record ) );
		return $record;
	};
	if ( array() === $paragraphs ) {
		return $store( array( 'rows' => array(), 'deterministic' => $det, 'paragraphs' => 0, 'at' => time(), 'hash' => $hash, 'error' => '' ) );
	}
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return $store( array( 'rows' => array(), 'deterministic' => $det, 'paragraphs' => count( $paragraphs ), 'at' => time(), 'hash' => '', 'error' => 'no-key' ) );
	}
	if ( ! $force && is_array( $prev ) && ( $prev['hash'] ?? '' ) === $hash ) {
		$prev['deterministic'] = $det;
		return $prev;
	}
	$r = sn_jev_ask( sn_jev_tells_state( $paragraphs ), sn_jev_tells_questions( count( $paragraphs ) ), 'tells' );
	if ( ! $r['ok'] ) {
		return $store( array_merge( is_array( $prev ) ? $prev : array( 'rows' => array(), 'at' => 0, 'hash' => '' ), array( 'deterministic' => $det, 'paragraphs' => count( $paragraphs ), 'error' => gmdate( 'c' ) . ' ' . $r['error'] ) ) );
	}
	return $store( array( 'rows' => sn_jev_tells_judge( $r['answers'], $paragraphs ), 'deterministic' => $det, 'paragraphs' => count( $paragraphs ), 'at' => time(), 'hash' => $hash, 'error' => '', 'input_tokens' => (int) ( $r['usage']['input_tokens'] ?? 0 ) ) );
}

/** On save of an unpublished note, after the collision gate. */
add_action( 'wp_after_insert_post', function ( $post_id, $post ) {
	if ( ! $post || 'post' !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) ) {
		return;
	}
	if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
		return;
	}
	sn_jev_tells_check( (int) $post_id );
}, 21, 2 );

add_action( 'init', function () {
	if ( ! function_exists( 'register_post_meta' ) ) {
		return;
	}
	register_post_meta( 'post', SN_JEV_TELLS_META, array(
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'auth_callback'     => static function () {
			return current_user_can( 'edit_posts' );
		},
	) );
} );

/**
 * The corpus pass: every published note, one request each, one option. A
 * reading, never a remedy: published notes are not edited.
 *
 * @return array{ok:bool,judged:int,failed:int,flagged:int,error:string}
 */
function sn_jev_tells_pass() {
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'flagged' => 0, 'error' => 'no-key' );
	}
	$ids    = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ) );
	$notes  = array();
	$judged = 0;
	$failed = 0;
	$tokens = 0;
	$flag   = 0;
	$err    = '';
	foreach ( (array) $ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			continue;
		}
		$paragraphs = sn_jev_tells_paragraphs( $post->post_content );
		if ( array() === $paragraphs ) {
			continue;
		}
		$det = sn_jev_tells_deterministic( $paragraphs );
		$r   = sn_jev_ask( sn_jev_tells_state( $paragraphs ), sn_jev_tells_questions( count( $paragraphs ) ), 'tells' );
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
		$rows    = sn_jev_tells_judge( $r['answers'], $paragraphs );
		if ( array() !== $rows || array() !== $det ) {
			$flag++;
		}
		$notes[ (int) $id ] = array( 'title' => (string) $post->post_title, 'paragraphs' => count( $paragraphs ), 'rows' => $rows, 'deterministic' => $det );
	}
	update_option( SN_JEV_TELLS_OPTION, array( 'at' => time(), 'judged' => $judged, 'failed' => $failed, 'notes' => $notes, 'input_tokens' => $tokens, 'error' => $err ), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'flagged' => $flag, 'error' => $err );
}

/** The stored corpus pass, or null. */
function sn_jev_tells_data() {
	$d = get_option( SN_JEV_TELLS_OPTION, null );
	return is_array( $d ) && isset( $d['at'] ) ? $d : null;
}
