<?php
/**
 * Signal & Noise Tools — Search Console × crawler-ledger cross-exam (R6b).
 *
 * TWO INDEPENDENT INSTRUMENTS THAT SHOULD AGREE. Google reports what it showed
 * to searchers; the Worker's machine-reader ledger reports what actually fetched
 * the site. If Google is ranking pages, something Google-shaped must be fetching
 * them. When one instrument sees that and the other does not, the disagreement
 * is the finding — and which way round it falls names a different problem.
 *
 * WHAT THIS DELIBERATELY IS NOT: a per-page join. The ledger aggregates to
 * {family, surface, day, hits} and carries NO path dimension, so GSC's
 * path-keyed rows have nothing to join to. The R6 scaffold assumed that join
 * existed; recon inside the arc found it does not. The shared dimension is
 * coarse agreement over a window, and pretending otherwise would invent a
 * precision neither instrument has.
 *
 * THE WINDOWS DO NOT LINE UP, and that is stated rather than smoothed: Google's
 * window ends ~3 days back because it is still counting, while the ledger runs
 * to now. This is an ORDER-OF-MAGNITUDE agreement check, never an equality test.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Crawler families that are search engines rather than AI or feed readers. */
function snt_gsc_search_families() {
	// 'seo' is deliberately EXCLUDED: Ahrefs/Semrush fetching the site says
	// nothing about whether Google is. Including it would make the ledger side
	// look healthy while the question — is a SEARCH ENGINE fetching us — went
	// unanswered.
	return array( 'search' );
}

/**
 * Compare the two instruments.
 *
 * @param int $days Ledger window; matched to Google's 28 where possible.
 * @return array
 */
function snt_gsc_crossexam( $days = 28 ) {
	$data = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	if ( null === $data ) {
		return array( 'ok' => false, 'reason' => 'no_gsc' );
	}
	if ( ! function_exists( 'snt_mr_fetch' ) ) {
		return array( 'ok' => false, 'reason' => 'no_ledger_module' );
	}
	// v13.62.1: read the AGGREGATE ROWS, not the summary payload. From R6b
	// (v13.11.0) to v13.62.0 this iterated $ledger['rows'] on
	// snt_mr_summary_payload(), which returns families/purposes/totals and has
	// NEVER carried a 'rows' key — so search_hits was 0 by construction and the
	// verdict read gsc_without_crawler whenever Google reported anything. The
	// unit test stubbed the summary WITH rows (stub drift). Measured on the day
	// of the fix: 8,290 search-purpose fetches in the window the verdict
	// called "NO search-engine fetches".
	$ledger = snt_mr_fetch( (int) $days );
	if ( empty( $ledger['ok'] ) ) {
		// A sensor that did not answer is NOT "zero crawler hits" — reporting it
		// as zero would manufacture the exact disagreement this looks for.
		return array( 'ok' => false, 'reason' => 'ledger_' . (string) ( $ledger['error'] ?? 'unknown' ) );
	}

	$impressions = 0;
	foreach ( (array) $data['pages'] as $m ) {
		$impressions += (int) $m['impressions'];
	}

	$families = snt_gsc_search_families();
	$search_hits = 0;
	$robots_hits = 0;
	$sitemap_hits = 0;
	foreach ( (array) ( $ledger['rows'] ?? array() ) as $row ) {
		$hits = (int) ( $row['hits'] ?? 0 );
		if ( ! in_array( (string) ( $row['family'] ?? '' ), $families, true ) ) {
			continue;
		}
		$search_hits += $hits;
		$surface = (string) ( $row['surface'] ?? '' );
		if ( 'robots' === $surface ) {
			$robots_hits += $hits;
		} elseif ( 'sitemap' === $surface ) {
			$sitemap_hits += $hits;
		}
	}

	if ( $impressions > 0 && 0 === $search_hits ) {
		$verdict = 'gsc_without_crawler';
	} elseif ( 0 === $impressions && $search_hits > 0 ) {
		$verdict = 'crawler_without_gsc';
	} elseif ( 0 === $impressions && 0 === $search_hits ) {
		$verdict = 'both_quiet';
	} else {
		$verdict = 'agree';
	}

	return array(
		'ok'      => true,
		'verdict' => $verdict,
		'gsc'     => array( 'impressions' => $impressions, 'window' => $data['window'] ),
		'ledger'  => array(
			'search_hits'  => $search_hits,
			'robots_hits'  => $robots_hits,
			'sitemap_hits' => $sitemap_hits,
			'days'         => (int) $days,
			// The aggregate view truncates on wide windows (latest days dropped
			// first). TRUE means every ledger count above is a FLOOR.
			'truncated'    => ! empty( $ledger['truncated'] ),
		),
	);
}

/**
 * The sentence a verdict earns. Each names a DIFFERENT problem.
 *
 * @param array $x From snt_gsc_crossexam().
 * @return string
 */
function snt_gsc_crossexam_reading( $x ) {
	if ( empty( $x['ok'] ) ) {
		return '';
	}
	switch ( (string) $x['verdict'] ) {
		case 'gsc_without_crawler':
			return __( 'Google reports impressions, but the Worker logged NO search-engine fetches. Two instruments disagree about whether Google is fetching this site. Either the ledger is blind (the sensor did not observe those fetches — an edge cache serving the crawler past it, or a classifier miss), or Google is serving results from an index it did not recrawl inside this window. Check the ledger\'s search-purpose count before believing either.', 'signal-and-noise-tools' );
		case 'crawler_without_gsc':
			return __( 'Search engines are fetching the site, but Google reports no impressions in its window. That is a ranking or indexing problem, not a crawling one — the opposite diagnosis from the reverse case.', 'signal-and-noise-tools' );
		case 'both_quiet':
			return __( 'Neither instrument saw search activity in its window. Nothing is contradicted, and nothing is confirmed.', 'signal-and-noise-tools' );
		default:
			return __( 'Both instruments agree: search engines are fetching the site and Google is showing it. Their windows differ by a few days, so this is agreement in magnitude, not an equality check.', 'signal-and-noise-tools' );
	}
}
