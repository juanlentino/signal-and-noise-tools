<?php
/**
 * Signal & Noise Tools: unmatched paths, the cleaner (2026-10-29). A stored
 * path is text a stranger chose; this is the only form of it the owner's
 * report and the local analytics breakdown ever show. The rules are in
 * inc/unmatched-paths.php's header. PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_UNMATCHED_PATH_MAX = 200;

/**
 * One segment, kept or replaced by the placeholder for what it looks like. PURE.
 *
 * @param string $seg Lowercased, decoded segment.
 * @return string
 */
function snt_unmatched_clean_segment( $seg ) {
	if ( false !== strpos( $seg, '@' ) ) {
		return '{email}';
	}
	if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $seg ) ) {
		return '{id}';
	}
	if ( preg_match( '/^[0-9a-f]{16,}$/', $seg ) ) {
		return '{hash}';
	}
	if ( preg_match( '/^\d{7,}$/', $seg ) ) {
		return '{number}';
	}
	// A JWT-shaped value, or a long run of letters and digits with no word
	// break: what tokens, keys and session ids look like, never a slug.
	if ( preg_match( '/^[a-z0-9_-]{8,}\.[a-z0-9_-]{8,}\.[a-z0-9_-]{8,}$/', $seg )
		|| ( strlen( $seg ) >= 20 && preg_match( '/^[a-z0-9]+$/', $seg ) && preg_match( '/\d/', $seg ) )
		|| strlen( $seg ) > 80 ) {
		return '{token}';
	}
	if ( ! preg_match( '/^[a-z0-9._~-]+$/', $seg ) ) {
		return '{text}';
	}
	return $seg;
}

/**
 * A stored path, cleaned for the owner's eyes. PURE.
 *
 * @param mixed $raw Stored path.
 * @return string Starts with "/", no trailing slash (but "/"), at most SNT_UNMATCHED_PATH_MAX.
 */
function snt_unmatched_clean_path( $raw ) {
	$p = is_scalar( $raw ) ? (string) $raw : '';
	$p = preg_split( '/[?#]/', $p )[0];
	$p = strtolower( rawurldecode( $p ) );
	$out = '';
	foreach ( explode( '/', $p ) as $seg ) {
		if ( '' === $seg ) {
			continue; // collapses duplicate and trailing slashes.
		}
		$next = '/' . snt_unmatched_clean_segment( $seg );
		if ( strlen( $out . $next ) > SNT_UNMATCHED_PATH_MAX - 7 ) {
			return $out . '/{more}';
		}
		$out .= $next;
	}
	return '' === $out ? '/' : $out;
}
