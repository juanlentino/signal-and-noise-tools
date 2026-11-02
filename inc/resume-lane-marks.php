<?php
/**
 * Signal & Noise Tools: what only one /resume lane holds (edition marks, owner yes 2026-11-02).
 *
 * sn_resume_doc_view() drops the variant flags, so what only one lane holds is
 * read back from the two lane documents by string equality only: credential
 * rows by title and lines, earlier-career entries by organization, roles by
 * title within their organization, a stats band or skills table when every row
 * is lane-only, and the headed bands one lane lacks (each gets a hunk in the
 * other). Never a bullet. Only sn_resume_page_body() calls this, so the PDFs,
 * the JSON Resume, the Markdown and /cv never see an `_only` key.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The headed bands, in the order both lanes lay them out (each h2's anchor slug). */
const SN_RESUME_LANE_BANDS = array( 'built', 'experience', 'credentials', 'publications', 'skills' );

/**
 * The headed bands a lane document fills, in page order. PURE.
 *
 * @param array $doc A lane document (sn_resume_lane_doc()).
 * @return string[]
 */
function sn_resume_lane_band_keys( $doc ) {
	$has = array(
		'built'        => ! empty( $doc['built']['items'] ),
		'experience'   => ! empty( $doc['experience'] ) || ! empty( $doc['earlier']['entries'] ),
		'credentials'  => ! empty( $doc['education'] ) || ! empty( $doc['affiliations'] ),
		'publications' => ! empty( $doc['publications'] ),
		'skills'       => ! empty( $doc['skills'] ),
	);
	return array_keys( array_filter( $has ) );
}

/** Both lane documents, each marked against the other. PURE. @param array $music @param array $ops @return array{0:array,1:array} */
function sn_resume_lane_marks( $music, $ops ) {
	return array( sn_resume_lane_mark( $music, $ops ), sn_resume_lane_mark( $ops, $music ) );
}

/**
 * One lane document with `_only` on each row the other lane document lacks. PURE.
 *
 * @param array $doc   This lane's document.
 * @param array $other The other lane's document.
 * @return array
 */
function sn_resume_lane_mark( $doc, $other ) {
	$only = static function ( $rows, $key, $theirs ) {
		$have = array_map( $key, (array) $theirs );
		return array_map( static fn( $r ) => in_array( $key( $r ), $have, true ) ? $r : $r + array( '_only' => true ), (array) $rows );
	};
	$lined = static fn( $r ) => $r['title'] . "\n" . implode( "\n", $r['lines'] );
	foreach ( array( 'education', 'affiliations' ) as $list ) {
		$doc[ $list ] = $only( $doc[ $list ], $lined, $other[ $list ] );
	}
	$doc['stats']  = $only( $doc['stats'], static fn( $r ) => $r['n'] . "\n" . $r['label'], $other['stats'] );
	$doc['skills'] = $only( $doc['skills'], static fn( $r ) => $r['category'] . "\n" . $r['items'], $other['skills'] );
	// The other lane's role titles, by organization, wherever it lists them.
	$titles = array();
	foreach ( array_merge( $other['experience'], $other['earlier']['entries'] ) as $e ) {
		$titles[ $e['org'] ] = array_merge( $titles[ $e['org'] ] ?? array(), array_column( $e['roles'], 'title' ) );
	}
	$roles = static fn( $e ) => array_merge(
		$e,
		array( 'roles' => array_map( static fn( $r ) => in_array( $r['title'], $titles[ $e['org'] ] ?? array(), true ) ? $r : $r + array( '_only' => true ), $e['roles'] ) )
	);
	$doc['experience'] = array_map( $roles, $doc['experience'] );
	// An earlier-career entry the other lane lacks is marked whole; its roles are not marked again.
	$doc['earlier']['entries'] = array_map( static fn( $e ) => isset( $titles[ $e['org'] ] ) ? $roles( $e ) : $e + array( '_only' => true ), $doc['earlier']['entries'] );
	return $doc;
}

/**
 * Where the bands only the other lane has go in this one: before the next band
 * both share, or at the end. PURE.
 *
 * @param string[] $mine   This lane's band keys, in page order.
 * @param string[] $theirs The other lane's band keys, in page order.
 * @return array<string,string[]> Shared key ('' for the end) => the other lane's keys before it.
 */
function sn_resume_lane_hunks( $mine, $theirs ) {
	$at = array();
	foreach ( array_values( $theirs ) as $i => $key ) {
		if ( ! in_array( $key, $mine, true ) ) {
			$next = current( array_intersect( array_slice( $theirs, $i + 1 ), $mine ) );
			$at[ false === $next ? '' : $next ][] = $key;
		}
	}
	return $at;
}

/**
 * A lane's bands, joined: a hunk band before each shared band the other lane
 * leads into with one of its own, and the formats line as the last block of
 * this lane's last band.
 *
 * @param string                $lane   'music' or 'operations'.
 * @param array<string,string>  $mine   This lane's bands, keyed (hero, stats, then SN_RESUME_LANE_BANDS keys).
 * @param array<string,string>  $theirs The other lane's bands, keyed the same way.
 * @return string
 */
function sn_resume_lane_join( $lane, $mine, $theirs ) {
	$other  = 'music' === $lane ? 'operations' : 'music';
	$headed = array_values( array_intersect( array_keys( $mine ), SN_RESUME_LANE_BANDS ) );
	$at     = sn_resume_lane_hunks( $headed, array_values( array_intersect( array_keys( $theirs ), SN_RESUME_LANE_BANDS ) ) );
	$close  = '</div>' . "\n" . '<!-- /wp:group -->' . "\n\n"; // how every sn_resume_band() ends.
	$last   = end( $headed );
	if ( false !== $last ) {
		$mine[ $last ] = substr( $mine[ $last ], 0, -strlen( $close ) ) . sn_resume_lane_formats( $lane ) . $close;
	}
	$hunks = static fn( $keys ) => implode( '', array_map( static fn( $k ) => sn_resume_hunk_band( $other, $k, $theirs[ $k ] ), $keys ) );
	$out   = '';
	foreach ( $mine as $key => $band ) {
		$out .= $hunks( $at[ $key ] ?? array() ) . $band;
	}
	return $out . $hunks( $at[''] ?? array() );
}

/**
 * The thin band that points at a band only the other lane has: "In {lane}:"
 * and a link named as that band's h2, to the h2's anchor.
 *
 * @param string $lane The lane that has the band.
 * @param string $key  Its SN_RESUME_LANE_BANDS key.
 * @param string $band Its serialized band.
 * @return string
 */
function sn_resume_hunk_band( $lane, $key, $band ) {
	$name = preg_match( '#<h2 class="wp-block-heading"[^>]*>(.*?)</h2>#s', $band, $m ) ? $m[1] : ''; // already escaped.
	$link = '<a href="' . esc_url( sn_resume_lane_url( $lane ) . '#resume-' . $lane . '-' . $key ) . '" data-lane="' . $lane . '" data-sn-goal="resume_hunk_' . $lane . '">' . $name . '</a>';
	return sn_resume_band( '1320px', '20', '20', sn_resume_para( 'sn-resume-hunk', 'In ' . esc_html( SN_RESUME_LANE_LABELS[ $lane ] ) . ': ' . $link ), 'sn-resume-hunk-band' );
}

/**
 * "Also as Markdown · JSON Resume": the lane's machine twins, at its end.
 *
 * @param string $lane 'music' or 'operations'.
 * @return string
 */
function sn_resume_lane_formats( $lane ) {
	$slug = '/' . ( defined( 'SN_RESUME_SLUG' ) ? SN_RESUME_SLUG : 'resume' );
	$q    = 'operations' === $lane ? '?lane=operations' : '';
	$json = $slug . '/' . ( defined( 'SN_JSONRESUME_FILE' ) ? SN_JSONRESUME_FILE : 'json-resume.json' ) . $q;
	return sn_resume_para( 'sn-resume-formats', 'Also as <a href="' . esc_url( $slug . '.md' . $q ) . '">Markdown</a> · <a href="' . esc_url( $json ) . '">JSON Resume</a>' );
}
