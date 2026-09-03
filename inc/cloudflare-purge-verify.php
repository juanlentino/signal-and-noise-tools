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

/**
 * How many probe outcomes the log retains.
 *
 * Named in v13.86.0 because this number is load-bearing for how the summary
 * READS. `total` pins at this value once the buffer fills, so it is a WINDOW
 * SIZE, not a lifetime count — and a rising `stale` against a fixed
 * denominator means the recent failure RATE is rising, not that a tally is
 * accumulating. Read the other way it says "this can only go up", which is the
 * opposite conclusion drawn from the same five numbers. As a bare 20 inside
 * array_slice() nothing could report it, so no reader could tell which it was.
 */
const SN_CF_PROBE_LOG_CAP = 20;

/** Option holding the last few probe outcomes, for the Cloudflare tab. */
const SN_CF_PROBE_LOG_OPT = 'sn_cf_purge_probe_log';

/**
 * The comparison algorithm that produced a verdict.
 *
 * WHY A VERDICT NEEDS A VERSION. Until v11.29.1 this probe compared a CACHED
 * render against a CACHE-BUSTED one, which on this site can never be equal —
 * Breeze injects a prefetch script on one path only. Every probe returned stale
 * and every one escalated: the log read 11 stale of 11, from a detector that
 * could not return anything else.
 *
 * The detector was fixed. The LOG was not, and nothing in an entry said which
 * detector wrote it — so the desktop widget kept showing a red "Edge served a
 * stale render" over a day-old pre-fix row while the Dashboard screen reported
 * every zone fresh. Two surfaces disagreeing, because one was counting evidence
 * from a broken instrument.
 *
 * A measurement made by a broken instrument is not a measurement. Bump this
 * whenever snt_cf_normalize_render() changes what it compares, and every older
 * verdict stops counting — the log is kept on disk for forensics, but it is no
 * longer evidence.
 *
 * 1 = pre-v11.29.1 whole-document comparison (never explicitly stamped).
 * 2 = v11.29.1 <main>-region comparison, comments and whitespace stripped.
 *
 * @since 11.30.3
 */
const SN_CF_PROBE_ALGO = 2;

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

	// v11.29.1 — COMPARE THE CONTENT REGION, NOT THE DOCUMENT.
	//
	// Measured against the live site 2026-08-19: the two fetches this probe
	// compares are not comparable as whole documents, for reasons that have
	// nothing to do with staleness.
	//
	//   Breeze injects <script id="breeze-prefetch-js-extra"> on the
	//   cache-busted request and not on the cached one — a different code path
	//   through the caching plugin, present on EVERY url.
	//
	// So every probe returned stale, every one escalated to a full zone purge,
	// and the log read 11 stale of 11. Not a stale edge: a detector that could
	// not return anything else.
	//
	// <main> is the region both paths render identically — verified byte-equal
	// on /about/, / and /resume/ after the normalisation below. Falling back to
	// the whole document when there is no <main> keeps a theme without that
	// element comparing something rather than silently reading fresh.
	if ( preg_match( '#<main\b[^>]*>(.*)</main>#is', $html, $main ) ) {
		$html = $main[1];
	}

	// Minification strips HTML comments, so a comment is not evidence of drift.
	// Done BEFORE the volatile patterns so a token inside a comment cannot
	// survive as a difference.
	$html = preg_replace( '/<!--.*?-->/s', '', $html );
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
	// Whitespace is REMOVED, not collapsed. The cached copy is minified and the
	// cache-busted copy is not, so `><` and `> <` must compare equal; collapsing
	// runs to a single space leaves that difference intact. Measured 122,960 vs
	// 132,288 bytes on /about/ — about 9KB of inter-tag whitespace.
	//
	// The cost, stated: this also ignores whitespace inside <pre> and
	// <textarea>, so a change that is ONLY whitespace in preformatted text
	// reads as fresh. That is the right trade against eleven needless zone
	// purges, and the alternative — parsing to compare structurally — is a
	// large amount of machinery for a heuristic.
	return preg_replace( '/\s+/u', '', $html );
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
	// Stamp WHAT MEASURED IT. Without this every new verdict is
	// indistinguishable from the pre-fix rows and the summary never recovers.
	$entry['algo'] = SN_CF_PROBE_ALGO;

	$log = get_option( SN_CF_PROBE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $entry );
	update_option( SN_CF_PROBE_LOG_OPT, array_slice( $log, 0, SN_CF_PROBE_LOG_CAP ), false );
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
/**
 * The one human sentence describing the last purge verdict.
 *
 * SINGLE PRODUCER, both surfaces. The Classic Admin cell and the OpenStation
 * cache widget used to build this separately — PHP on one side, JavaScript on
 * the other — so they could and did drift in tone about the same row: one said
 * "still stale after 4 mins" while the other said "Edge served a stale render".
 * Owner ruling 2026-09-03: the two surfaces must say the same thing about the
 * cache, from the authoritative record. Two implementations agreeing is a
 * coincidence; one implementation is the guarantee.
 *
 * @param string $last      fresh|stale|unknown.
 * @param int    $last_time Unix time of the verdict.
 * @param int    $now       Unix time now.
 * @return string
 */
function snt_cf_freshness_phrase( $last, $last_time, $now ) {
	$last_time = (int) $last_time;
	$now       = (int) $now;
	if ( $last_time <= 0 || $last_time > $now ) {
		// No usable timestamp: say that, rather than inventing an age. A future
		// stamp is a broken clock, not a fresh purge.
		return __( 'no timing recorded', 'signal-and-noise-tools' );
	}
	$ago = function_exists( 'human_time_diff' ) ? human_time_diff( $last_time, $now ) : ( $now - $last_time ) . 's';

	if ( 'stale' === (string) $last ) {
		/* translators: %s: human-readable age of the verdict, e.g. "4 mins" */
		return sprintf( __( 'last verdict %s ago', 'signal-and-noise-tools' ), $ago );
	}
	if ( 'fresh' === (string) $last ) {
		/* translators: %s: human-readable age of the verdict, e.g. "4 mins" */
		return sprintf( __( 'verified %s ago', 'signal-and-noise-tools' ), $ago );
	}
	if ( 'pending' === (string) $last ) {
		// Says what IS known — a purge happened, and when — rather than
		// implying nobody looked.
		/* translators: %s: human-readable age of the purge, e.g. "4 mins" */
		return sprintf( __( 'purged %s ago, verifying', 'signal-and-noise-tools' ), $ago );
	}
	// 'unknown' is not 'fresh'. Something ran and could not read an answer.
	/* translators: %s: human-readable age of the verdict, e.g. "4 mins" */
	return sprintf( __( 'unread %s ago', 'signal-and-noise-tools' ), $ago );
}

/**
 * The headline noun phrase, shared by both surfaces for the same reason.
 *
 * @param string $last fresh|stale|unknown.
 * @return string
 */
function snt_cf_freshness_headline( $last ) {
	if ( 'stale' === (string) $last ) {
		return __( 'Edge served a stale render', 'signal-and-noise-tools' );
	}
	if ( 'fresh' === (string) $last ) {
		return __( 'Edge fresh', 'signal-and-noise-tools' );
	}
	if ( 'pending' === (string) $last ) {
		return __( 'Purge dispatched, verifying', 'signal-and-noise-tools' );
	}
	return __( 'Last verdict unrecognised', 'signal-and-noise-tools' );
}

function snt_cf_freshness_summary() {
	// ── "LAST PURGE" READS THE AUTHORITATIVE RECORD, NOT A COPY ──────────
	//
	// v13.87.2. v13.70.0 needed this cell to update when someone pressed
	// Purge, and satisfied that by APPENDING A ROW TO THE PROBE LOG — a
	// measurement store whose job is recording whether post-save purges clear
	// the edge. Every defect since has been a consequence of that one
	// duplication:
	//
	//   the count climbed when you purged   (racing probes booked as stale)
	//   the count fell when you purged      (each row evicting an older one)
	//
	// Both directions, the number answered "how often did you press Purge?".
	// Measured 2026-09-03 as presses displaced the diagnostic population:
	// manual 10 / post-save 10  ->  13 / 7  ->  15 / 5.
	//
	// THE INVARIANT: a diagnostic must not move because you operated the thing
	// it measures. So manual purges no longer write here at all, and this reads
	// sn_last_purge_report — which already carries time, resolved and epoch,
	// and which the theme's deferred verify CORRECTS in place once propagation
	// has finished. Reading the source of truth means there is nothing to keep
	// in step, and no settle-and-copy machinery to get wrong.
	$report    = get_option( 'sn_last_purge_report', array() );
	$last      = 'unknown';
	$last_time = 0;
	if ( is_array( $report ) && array_key_exists( 'resolved', $report ) ) {
		$last = empty( $report['resolved'] ) ? 'stale' : 'fresh';
		// verified_at when the deferred verify has run, else the purge time.
		$last_time = (int) ( $report['verified_at'] ?? ( $report['time'] ?? 0 ) );
	} elseif ( is_array( $report ) && ! empty( $report['time'] ) ) {
		// v13.91.1 — PENDING, and v13.87.2 collapsed this into `unknown`.
		//
		// The theme writes `resolved` only on a VERIFIED purge. An AUTO purge —
		// which is what a plugin or theme update fires, via
		// inc/deploy-history.php passing no `verified` flag — writes a report
		// with no `resolved` key at all, and its deferred cron verify fills it
		// about 75 seconds later.
		//
		// Reading that gap as `unknown` made the cache readout blank on EVERY
		// update and recover a minute later, which is a flicker that teaches a
		// reader to ignore the field. And it is not true: a purge demonstrably
		// happened, we know when, and verification is scheduled. That is a
		// KNOWN state.
		//
		// `unknown` now means what it says — no report at all, or one with no
		// usable time. Same distinction as checks_skipped against
		// checks_passed: could-not-run-YET is not nothing-known.
		$last      = 'pending';
		$last_time = (int) $report['time'];
	}

	// ── THE TALLY IS POST-SAVE PROBES ONLY ───────────────────────────────
	//
	// Filtered at READ time, not just at write: the log on an upgraded site
	// still holds manual rows written before this, and counting them would
	// carry the old defect forward until they aged out.
	$log     = get_option( SN_CF_PROBE_LOG_OPT, array() );
	$current = array();
	if ( is_array( $log ) ) {
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// An entry with no `algo` predates the detector fix; counting it
			// would put a broken instrument in numerator AND denominator.
			if ( (int) ( $entry['algo'] ?? 1 ) < SN_CF_PROBE_ALGO ) {
				continue;
			}
			if ( 'manual_zone_purge' === ( $entry['source'] ?? '' ) ) {
				continue;
			}
			$current[] = $entry;
		}
	}

	// Nothing known from either source is NOT a clean edge — it is an absence
	// of evidence, and the widgets render null as "records a verdict after the
	// next purge", which is the true statement.
	if ( 'unknown' === $last && empty( $current ) ) {
		return null;
	}

	$stale     = 0;
	$escalated = 0;
	foreach ( $current as $entry ) {
		if ( 'stale' === ( $entry['result'] ?? '' ) ) {
			++$stale;
			if ( ! empty( $entry['escalated'] ) ) {
				++$escalated;
			}
		}
	}

	return array(
		// Anything we do not recognise is `unknown`, never silently `fresh`.
		'last'      => in_array( $last, array( 'fresh', 'stale', 'pending' ), true ) ? $last : 'unknown',
		'last_time' => $last_time,
		// Carried IN the summary so every renderer gets the same words without
		// each computing them. See snt_cf_freshness_phrase().
		'headline'  => snt_cf_freshness_headline( $last ),
		'phrase'    => snt_cf_freshness_phrase( $last, $last_time, time() ),
		// These describe POST-SAVE probes. Pressing Purge cannot move them,
		// which is the whole point of the split above.
		'total'     => count( $current ),
		'stale'     => $stale,
		'escalated' => $escalated,
	);
}
