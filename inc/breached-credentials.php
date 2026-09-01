<?php
/**
 * Signal & Noise Tools — breached-credential client (HIBP k-anonymity).
 *
 * Phase 0 of docs/proposals/breached-credential-check-2026-08-31.md: the client
 * and its parser, with NO hook wiring — and it stays hookless: Mode A (set-time,
 * fail-closed, v13.58.0) lives in inc/breached-credentials-set.php, and Mode B
 * (login-time, advisory) are later phases and are deliberately absent — this
 * file cannot reject or warn about anything yet.
 *
 * THE PRIVACY CONTRACT, and it is the reason this design is usable at all.
 * Only the first 5 characters of the SHA-1 leave the origin. The 35-character
 * suffix is compared in memory and discarded. The plaintext and its full SHA-1
 * are NEVER logged, cached, persisted, or transmitted — not in an error string,
 * not in a transient, not in a return value.
 *
 * WHY LIVE k-ANONYMITY RATHER THAN THE OFFLINE CORPUS. The first draft of the
 * plan preferred a downloaded corpus to avoid a runtime dependency in the auth
 * path. It was reversed by a measurement: 8 ranges sampled across the keyspace
 * averaged 80,274 bytes, putting the full SHA-1 corpus at ~84.2 GB across
 * 1,048,576 files. That does not go on managed hosting, and no incremental
 * refresh changes the floor.
 *
 * THE TRI-STATE IS THE WHOLE POINT. An empty 200 and "your suffix is not in the
 * list" are byte-identical on the wire and mean opposite things. A client that
 * collapses them into a boolean reports the safest-looking answer at the exact
 * moment it stopped working. So this returns BREACHED / NOT_BREACHED /
 * UNAVAILABLE, and callers must handle the third — Mode A will fail CLOSED on
 * it, Mode B will drop it silently.
 *
 * @package SignalNoiseTools
 * @since 13.54.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Range endpoint. The prefix is appended; nothing else is ever sent. */
const SN_HIBP_RANGE_URL = 'https://api.pwnedpasswords.com/range/';

/** Verdicts. Three, never two — see this file's header. */
const SN_HIBP_BREACHED     = 'breached';
const SN_HIBP_NOT_BREACHED = 'not_breached';
const SN_HIBP_UNAVAILABLE  = 'unavailable';

/**
 * Split a plaintext password into its k-anonymity halves.
 *
 * PURE, and the only function in this file that ever sees plaintext. Returns
 * uppercase hex because the API's response suffixes are uppercase and a
 * case-mismatched comparison would silently never match — a false NOT_BREACHED,
 * which is the dangerous direction.
 *
 * @since 13.54.0
 * @param string $password Plaintext. Never stored, never returned.
 * @return array{prefix:string,suffix:string} Empty strings when unusable.
 */
function sn_hibp_split( $password ) {
	if ( ! is_string( $password ) || '' === $password ) {
		return array( 'prefix' => '', 'suffix' => '' );
	}
	$hash = strtoupper( sha1( $password ) );
	return array(
		'prefix' => substr( $hash, 0, 5 ),
		'suffix' => substr( $hash, 5 ),
	);
}

/**
 * Parse a range response body against a suffix.
 *
 * PURE — no network, no WordPress. This is where the fixture suite lives,
 * because parsing is where the interesting failures are.
 *
 * THE EMPTY-BODY RULE. A 200 with an empty body is UNAVAILABLE, never
 * NOT_BREACHED. Every real range in the corpus has hundreds of entries; an
 * empty one means something in front of the API answered instead of the API.
 * Collapsing that into "clean" is the exact wrong-call readout this codebase
 * keeps closing.
 *
 * Tolerant of the wire's realities — CRLF, blank lines, and a trailing
 * newline are all normal — but NOT of garbage: a body with no parseable
 * `SUFFIX:COUNT` line at all is UNAVAILABLE, because an unparseable answer is
 * not a clean answer.
 *
 * @since 13.54.0
 * @param string $body   Response body.
 * @param string $suffix The 35-char uppercase suffix to look for.
 * @return array{verdict:string,count:int} count is 0 unless BREACHED.
 */
function sn_hibp_parse_range( $body, $suffix ) {
	$suffix = strtoupper( trim( (string) $suffix ) );
	$body   = (string) $body;
	if ( '' === $suffix || '' === trim( $body ) ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}

	$parsed = 0;
	foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = explode( ':', $line, 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		$row_suffix = strtoupper( trim( $parts[0] ) );
		if ( ! preg_match( '/^[0-9A-F]{35}$/', $row_suffix ) ) {
			continue;
		}
		$parsed++;
		if ( $row_suffix === $suffix ) {
			// A padded response (Add-Padding) uses count 0 for filler rows.
			// A zero count means "present as padding", not "breached zero
			// times" — treat it as not a hit rather than a hit of size 0.
			$count = (int) trim( $parts[1] );
			if ( $count > 0 ) {
				return array( 'verdict' => SN_HIBP_BREACHED, 'count' => $count );
			}
		}
	}

	// Parsed nothing => the body was not a range response at all.
	if ( 0 === $parsed ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}
	return array( 'verdict' => SN_HIBP_NOT_BREACHED, 'count' => 0 );
}

/**
 * Classify an HTTP outcome before the body is trusted.
 *
 * PURE. Anything other than a 200 is UNAVAILABLE — including 404, which the
 * API does not use for "clean" but a proxy might invent.
 *
 * @since 13.54.0
 * @param mixed $status HTTP status code, or null when the request errored.
 * @return string SN_HIBP_UNAVAILABLE, or '' when the status is usable.
 */
function sn_hibp_status_blocks( $status ) {
	if ( null === $status || ! is_numeric( $status ) || 200 !== (int) $status ) {
		return SN_HIBP_UNAVAILABLE;
	}
	return '';
}

/**
 * The live check. Returns the tri-state; never throws, never logs plaintext.
 *
 * `Add-Padding` is requested so the response size cannot leak how many entries
 * the prefix has — the padding rows carry count 0, which the parser treats as
 * absent.
 *
 * @since 13.54.0
 * @param string $password Plaintext. Not stored, not returned, not logged.
 * @param int    $timeout  Seconds.
 * @return array{verdict:string,count:int}
 */
function sn_hibp_check_password( $password, $timeout = 4 ) {
	$halves = sn_hibp_split( $password );
	if ( '' === $halves['prefix'] ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}

	$res = wp_remote_get(
		SN_HIBP_RANGE_URL . $halves['prefix'],
		array(
			'timeout'    => (int) $timeout,
			'headers'    => array(
				'Add-Padding' => 'true',
				'User-Agent'  => 'signal-and-noise-tools',
			),
			'user-agent' => 'signal-and-noise-tools',
		)
	);

	if ( function_exists( 'is_wp_error' ) && is_wp_error( $res ) ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}
	$status = function_exists( 'wp_remote_retrieve_response_code' ) ? wp_remote_retrieve_response_code( $res ) : null;
	if ( '' !== sn_hibp_status_blocks( $status ) ) {
		return array( 'verdict' => SN_HIBP_UNAVAILABLE, 'count' => 0 );
	}
	$body = function_exists( 'wp_remote_retrieve_body' ) ? wp_remote_retrieve_body( $res ) : '';

	return sn_hibp_parse_range( $body, $halves['suffix'] );
}
