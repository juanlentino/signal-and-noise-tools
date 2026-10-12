<?php
/**
 * The 5xx responder rows in words: who asked (the request source) and who
 * answered (edge and origin status). Split out of inc/edge-rollup.php
 * (2026-10-12 re-measure) so the Measurement leaf can read the short labels
 * and their legend; the full labels, parentheticals included, are unchanged
 * for the abilities and the S&N Analytics edge view.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who asked, in words (18.7.0). `edgeWorkerFetch` is not "a Worker" in the
 * sense a reader takes it: sn-rights-signals sits on `juanlentino.com/*` and
 * passes every page through with fetch(request), so most of those rows are a
 * visitor's request the Worker forwarded (47 of 93 "worker" 5xx on
 * 2026-09-23). A Worker's own calls to the origin read the same, so the
 * label names the path, not a guess at the person. `edgeWorkerCacheAPI` is a
 * cache lookup that made no request; the query drops it from 18.7.0, and the
 * label only reads rows stored before that.
 *
 * @param string $source Cloudflare requestSource.
 * @param bool   $short  Leave the parenthetical out (the leaf's legend says it once).
 * @return string
 */
function sn_edge_error_asker_label( $source, $short = false ) {
	$source = (string) $source;
	if ( 'eyeball' === $source ) {
		return __( 'asked by a visitor', 'signal-and-noise-tools' );
	}
	if ( 'edgeWorkerCacheAPI' === $source ) {
		return __( 'a Worker\'s cache lookup, no request made', 'signal-and-noise-tools' );
	}
	if ( 0 === strpos( $source, 'edgeWorker' ) ) {
		return $short ? __( 'through a Worker', 'signal-and-noise-tools' ) : __( 'through a Worker (usually a visitor\'s request it forwarded)', 'signal-and-noise-tools' );
	}
	/* translators: %s: Cloudflare request source. */
	return sprintf( __( 'asked by %s', 'signal-and-noise-tools' ), $source );
}

/**
 * `edge=503 origin=503 cache=miss` in words. The distinction is the one
 * #1002 could not get from outside: an origin status means the origin (or
 * the cache in front of it) failed; `origin=-` means nothing upstream
 * answered, so Cloudflare or a Worker produced the error by itself.
 *
 * @param string $value A stored err_source value.
 * @param bool   $short Leave the parentheticals out (sn_edge_error_source_notes() has them).
 * @return string
 */
function sn_edge_error_source_label( $value, $short = false ) {
	$who = '';
	if ( preg_match( '/^src=(\S+) /', (string) $value, $s ) ) {
		$who   = ', ' . sn_edge_error_asker_label( $s[1], $short );
		$value = substr( (string) $value, strlen( $s[0] ) );
	}
	if ( ! preg_match( '/^edge=(\d+) origin=(\d+|-)/', (string) $value, $m ) ) {
		return (string) $value . $who;
	}
	if ( '-' === $m[2] ) {
		/* translators: %s: HTTP status Cloudflare returned. */
		return ( $short
			/* translators: %s: HTTP status Cloudflare returned. */
			? sprintf( __( '%s from Cloudflare itself', 'signal-and-noise-tools' ), $m[1] )
			: sprintf( __( '%s from Cloudflare itself (the origin never answered)', 'signal-and-noise-tools' ), $m[1] ) ) . $who;
	}
	if ( $m[1] === $m[2] ) {
		/* translators: %s: HTTP status the origin returned. */
		return sprintf( __( '%s from the origin', 'signal-and-noise-tools' ), $m[2] ) . $who;
	}
	/* translators: 1: status Cloudflare returned, 2: status the origin returned. */
	return sprintf( __( '%1$s at the edge, origin said %2$s', 'signal-and-noise-tools' ), $m[1], $m[2] ) . $who;
}

/**
 * The parentheticals the short labels leave out, keyed so a list of rows
 * says each once: `cf` when the origin never answered, `worker` when a
 * Worker forwarded the request (not its cache lookup).
 *
 * @param string $value A stored err_source value.
 * @return array<string,string>
 */
function sn_edge_error_source_notes( $value ) {
	$value = (string) $value;
	$notes = array();
	if ( preg_match( '/(?:^|\s)edge=\d+ origin=-(?:\s|$)/', $value ) ) {
		$notes['cf'] = __( 'From Cloudflare itself: the origin never answered.', 'signal-and-noise-tools' );
	}
	if ( preg_match( '/^src=edgeWorker(?!CacheAPI)\S* /', $value ) ) {
		$notes['worker'] = __( 'Through a Worker: usually a visitor’s request it forwarded.', 'signal-and-noise-tools' );
	}
	return $notes;
}
