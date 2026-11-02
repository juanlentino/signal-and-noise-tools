<?php
/**
 * Signal & Noise Tools: the JSON Resume mapper's parsers and sections
 * (inc/resume-jsonresume.php). Dates are read in English and Spanish, the
 * two languages the résumé is written in; anything else parses to ''.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Sep 2022", "sep. 2018", "agosto de 2026", "2016" to ISO 8601 (YYYY-MM or
 * YYYY). '' for anything else, "Present" included: never a guessed month.
 *
 * @param string $s
 * @return string
 */
function sn_jsonresume_date( $s ) {
	if ( ! preg_match( '~^(?:(\p{L}+)\.?\s+(?:de\s+)?)?([12]\d{3})$~u', trim( (string) $s ), $m ) ) {
		return '';
	}
	if ( '' === $m[1] ) {
		return $m[2];
	}
	$months = array( 'jan' => 1, 'ene' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12, 'dic' => 12 );
	$n      = $months[ mb_substr( mb_strtolower( $m[1] ), 0, 3 ) ] ?? 0;
	return $n ? sprintf( '%s-%02d', $m[2], $n ) : '';
}

/**
 * A "start - end" range to [start, end]; an open or single date has end ''.
 *
 * @param string $s
 * @return array{0:string,1:string}
 */
function sn_jsonresume_range( $s ) {
	$parts = preg_split( '~\s+[-–]\s+~u', trim( (string) $s ) );
	return array( sn_jsonresume_date( $parts[0] ?? '' ), sn_jsonresume_date( $parts[1] ?? '' ) );
}

/**
 * "Title · Sep 2022 - Present" to [title, dates]; [title, ''] when the last
 * segment is not a date.
 *
 * @param string $s
 * @return array{0:string,1:string}
 */
function sn_jsonresume_split_dated( $s ) {
	$at = strrpos( (string) $s, ' · ' );
	if ( false !== $at && '' !== sn_jsonresume_range( substr( $s, $at + 4 ) )[0] ) {
		return array( trim( substr( $s, 0, $at ) ), trim( substr( $s, $at + 4 ) ) );
	}
	return array( trim( (string) $s ), '' );
}

/**
 * Where the résumé says it is (pdf.location, else the contact line), with a
 * trailing parenthetical apart: "Orlando, FL (open to relocation)" to
 * ["Orlando, FL", "open to relocation"].
 *
 * @param array $doc
 * @return array{0:string,1:string}
 */
function sn_jsonresume_where( array $doc ) {
	$where = trim( '' !== (string) ( $doc['pdf']['location'] ?? '' ) ? (string) $doc['pdf']['location'] : (string) ( $doc['hero']['contact_line'] ?? '' ) );
	return preg_match( '~^(.+?)\s*\(([^()]+)\)$~u', $where, $m ) ? array( $m[1], trim( $m[2] ) ) : array( $where, '' );
}

/** A bullet's allowlisted HTML as plain text. @param string $s @return string */
function sn_jsonresume_plain( $s ) {
	return trim( html_entity_decode( strip_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Profiles from profile URLs, deduplicated by host and path.
 *
 * @param array<int,string> $urls
 * @return array<int,array{network:string,username:string,url:string}>
 */
function sn_jsonresume_profiles( array $urls ) {
	$names = array( 'x.com' => 'X', 'twitter.com' => 'X', 'instagram.com' => 'Instagram', 'linkedin.com' => 'LinkedIn', 'orcid.org' => 'ORCID', 'github.com' => 'GitHub', 'open.spotify.com' => 'Spotify', 'bsky.app' => 'Bluesky' );
	$out   = array();
	foreach ( $urls as $url ) {
		$host = preg_replace( '~^www\.~', '', (string) parse_url( (string) $url, PHP_URL_HOST ) );
		$path = trim( (string) parse_url( (string) $url, PHP_URL_PATH ), '/' );
		if ( '' === $host || ! preg_match( '~^https?://~', (string) $url ) || isset( $out[ "$host/$path" ] ) ) {
			continue;
		}
		$segs               = explode( '/', $path );
		$out[ "$host/$path" ] = array( 'network' => $names[ $host ] ?? $host, 'username' => (string) end( $segs ), 'url' => (string) $url );
	}
	return array_values( $out );
}

/**
 * Education, plus the honors its extra lines carry (as awards). A degree's
 * verification URL rides as `x-credentialUrl`: the schema's education `url`
 * is the institution's site, and the item allows extra properties.
 *
 * @param array $doc
 * @return array{0:array,1:array}
 */
function sn_jsonresume_education( array $doc ) {
	$edu    = array();
	$honors = array();
	foreach ( $doc['education'] ?? array() as $row ) {
		$title = explode( ', ', $row['title'], 2 );
		$inst  = trim( explode( ' · ', (string) ( $row['lines'][0] ?? '' ) )[0] );
		$end   = '';
		foreach ( array_slice( $row['lines'], 1 ) as $line ) {
			$date = sn_jsonresume_date( $line );
			if ( '' !== $date ) {
				$end = $date;
				continue;
			}
			foreach ( explode( ' · ', $line ) as $honor ) {
				$honors[] = array_filter( array( 'title' => trim( $honor ), 'awarder' => $inst ) );
			}
		}
		$edu[] = array_filter( array( 'institution' => $inst, 'studyType' => trim( $title[0] ), 'area' => trim( $title[1] ?? '' ), 'endDate' => $end, 'x-credentialUrl' => $row['credential_url'] ?? '' ) );
	}
	return array( $edu, $honors );
}

/**
 * Affiliations: dated ranges (memberships) to awards, the rest to certificates.
 * A verification URL is a certificate's own `url`, a membership's
 * `x-credentialUrl` (awards have no url field).
 *
 * @param array $doc
 * @return array{0:array,1:array}
 */
function sn_jsonresume_affiliations( array $doc ) {
	$awards = array();
	$certs  = array();
	foreach ( $doc['affiliations'] ?? array() as $row ) {
		$line = (string) ( $row['lines'][0] ?? '' );
		if ( preg_match( '~\s[-–]\s~u', $line ) ) {
			$by       = explode( ', ', $row['title'], 2 );
			$awards[] = array_filter( array( 'title' => $row['title'], 'awarder' => trim( $by[1] ?? '' ), 'date' => sn_jsonresume_range( $line )[0], 'summary' => $line, 'x-credentialUrl' => $row['credential_url'] ?? '' ) );
		} else {
			$certs[] = array_filter( array( 'name' => $row['title'], 'url' => $row['credential_url'] ?? '' ) );
		}
	}
	return array( $awards, $certs );
}

/**
 * Publications: "SSRN Working Paper · April 2026 · In submission, ..." meta.
 *
 * @param array $doc
 * @return array
 */
function sn_jsonresume_publications( array $doc ) {
	$out = array();
	foreach ( $doc['publications'] ?? array() as $pub ) {
		$segs = array_map( 'trim', explode( ' · ', (string) $pub['meta'] ) );
		$date = '';
		$rest = array();
		foreach ( array_slice( $segs, 1 ) as $seg ) {
			if ( '' === $date && '' !== sn_jsonresume_date( $seg ) ) {
				$date = sn_jsonresume_date( $seg );
			} else {
				$rest[] = $seg;
			}
		}
		$out[] = array_filter( array( 'name' => $pub['title'], 'publisher' => $segs[0], 'releaseDate' => $date, 'url' => $pub['url'], 'summary' => implode( ' · ', $rest ) ) );
	}
	return $out;
}

/**
 * Skills by category; the Languages / Idiomas row becomes languages[]. Items
 * split on commas outside parentheses: "Standards (C2PA, TDMRep, RSL)" is one.
 *
 * @param array $doc
 * @return array{0:array,1:array}
 */
function sn_jsonresume_skills( array $doc ) {
	$skills    = array();
	$languages = array();
	foreach ( $doc['skills'] ?? array() as $row ) {
		$items = array_values( array_filter( array_map( 'trim', (array) preg_split( '~,(?![^()]*\))~u', $row['items'] ) ), 'strlen' ) );
		if ( in_array( $row['category'], array( 'Languages', 'Idiomas' ), true ) ) {
			foreach ( $items as $item ) {
				$languages[] = preg_match( '~^(.+?)\s*\((.+)\)$~u', $item, $m ) ? array( 'language' => $m[1], 'fluency' => $m[2] ) : array( 'language' => $item );
			}
			continue;
		}
		$skills[] = array( 'name' => $row['category'], 'keywords' => $items );
	}
	return array( $skills, $languages );
}
