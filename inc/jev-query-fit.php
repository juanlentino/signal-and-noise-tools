<?php
/**
 * Signal & Noise Tools — query-to-page fit (16.5.0).
 *
 * Search Console already says which queries land on which note. Jev is
 * asked the question the impressions cannot answer: does the note answer
 * that query, or did the query land here on vocabulary? One request per
 * note with queries in the window, one Score (0..2) per query. Two lists
 * come out: the gaps (real impressions, the note does not answer) are the
 * raw material for the next note; the stray traffic (level zero with
 * clicks) names the titles that are chasing the wrong query.
 *
 * Nothing here writes to a note. The reading is a list for the owner.
 *
 * @since 16.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_JEV_FIT_OPTION          = 'sn_jev_query_fit';
const SN_JEV_FIT_HOOK            = 'sn_jev_fit_weekly';
const SN_JEV_FIT_MIN_IMPRESSIONS = 20;
const SN_JEV_FIT_QUERIES_PER_NOTE = 8;
const SN_JEV_FIT_ROW_LIMIT       = 2000;
const SN_JEV_FIT_OPENING         = 1200;
const SN_JEV_FIT_GAP_BELOW       = 1.0; // the note does not reach "touches it"
const SN_JEV_FIT_STRAY_BELOW     = 0.5; // level zero, near enough

/** Ready when Jev has a key and Search Console has a property. */
function sn_jev_fit_is_ready() {
	return function_exists( 'sn_jev_is_ready' ) && sn_jev_is_ready() && function_exists( 'snt_gsc_sync_is_ready' ) && snt_gsc_sync_is_ready();
}

/**
 * Group page × query rows by note. PURE given the rows and the path map.
 *
 * @param array<int,array{keys:array<int,string>,clicks:int,impressions:int}> $rows
 * @param array<string,int> $path_to_id
 * @return array<int,array<int,array{query:string,clicks:int,impressions:int}>> top queries per note by impressions
 */
function sn_jev_fit_group( array $rows, array $path_to_id ) {
	$by = array();
	foreach ( $rows as $r ) {
		$keys = (array) ( $r['keys'] ?? array() );
		if ( count( $keys ) < 2 || (int) ( $r['impressions'] ?? 0 ) < SN_JEV_FIT_MIN_IMPRESSIONS ) {
			continue;
		}
		$path = snt_gsc_url_to_path( (string) $keys[0] );
		if ( ! isset( $path_to_id[ $path ] ) ) {
			continue;
		}
		$by[ $path_to_id[ $path ] ][] = array( 'query' => trim( (string) $keys[1] ), 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'] );
	}
	foreach ( $by as $id => $list ) {
		usort( $list, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'] ?: $b['clicks'] <=> $a['clicks'];
		} );
		$by[ $id ] = array_slice( $list, 0, SN_JEV_FIT_QUERIES_PER_NOTE );
	}
	return $by;
}

/** The published notes keyed by their Search Console path. */
function sn_jev_fit_path_map() {
	$map = array();
	foreach ( sn_jev_note_ids() as $id ) {
		$map[ snt_gsc_url_to_path( get_permalink( $id ) ) ] = (int) $id;
	}
	return $map;
}

/** The state: the note and its queries keyed qN. PURE. */
function sn_jev_fit_state( $post, array $queries ) {
	$plain = static function ( $s ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$content = preg_replace( '/<!--.*?-->/s', '', strip_shortcodes( (string) ( $post->post_content ?? '' ) ) );
	$opening = $plain( $content );
	$opening = function_exists( 'mb_substr' ) ? mb_substr( $opening, 0, SN_JEV_FIT_OPENING, 'UTF-8' ) : substr( $opening, 0, SN_JEV_FIT_OPENING );
	$q       = array();
	foreach ( array_values( $queries ) as $i => $row ) {
		$q[ 'q' . ( $i + 1 ) ] = (string) $row['query'];
	}
	return array(
		'note'    => array(
			'title'       => $plain( $post->post_title ?? '' ),
			'description' => function_exists( 'sn_seo_resolve_singular_description' ) ? $plain( sn_seo_resolve_singular_description( $post ) ) : $plain( $post->post_excerpt ?? '' ),
			'opening'     => $opening,
		),
		'queries' => $q,
	);
}

/** One Score per query, three levels. PURE. */
function sn_jev_fit_questions( array $queries ) {
	$out = array();
	foreach ( array_values( $queries ) as $i => $_ ) {
		$k         = 'q' . ( $i + 1 );
		$out[ $k ] = array(
			'type'         => 'score',
			'instructions' => array(
				'question' => 'Does `note` answer `queries.' . $k . '`, the words a person typed into a search engine before landing on it?',
				'note'     => 'Judge from `note.title`, `note.description` and `note.opening` only. A note can be good and still not answer this query.',
			),
			'criteria'     => array(
				array( 'summary' => 'It does not answer it; the query landed here on shared vocabulary', 'signals' => array( 'The query asks for something the note never addresses', 'A word overlaps, the need does not' ) ),
				array( 'summary' => 'It touches it; the reader would have to dig or infer', 'signals' => array( 'The subject is the same but the question is answered in passing', 'The reader gets part of what they came for' ) ),
				array( 'summary' => 'It answers it directly', 'signals' => array( 'The note\'s argument is the answer to the query', 'A reader with that query would stop here' ) ),
			),
		);
	}
	return $out;
}

/** The rows: query, counts, score, confidence. PURE. */
function sn_jev_fit_judge( array $answers, array $queries ) {
	$rows = array();
	foreach ( array_values( $queries ) as $i => $row ) {
		$a = $answers[ 'q' . ( $i + 1 ) ] ?? null;
		if ( ! is_array( $a ) || 'score' !== (string) ( $a['type'] ?? '' ) ) {
			continue;
		}
		$rows[] = array( 'query' => (string) $row['query'], 'clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'score' => round( (float) $a['score'], 2 ), 'confidence' => round( (float) ( $a['confidence'] ?? 0 ), 2 ) );
	}
	return $rows;
}

/**
 * The pass: one Search Console read (page × query), one Jev request per
 * note with queries, one option. Weekly.
 *
 * @return array{ok:bool,judged:int,failed:int,queries:int,error:string}
 */
function sn_jev_fit_sync() {
	if ( ! sn_jev_fit_is_ready() ) {
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'queries' => 0, 'error' => 'not-ready' );
	}
	$rows = snt_gsc_query( (string) sn_setting( 'search_console.property', '' ), array( 'page', 'query' ), snt_gsc_window(), SN_JEV_FIT_ROW_LIMIT );
	if ( is_wp_error( $rows ) ) {
		$prev = sn_jev_fit_data();
		update_option( SN_JEV_FIT_OPTION, array_merge( is_array( $prev ) ? $prev : array( 'synced_at' => 0, 'notes' => array() ), array( 'last_error' => gmdate( 'c' ) . ' ' . $rows->get_error_message() ) ), false );
		return array( 'ok' => false, 'judged' => 0, 'failed' => 0, 'queries' => 0, 'error' => $rows->get_error_message() );
	}
	$by     = sn_jev_fit_group( $rows, sn_jev_fit_path_map() );
	$notes  = array();
	$judged = 0;
	$failed = 0;
	$tokens = 0;
	$nq     = 0;
	$err    = '';
	foreach ( $by as $id => $queries ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$r = sn_jev_ask( sn_jev_fit_state( $post, $queries ), sn_jev_fit_questions( $queries ) );
		if ( ! $r['ok'] ) {
			$failed++;
			$err = (string) $r['error'];
			if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
				break;
			}
			continue;
		}
		$judged++;
		$tokens      += (int) ( $r['usage']['input_tokens'] ?? 0 );
		$fit          = sn_jev_fit_judge( $r['answers'], $queries );
		$nq          += count( $fit );
		$notes[ $id ] = array( 'title' => (string) $post->post_title, 'path' => snt_gsc_url_to_path( get_permalink( $id ) ), 'rows' => $fit );
	}
	update_option( SN_JEV_FIT_OPTION, array(
		'synced_at'  => time(),
		'window'     => snt_gsc_window(),
		'notes'      => $notes,
		'usage'      => array( 'requests' => $judged + $failed, 'input_tokens' => $tokens ),
		'last_error' => '' !== $err ? gmdate( 'c' ) . ' ' . $err : '',
	), false );
	return array( 'ok' => 0 === $failed, 'judged' => $judged, 'failed' => $failed, 'queries' => $nq, 'error' => $err );
}

/** The stored pass, or null. */
function sn_jev_fit_data() {
	$d = get_option( SN_JEV_FIT_OPTION, null );
	return is_array( $d ) && isset( $d['notes'] ) ? $d : null;
}

/**
 * The two lists. PURE given the stored pass.
 *
 * @return array{gaps:array,stray:array}
 */
function sn_jev_fit_readings( $data ) {
	$gaps  = array();
	$stray = array();
	foreach ( (array) ( $data['notes'] ?? array() ) as $id => $n ) {
		foreach ( (array) ( $n['rows'] ?? array() ) as $r ) {
			$row = array_merge( $r, array( 'id' => (int) $id, 'note' => (string) $n['title'], 'path' => (string) ( $n['path'] ?? '' ) ) );
			if ( $r['score'] < SN_JEV_FIT_GAP_BELOW ) {
				$gaps[] = $row;
			}
			if ( $r['score'] < SN_JEV_FIT_STRAY_BELOW && $r['clicks'] > 0 ) {
				$stray[] = $row;
			}
		}
	}
	$by_imp = static function ( $a, $b ) {
		return $b['impressions'] <=> $a['impressions'] ?: $a['score'] <=> $b['score'];
	};
	usort( $gaps, $by_imp );
	usort( $stray, static function ( $a, $b ) {
		return $b['clicks'] <=> $a['clicks'];
	} );
	return array( 'gaps' => $gaps, 'stray' => $stray );
}

add_action( SN_JEV_FIT_HOOK, 'sn_jev_fit_sync' );
add_action( 'init', function () {
	if ( sn_jev_fit_is_ready() && ! wp_next_scheduled( SN_JEV_FIT_HOOK ) ) {
		wp_schedule_event( time() + 1800, 'weekly', SN_JEV_FIT_HOOK );
	}
}, 20 );
