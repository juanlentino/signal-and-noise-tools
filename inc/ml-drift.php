<?php
/**
 * Signal & Noise — corpus drift as an editorial mirror (R4 4A, ML pipeline #9).
 *
 * Buckets the PUBLISHED corpus by calendar year of post_date_gmt (UTC — the
 * analytics "today" rule) and hands adjacent-year pairs to the pure kernel's
 * snt_ml_corpus_drift(). All arithmetic lives in inc/ml-kernel.php; this file
 * owns the corpus walk and the bucketing, nothing heavier.
 *
 * NO SNAPSHOTS BY DESIGN: the corpus carries its own history in post dates, so
 * drift is recomputed from the live corpus on demand. A stored time series
 * would be a second source of truth able to disagree with the first.
 *
 * WRITER-FACING ONLY — the row's own prose says "shown to the writer, never to
 * a model". That boundary is an ABSENCE (no ability registration, no remote
 * twin), and tests/ml-drift.php pins the absence, because an unregistered
 * surface is one helpful future session away from existing.
 *
 * A YEAR BELOW THE FLOOR REFUSES TO SPEAK: the kernel returns verdict 'thin'
 * and this module renders that verdict as its own state — never as "no drift".
 * A confident 0.00 over three notes is the failure this exists to prevent.
 *
 * @package SignalNoiseTools
 * @since 11.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Per-year document floor under which drift refuses to speak. */
const SNT_ML_DRIFT_MIN_DOCS = 5;

/** Rows per movement list (risen/fallen/entered/silenced). */
const SNT_ML_DRIFT_TOP = 12;

if ( ! function_exists( 'snt_ml_drift_year_buckets' ) ) {
	/**
	 * The published corpus bucketed by UTC calendar year: year => doc map.
	 *
	 * Published only — drafts are not part of the site's public vocabulary,
	 * and including them would let an unpublished pile skew the mirror.
	 * Bodies that tokenize to nothing are skipped (markup-only: zero lexical
	 * signal), matching the cousins walk.
	 *
	 * @return array<int,array<int,string[]>> Ascending by year; may be empty.
	 */
	function snt_ml_drift_year_buckets() {
		$buckets = array();
		foreach ( snt_corpus_fetch_posts( 'publish', 'post' ) as $post ) {
			$date = (string) ( $post->post_date_gmt ?? '' );
			$year = (int) substr( $date, 0, 4 );
			if ( $year <= 0 ) {
				continue; // A zeroed/absent GMT date names no year; skip, never guess.
			}
			$tokens = snt_ml_tokenize( (string) ( $post->post_content ?? '' ) );
			if ( array() === $tokens ) {
				continue;
			}
			$buckets[ $year ][ (int) $post->ID ] = $tokens;
		}
		ksort( $buckets );
		return $buckets;
	}
}

if ( ! function_exists( 'snt_ml_drift_report' ) ) {
	/**
	 * The mirror: per-term drift for every ADJACENT year pair in the corpus.
	 *
	 * Adjacent pairs, not first-vs-last: the editorial question is "how did the
	 * vocabulary move", and a decade collapsed to one comparison hides the year
	 * the move happened. Every pair carries its own verdict, so one thin early
	 * year does not silence the well-fed pairs after it.
	 *
	 * @return array {
	 *     @type bool  $ok    Always true (an empty corpus is a real answer).
	 *     @type array $years  Ascending list of {year:int, docs:int}.
	 *     @type array $pairs  Ascending list of {from:int, to:int} + the kernel
	 *                         drift envelope (verdict/docs/risen/fallen/entered/silenced).
	 * }
	 */
	function snt_ml_drift_report() {
		$buckets = snt_ml_drift_year_buckets();
		$years   = array();
		foreach ( $buckets as $year => $docs ) {
			$years[] = array(
				'year' => (int) $year,
				'docs' => count( $docs ),
			);
		}

		$pairs    = array();
		$year_ids = array_keys( $buckets );
		$count    = count( $year_ids );
		for ( $i = 1; $i < $count; $i++ ) {
			$from  = $year_ids[ $i - 1 ];
			$to    = $year_ids[ $i ];
			$drift = snt_ml_corpus_drift( $buckets[ $from ], $buckets[ $to ], SNT_ML_DRIFT_MIN_DOCS, SNT_ML_DRIFT_TOP );
			// Non-consecutive years (a silent year between) still pair — the
			// comparison is between the corpus's own periods, and skipping the
			// gap would compare nothing to nothing. The pair NAMES both years,
			// so the gap is visible rather than papered over.
			$pairs[] = array_merge( array( 'from' => (int) $from, 'to' => (int) $to ), $drift );
		}

		return array(
			'ok'    => true,
			'years' => $years,
			'pairs' => $pairs,
		);
	}
}
