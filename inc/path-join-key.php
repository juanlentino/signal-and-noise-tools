<?php
/**
 * Signal & Noise Tools — the cross-instrument path JOIN KEY.
 *
 * Phase 0 of docs/proposals/measurement-weave-2026-08-31.md.
 *
 * THE PROBLEM, MEASURED 2026-09-01. Four path spellings live in this codebase,
 * and they disagree on 5 of 10 realistic inputs:
 *
 *   input                       analytics          agent               redirects
 *   ""                          ""                 "/"                 "/"
 *   "notes/foo"                 "notes/foo"        "/notes/foo"        "/notes/foo"
 *   "https://host/notes/foo/"   "https://host/..." "/https://host/..." "/notes/foo"
 *   "/notes/foo?utm=x"          "/notes/foo?utm=x" "/notes/foo"        "/notes/foo"
 *   "/notes/foo#top"            "/notes/foo#top"   "/notes/foo#top"    "/notes/foo"
 *
 * Each is CORRECT for its own job — redirects must see a query string, agent
 * discovery must not. The defect is only in JOINING across them: the weave
 * matches AE paths to GSC pages to post permalinks, and a mismatched key does
 * not error, it silently drops the row. A join that loses half its rows reports
 * a smaller, cleaner-looking world.
 *
 * So this is ONE key for JOIN SITES ONLY. It does not replace the four; they
 * keep their local jobs. Nothing here changes what any of them returns.
 *
 * THE RULE THAT MATTERS: an EMPTY input yields an EMPTY key, never "/".
 * Collapsing unusable input onto the homepage would join every unjoinable row
 * onto the site's most-trafficked path and inflate it — a wrong number that
 * looks plausible, which is worse than a missing one. '' means "not joinable";
 * '/' means "the homepage". Callers must treat them differently.
 *
 * @package SignalNoiseTools
 * @since 13.55.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The canonical join key for a URL or path.
 *
 * Absolute URLs are reduced to their path. Query strings and fragments are
 * dropped — neither identifies a page for measurement purposes. Duplicate
 * slashes collapse. A trailing slash is removed except at the root, so
 * `/notes/foo` and `/notes/foo/` are one key rather than two.
 *
 * PURE: no WordPress, no network, no site config — so a join key computed in a
 * test is byte-identical to one computed in production.
 *
 * @since 13.55.0
 * @param string $uri A path, a relative URL, or an absolute URL.
 * @return string The join key, or '' when the input names no path at all.
 */
function sn_path_join_key( $uri ) {
	if ( ! is_string( $uri ) ) {
		return '';
	}
	$uri = trim( $uri );
	if ( '' === $uri ) {
		return '';
	}

	// Absolute (or protocol-relative) URL -> its path component. parse_url is
	// used rather than wp_parse_url so this stays loadable standalone; the two
	// agree for every input shape reaching a join site.
	// The protocol-relative branch requires an actual HOST after the "//".
	// Without that guard "//" alone parses as an empty-authority URL and yields
	// nothing, when all three existing normalizers agree it is the homepage
	// with a doubled slash (measured 2026-09-01). The join key must not
	// disagree with them where they already agree.
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $uri ) || preg_match( '#^//[^/]#', $uri ) ) {
		$parsed = parse_url( 0 === strpos( $uri, '//' ) ? 'https:' . $uri : $uri );
		if ( ! is_array( $parsed ) ) {
			return ''; // unparseable -> NOT joinable, never a guess
		}
		$uri = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
	}

	// Drop query and fragment wherever they appear. Deliberately after the
	// absolute-URL branch, so `?` inside a host can never be mistaken for one.
	$cut = strcspn( $uri, '?#' );
	$uri = substr( $uri, 0, $cut );

	$uri = preg_replace( '#/+#', '/', $uri );
	if ( null === $uri || '' === $uri ) {
		return '';
	}
	if ( '/' !== substr( $uri, 0, 1 ) ) {
		$uri = '/' . $uri;
	}
	if ( '/' === $uri ) {
		return '/';
	}
	$uri = rtrim( $uri, '/' );

	// rtrim can only reach '' when the input was slashes alone, which the
	// collapse above already reduced to '/' — belt, because returning '' here
	// would silently mean "not joinable" for a real homepage hit.
	return '' === $uri ? '/' : $uri;
}

/**
 * Join two path-keyed maps on the canonical key, reporting what did NOT match.
 *
 * WHY THIS RETURNS MISSES. A silent join is the failure this phase exists to
 * prevent: a key mismatch drops the row and the result still looks like a
 * clean answer. Callers get the matched pairs AND the counts on each side that
 * found no partner, so "we joined 12 of 40" can never read as "there are 12".
 *
 * @since 13.55.0
 * @param array $left  key => mixed (keys are raw paths/URLs; normalized here).
 * @param array $right key => mixed.
 * @return array{joined:array<string,array{left:mixed,right:mixed}>,left_only:string[],right_only:string[],left_unjoinable:int,right_unjoinable:int}
 */
function sn_path_join( $left, $right ) {
	$norm = function ( $map, &$unjoinable ) {
		$out = array();
		foreach ( (array) $map as $k => $v ) {
			$key = sn_path_join_key( (string) $k );
			if ( '' === $key ) {
				$unjoinable++;
				continue; // never fold an unjoinable row onto '/'
			}
			$out[ $key ] = $v;
		}
		return $out;
	};
	$lu = 0;
	$ru = 0;
	$l  = $norm( $left, $lu );
	$r  = $norm( $right, $ru );

	$joined = array();
	foreach ( $l as $k => $v ) {
		if ( array_key_exists( $k, $r ) ) {
			$joined[ $k ] = array( 'left' => $v, 'right' => $r[ $k ] );
		}
	}
	return array(
		'joined'          => $joined,
		'left_only'       => array_values( array_diff( array_keys( $l ), array_keys( $r ) ) ),
		'right_only'      => array_values( array_diff( array_keys( $r ), array_keys( $l ) ) ),
		'left_unjoinable' => $lu,
		'right_unjoinable' => $ru,
	);
}
