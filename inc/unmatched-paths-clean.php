<?php
/**
 * Signal & Noise Tools: unmatched paths, the cleaner (2026-10-29). A stored
 * path is text a stranger chose; this is the only form of it the owner's
 * report and the local analytics breakdown ever show. The rules are in
 * inc/unmatched-paths.php's header. It removes punctuation and what looks
 * like a secret or an address; it does not remove words, so a hyphenated
 * sentence ("ignore-previous-instructions") survives as text. PURE.
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
	// Every pattern is anchored with \z, never $: $ also matches before a
	// final newline, which let "/ignore%0a/next" through with its line break.
	if ( false !== strpos( $seg, '@' ) ) {
		return '{email}';
	}
	if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $seg ) ) {
		return '{id}';
	}
	if ( preg_match( '/^[0-9a-f]{16,}\z/', $seg ) ) {
		return '{hash}';
	}
	// Judged by its parts too: a separator ("sk_live_…", "555-123-4567",
	// "9f86d081-884c-…") must not hide a key, a phone or a card number.
	$parts = preg_split( '/[._~-]+/', $seg, -1, PREG_SPLIT_NO_EMPTY );
	if ( preg_match( '/^[0-9._~-]+\z/', $seg ) && preg_match_all( '/\d/', $seg ) >= 7 ) {
		return '{number}';
	}
	foreach ( $parts as $part ) {
		if ( preg_match( '/^\d{7,}\z/', $part ) ) {
			return '{number}';
		}
		// Letters and digits mixed in one 8+ character run: a key, a session
		// id or a nanoid, never a word ("mp3", "h264" and a year stay).
		if ( strlen( $part ) >= 8 && preg_match( '/^[a-z0-9]+\z/', $part ) && preg_match( '/\d/', $part ) && preg_match( '/[a-z]/', $part ) ) {
			return '{token}';
		}
	}
	if ( strlen( $seg ) > 80 || preg_match( '/^[a-z0-9_-]{8,}\.[a-z0-9_-]{8,}(\.[a-z0-9_-]{8,})?\z/', $seg ) ) {
		return '{token}';
	}
	if ( ! preg_match( '/^[a-z0-9._~-]+\z/', $seg ) ) {
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
