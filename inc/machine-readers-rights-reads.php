<?php
/**
 * Signal & Noise Tools — the rights-read count, as a public claim.
 *
 * R3 gate 3B (the planned half). The board's row: "the rights-read count
 * published on the machine-readability page itself, read from the crawler
 * ledger at render — once that read can be served from state the site already
 * holds, so a reader's page never waits on a sensor call."
 *
 * That second clause is gate 3A, and it is why this module reads a snapshot
 * record it is HANDED rather than fetching anything itself. There is no sensor
 * call on this path, by construction: the only input is an array.
 *
 * A RIGHTS read is a read of the surfaces that carry the terms — the crawler
 * manifest, the reservation, the licence, the agent manifest, the well-known
 * documents. Reading an article is not reading the terms, so 'html' is
 * deliberately excluded: folding it in would let a busy month of ordinary
 * crawling masquerade as machines actually consulting the reservation, which is
 * the exact claim this number exists to support or refute.
 *
 * Three-valued, inherited from the snapshot: an unmeasured count renders as
 * unmeasured. Publishing "no machine has read our terms" off a sensor that
 * never answered would be the most flattering possible reading of a broken
 * pipe, and it is the one failure mode this page cannot afford.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The surfaces whose reads count as "a machine read the terms".
 *
 * A strict subset of snt_mr_valid_surfaces(); feed/wp-json/sitemap/asset/html
 * are content or discovery, not terms. Extending this set widens a published
 * claim, so it is a deliberate edit, never a convenience.
 *
 * @return string[]
 */
function snt_mr_rights_surfaces() {
	return array( 'robots', 'rights', 'llms', 'agents-manifest', 'well-known' );
}

/**
 * Total reads of the rights surfaces in the snapshot's window.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return int|null Null when nothing was ever measured — NEVER 0. A measured
 *                  window in which no machine touched the terms is a real 0.
 */
function snt_mr_rights_reads( $snap ) {
	if ( ! is_array( $snap ) || ! is_int( $snap['captured_at'] ?? null ) ) {
		return null;
	}
	$by_surface = isset( $snap['by_surface'] ) && is_array( $snap['by_surface'] ) ? $snap['by_surface'] : array();
	$total      = 0;
	foreach ( snt_mr_rights_surfaces() as $surface ) {
		// Absent means the surface saw no reads in a window we DID measure —
		// a zero contribution, not an unknown one. The unknown case was already
		// answered by captured_at above.
		$total += max( 0, (int) ( $by_surface[ $surface ] ?? 0 ) );
	}
	return $total;
}

/**
 * Age of a measurement in reader's units. Whole hours under a day, whole days
 * above it — a public sentence saying "4 hours and 12 minutes ago" implies a
 * precision an hourly snapshot does not have.
 *
 * @param int $age Seconds.
 * @return string
 */
function snt_mr_rights_reads_age_phrase( $age ) {
	$age = max( 0, (int) $age );
	if ( $age < DAY_IN_SECONDS ) {
		$hours = max( 1, (int) floor( $age / HOUR_IN_SECONDS ) );
		/* translators: %d: whole hours. */
		return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'signal-and-noise-tools' ), $hours );
	}
	$days = max( 1, (int) floor( $age / DAY_IN_SECONDS ) );
	/* translators: %d: whole days. */
	return sprintf( _n( '%d day ago', '%d days ago', $days, 'signal-and-noise-tools' ), $days );
}

/**
 * The published sentence. Plain text — the render layer escapes at its sink.
 *
 * Carries no option names, endpoint paths or internal prefixes: this lands on a
 * maturity page, which holds a standing "model, never levers" contract that
 * tests/maturity-family.php sweeps across every rendered format.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return string
 */
function snt_mr_rights_reads_sentence( $snap ) {
	$reads = snt_mr_rights_reads( $snap );

	if ( null === $reads ) {
		return __( 'This site counts how often machines read the terms it publishes. That count has not been measured yet.', 'signal-and-noise-tools' );
	}

	$days = isset( $snap['days'] ) ? max( 1, (int) $snap['days'] ) : 30;

	if ( 0 === $reads ) {
		/* translators: %d: window length in days. */
		$sentence = sprintf( __( 'No machine has read this site\'s published terms in the last %d days.', 'signal-and-noise-tools' ), $days );
	} else {
		$sentence = sprintf(
			/* translators: 1: read count. 2: window length in days. */
			_n(
				'Machines read this site\'s published terms %1$s time in the last %2$d days.',
				'Machines read this site\'s published terms %1$s times in the last %2$d days.',
				$reads,
				'signal-and-noise-tools'
			),
			number_format_i18n( $reads ),
			$days
		);
	}

	// The age clause appears only once the measurement is old enough to mislead.
	// A number this page states in the present tense must say so when it is not.
	// The threshold lives with the snapshot module that owns it — duplicating it
	// here would let the page and the record disagree about what "stale" means.
	$age = max( 0, time() - (int) $snap['captured_at'] );
	if ( true === snt_mr_snapshot_is_stale( $snap ) ) {
		/* translators: %s: a phrase like "3 days ago". */
		$sentence .= ' ' . sprintf( __( 'Last measured %s.', 'signal-and-noise-tools' ), snt_mr_rights_reads_age_phrase( $age ) );
	}

	return $sentence;
}
