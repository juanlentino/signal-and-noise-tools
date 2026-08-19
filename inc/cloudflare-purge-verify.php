<?php
/**
 * Signal & Noise Tools — did the per-post Cloudflare purge actually work?
 *
 * v11.10.0, born from a live incident. On 2026-08-15 a Note was edited three
 * times (18:55, 19:00, 19:05). Each save fired the per-post purge in
 * cloudflare-purge.php. Fifty minutes later the bare URL still served HTML
 * with `last-modified: Fri, 14 Aug 16:25:36 GMT` — 27 hours old — carrying a
 * sentence the edit had removed. The same URL with a cache-busting query
 * returned the current render. A manual ZONE purge cleared it immediately.
 *
 * So three per-URL purges ran and did not work, while one zone purge did.
 * Cloudflare single-file purge must match the exact cache key and does not
 * reliably clear upper tiers under Tiered Cache; a zone purge always does.
 *
 * Nothing could see this. sn_cf_purge_urls() is documented as
 * "Fire-and-forget (non-blocking); Caller doesn't get a success signal", and
 * the Cloudflare admin tab reports purge DISPATCH, never edge FRESHNESS for
 * the purged URL. The readout measured the wrong call: it was green the whole
 * time the public was being served a stale page, and the public provenance
 * ledger went red for it three times.
 *
 * This module closes the loop: after a per-post purge, check the URL a reader
 * would actually get, and escalate ONCE to a zone purge if it is still stale.
 *
 * The comparison lives in pure functions so it is testable without HTTP.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Seconds to wait before probing. Long enough for a purge to propagate. */
const SN_CF_PROBE_DELAY = 120;

/** Hook name for the scheduled probe. */
const SN_CF_PROBE_HOOK = 'sn_cf_verify_post_purge';

/** Option holding the last few probe outcomes, for the Cloudflare tab. */
const SN_CF_PROBE_LOG_OPT = 'sn_cf_purge_probe_log';

/**
 * Reduce a rendered page to something comparable across two fetches.
 *
 * Two fetches of the SAME page differ in ways that are not staleness: nonces,
 * CSRF tokens, cache-buster query strings in asset URLs, and the probe's own
 * marker. Strip those, then collapse whitespace — what remains differs only
 * when the markup genuinely differs.
 *
 * Deliberately NOT a content extractor: this compares two renders of one URL
 * against each other, never a render against the signed record. The ledger
 * remains the only thing that judges content against provenance; this only
 * answers "is the cached copy the same page the origin is serving now?".
 *
 * @param string $html Raw response body.
 * @return string Normalized digest source.
 */
function snt_cf_normalize_render( $html ) {
	$html = (string) $html;
	// Volatile per-request tokens. Filterable because a future plugin can add
	// its own, and a false "stale" verdict costs a needless zone purge.
	$patterns = apply_filters( 'sn_cf_probe_volatile_patterns', array(
		'/name="[a-z_\-]*nonce[a-z_\-]*"\s+value="[^"]*"/i',
		'/"nonce"\s*:\s*"[^"]*"/i',
		'/\bnonce=[A-Za-z0-9]+/',
		'/[?&]ver=[^"\'&\s]*/',
		'/[?&]sn-cache-probe=1/',
	) );
	foreach ( $patterns as $pattern ) {
		$html = preg_replace( $pattern, '', $html );
	}
	return trim( preg_replace( '/\s+/u', ' ', $html ) );
}

/**
 * Is the cached render different from what the origin serves right now?
 *
 * Returns null — not false — when either body is unusable. Unknown must never
 * pass as fresh: a probe that could not answer is a gap in evidence, and the
 * caller escalates on true only, so null correctly does nothing.
 *
 * @param string|null $bare_html  Body from the public URL.
 * @param string|null $fresh_html Body from the cache-busted URL.
 * @return bool|null True when the cached copy is stale.
 */
function snt_cf_probe_is_stale( $bare_html, $fresh_html ) {
	if ( ! is_string( $bare_html ) || ! is_string( $fresh_html ) ) {
		return null;
	}
	if ( '' === trim( $bare_html ) || '' === trim( $fresh_html ) ) {
		return null;
	}
	return snt_cf_normalize_render( $bare_html ) !== snt_cf_normalize_render( $fresh_html );
}

/**
 * Append one probe outcome to the bounded log the admin tab reads.
 *
 * @param array $entry Outcome record.
 * @return void
 */
function snt_cf_probe_record( array $entry ) {
	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $entry );
	update_option( SN_CF_PROBE_LOG_OPT, array_slice( $log, 0, 20 ), false );
}

/**
 * Summarise the purge-verification log for a glance surface. (v11.29.0)
 *
 * The log has been written since v11.10.0 and read by NOTHING — an instrument
 * with no reader. This is its first one.
 *
 * NULL means never probed, and that is NOT the same as all-fresh.
 * snt_cf_verify_post_purge() deliberately records nothing when a probe is
 * unreadable — "an outage is a gap in evidence, not a verdict" — so an empty
 * log means verification has not run, and reporting that as a clean edge would
 * be the same green-readout-over-a-stale-page failure this module exists to
 * catch (2026-08-15).
 *
 * @since 11.29.0
 * @return array{last:string,last_time:int,total:int,stale:int,escalated:int}|null
 */
function snt_cf_freshness_summary() {
	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	if ( ! is_array( $log ) || empty( $log ) ) {
		return null;
	}

	$stale     = 0;
	$escalated = 0;
	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( 'stale' === ( $entry['result'] ?? '' ) ) {
			++$stale;
			if ( ! empty( $entry['escalated'] ) ) {
				++$escalated;
			}
		}
	}

	// snt_cf_probe_record() array_unshifts, so index 0 is the newest.
	$newest = is_array( $log[0] ) ? $log[0] : array();
	$result = (string) ( $newest['result'] ?? '' );

	return array(
		// Anything we do not recognise is `unknown`, never silently `fresh`.
		'last'      => in_array( $result, array( 'fresh', 'stale' ), true ) ? $result : 'unknown',
		'last_time' => (int) ( $newest['time'] ?? 0 ),
		'total'     => count( $log ),
		'stale'     => $stale,
		'escalated' => $escalated,
	);
}
