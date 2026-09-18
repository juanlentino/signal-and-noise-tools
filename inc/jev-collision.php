<?php
/**
 * Signal & Noise Tools — the collision gate: does this draft make an
 * argument a published note already makes?
 *
 * The provenance-voice rule: notes are evergreen and never edited after
 * publication, so two notes in the same lane is a permanent problem with no
 * remedy; "complement, no overlap", and the queue is read at drafting time.
 * Until now that rule lived in an agent's memory. Now the site asks Jev.
 *
 * One request per draft: the draft (title, description, the opening) as
 * state beside every published note's title and description, and one Noul
 * per note answered in parallel. Runs when a draft, pending or scheduled
 * note is saved and its state has changed (a hash, so an autosave that moved
 * nothing costs nothing), stores the reading on the post as JSON meta the
 * editor reads through the REST meta it already reads, and the pre-publish
 * panel lists the notes over the line. A collision is a probability at or
 * above 0.5 (the docs' "do not act below" line; here acting means telling
 * the author, which is exactly what a low reading calls for).
 *
 * @since 16.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_COLLISION_META    = '_sn_jev_collision';
const SN_JEV_COLLISION_LINE    = 0.5; // the record's count and the lane map; the panel warns at 0.6 (assets/pre-publish-gate.js)
const SN_JEV_COLLISION_OPENING = 1200;
const SN_JEV_COLLISION_KEEP    = 5;

/**
 * The corpus a draft is judged against: every published note but itself,
 * as {id: {title, description}}.
 *
 * @since 16.4.0
 */
function sn_jev_collision_corpus( $except_id = 0 ) {
	$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ) );
	$out = array();
	foreach ( (array) $ids as $id ) {
		$id = (int) $id;
		if ( $id === (int) $except_id ) {
			continue;
		}
		$p = get_post( $id );
		if ( ! $p ) {
			continue;
		}
		$plain = static function ( $s ) {
			return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
		};
		$out[ $id ] = array(
			'title'       => $plain( $p->post_title ),
			'description' => function_exists( 'sn_seo_resolve_singular_description' ) ? $plain( sn_seo_resolve_singular_description( $p ) ) : $plain( $p->post_excerpt ),
		);
	}
	return $out;
}

/**
 * The state: the draft and the corpus. PURE given both.
 *
 * @since 16.4.0
 */
function sn_jev_collision_state( $post, array $corpus ) {
	$plain = static function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$content = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( (string) ( $post->post_content ?? '' ) ) : (string) ( $post->post_content ?? '' );
	$content = preg_replace( '/<!--.*?-->/s', '', $content );
	$opening = $plain( $content );
	$opening = function_exists( 'mb_substr' ) ? mb_substr( $opening, 0, SN_JEV_COLLISION_OPENING, 'UTF-8' ) : substr( $opening, 0, SN_JEV_COLLISION_OPENING );
	$notes   = array();
	foreach ( $corpus as $id => $n ) {
		$notes[ 'n' . (int) $id ] = array( 'title' => (string) $n['title'], 'description' => (string) $n['description'] );
	}
	return array(
		'draft' => array(
			'title'       => $plain( $post->post_title ?? '' ),
			'description' => function_exists( 'sn_seo_resolve_singular_description' ) ? $plain( sn_seo_resolve_singular_description( $post ) ) : $plain( $post->post_excerpt ?? '' ),
			'opening'     => $opening,
		),
		'notes' => $notes,
	);
}

/**
 * One Noul per note. PURE.
 *
 * @since 16.4.0
 */
function sn_jev_collision_questions( array $corpus ) {
	$q = array();
	foreach ( $corpus as $id => $n ) {
		$key       = 'n' . (int) $id;
		$q[ $key ] = array(
			'type'         => 'noul',
			'instructions' => 'Does `draft` make the same central argument that `notes.' . $key . '` makes, so that a reader who had read that note would learn nothing new from `draft`?',
			'criteria'     => array(
				'true'  => array( 'what' => 'The same central claim, even in different words or with different examples; the two would compete for the same reader and the same search.', 'examples' => array( 'Both argue that detection cannot replace provenance because a score is not a signature.' ) ),
				'false' => array( 'what' => 'The same topic but a different claim, a different angle, or a step the other note leaves open; a reader of one still learns from the other.', 'examples' => array( 'One argues detection fails on cost, the other that it fails on falsifiability.' ) ),
			),
		);
	}
	return $q;
}

/**
 * Rank the answers. PURE. Rows sorted by probability, the collisions being
 * those at or above the line; at most SN_JEV_COLLISION_KEEP rows are kept.
 *
 * @since 16.4.0
 * @return array{rows:array<int,array{id:int,title:string,noul:float}>,collisions:int}
 */
function sn_jev_collision_judge( array $answers, array $corpus ) {
	$rows = array();
	foreach ( $answers as $key => $a ) {
		if ( 'noul' !== (string) ( $a['type'] ?? '' ) || 0 !== strpos( (string) $key, 'n' ) ) {
			continue;
		}
		$id = (int) substr( (string) $key, 1 );
		if ( ! isset( $corpus[ $id ] ) ) {
			continue;
		}
		$rows[] = array( 'id' => $id, 'title' => (string) $corpus[ $id ]['title'], 'noul' => round( (float) $a['noul'], 2 ) );
	}
	usort( $rows, static function ( $x, $y ) {
		return $y['noul'] <=> $x['noul'];
	} );
	$collisions = 0;
	foreach ( $rows as $r ) {
		if ( $r['noul'] >= SN_JEV_COLLISION_LINE ) {
			$collisions++;
		}
	}
	return array( 'rows' => array_slice( $rows, 0, SN_JEV_COLLISION_KEEP ), 'collisions' => $collisions );
}

/**
 * Judge one post now and store the reading on it. Returns the stored
 * record or {error}. Skips the request when the state hash is unchanged.
 *
 * @since 16.4.0
 */
function sn_jev_collision_check( $post_id, $force = false ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return array( 'error' => 'not-a-note' );
	}
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return array( 'error' => 'no-key' );
	}
	$corpus = sn_jev_collision_corpus( (int) $post_id );
	if ( array() === $corpus ) {
		return array( 'error' => 'no-corpus' );
	}
	$state = sn_jev_collision_state( $post, $corpus );
	$hash  = md5( wp_json_encode( $state ) );
	$prev  = json_decode( (string) get_post_meta( (int) $post_id, SN_JEV_COLLISION_META, true ), true );
	if ( ! $force && is_array( $prev ) && ( $prev['hash'] ?? '' ) === $hash ) {
		return $prev;
	}
	$r = sn_jev_ask( $state, sn_jev_collision_questions( $corpus ) );
	if ( ! $r['ok'] ) {
		$record = array_merge( is_array( $prev ) ? $prev : array( 'rows' => array(), 'collisions' => 0, 'at' => 0, 'hash' => '' ), array( 'error' => gmdate( 'c' ) . ' ' . $r['error'] ) );
		update_post_meta( (int) $post_id, SN_JEV_COLLISION_META, wp_json_encode( $record ) );
		return $record;
	}
	$j      = sn_jev_collision_judge( $r['answers'], $corpus );
	$record = array( 'rows' => $j['rows'], 'collisions' => $j['collisions'], 'against' => count( $corpus ), 'at' => time(), 'hash' => $hash, 'error' => '', 'input_tokens' => (int) ( $r['usage']['input_tokens'] ?? 0 ) );
	update_post_meta( (int) $post_id, SN_JEV_COLLISION_META, wp_json_encode( $record ) );
	return $record;
}

/**
 * On save of an unpublished note. `wp_after_insert_post` fires once the
 * post and its terms are written; a published note is not judged again (a
 * note never changes after publication).
 */
add_action( 'wp_after_insert_post', function ( $post_id, $post ) {
	if ( ! $post || 'post' !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) ) {
		return;
	}
	if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
		return;
	}
	sn_jev_collision_check( (int) $post_id );
}, 20, 2 );

/** The reading as the editor sees it: registered meta, JSON string, read-only from the client. */
add_action( 'init', function () {
	if ( ! function_exists( 'register_post_meta' ) ) {
		return;
	}
	register_post_meta( 'post', SN_JEV_COLLISION_META, array(
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'default'           => '',
		'sanitize_callback' => 'sanitize_textarea_field',
		'auth_callback'     => static function () {
			return current_user_can( 'edit_posts' );
		},
	) );
} );

/**
 * The lane map: every published note judged against the others, one
 * request per note, stored in one option. Pairs at or above the line are
 * lanes already shared; the report is read through `jev-lanes`.
 *
 * @since 16.4.0
 * @return array{ok:bool,judged:int,failed:int,pairs:int,error:string}
 */
const SN_JEV_LANES_OPTION = 'sn_jev_lanes';

function sn_jev_lane_map() {
	if ( ! function_exists( 'sn_jev_is_ready' ) || ! sn_jev_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'pairs' => 0, 'error' => 'no-key' );
	}
	$all    = sn_jev_collision_corpus( 0 );
	$pairs  = array();
	$judged = 0;
	$failed = 0;
	$tokens = 0;
	$err    = '';
	foreach ( array_keys( $all ) as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$corpus = $all;
		unset( $corpus[ $id ] );
		$r = sn_jev_ask( sn_jev_collision_state( $post, $corpus ), sn_jev_collision_questions( $corpus ) );
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
		foreach ( sn_jev_collision_judge( $r['answers'], $corpus )['rows'] as $row ) {
			if ( $row['noul'] < SN_JEV_COLLISION_LINE ) {
				continue;
			}
			// One row per unordered pair, keeping the higher of the two readings.
			$key = min( $id, $row['id'] ) . '-' . max( $id, $row['id'] );
			if ( ! isset( $pairs[ $key ] ) || $pairs[ $key ]['noul'] < $row['noul'] ) {
				$pairs[ $key ] = array( 'a' => min( $id, $row['id'] ), 'b' => max( $id, $row['id'] ), 'a_title' => (string) $all[ min( $id, $row['id'] ) ]['title'], 'b_title' => (string) $all[ max( $id, $row['id'] ) ]['title'], 'noul' => $row['noul'] );
			}
		}
	}
	usort( $pairs, static function ( $x, $y ) {
		return $y['noul'] <=> $x['noul'];
	} );
	update_option( SN_JEV_LANES_OPTION, array( 'at' => time(), 'judged' => $judged, 'failed' => $failed, 'pairs' => array_values( $pairs ), 'input_tokens' => $tokens, 'error' => $err ), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'pairs' => count( $pairs ), 'error' => $err );
}

/** The stored map, or null. */
function sn_jev_lanes() {
	$d = get_option( SN_JEV_LANES_OPTION, null );
	return is_array( $d ) && isset( $d['at'] ) ? $d : null;
}
