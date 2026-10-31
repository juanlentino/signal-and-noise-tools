<?php
/**
 * Signal & Noise Tools: the unmatched-paths report (report only, 2026-10-29).
 *
 * What visitors asked for that is not a note or a page: the paths every reader
 * has always counted as "(unmatched)", named for the owner. Built at scan time
 * from the stored analytics (inc/unmatched-paths-read.php) and joined to the
 * 404 log for status and referrer. Zero findings by design: the paths are the
 * deliverable, the fix is the owner's (a link at its source, or a redirect in
 * Site › Redirects), and nothing here creates one.
 *
 * Kept out of every machine read on purpose: a report-only check has no
 * findings, so the health-scan tools (local and remote) name it and say it
 * passed, never its rows. The paths reach a model only through the local
 * analytics_rows breakdown, cleaned.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/unmatched-paths-read.php';

const SN_UNMATCHED_REPORT_PEOPLE    = 100; // Rows the scan stores for people; the true total rides beside them.
const SN_UNMATCHED_REPORT_AUTOMATED = 30;
const SN_UNMATCHED_REPORT_SERVED    = 30;

/**
 * The check.
 *
 * @return array
 */
function sn_health_check_unmatched_paths() {
	// Suggestions run before the live check moves served rows out, so they
	// cover the rows shown after it: every row the check could move, and more.
	$r      = snt_unmatched_report( SN_UNMATCHED_REPORT_PEOPLE + SNT_UNMATCHED_PROBE_MAX );
	$packed = sn_health_pack_check(
		__( 'Unmatched paths: what visitors asked for that is not a note or page (report only)', 'signal-and-noise-tools' ),
		array(), // Report-first: the paths are the deliverable.
		__( 'Report only: fix the link where it lives, or add a redirect in Site › Redirects. Nothing here creates one.', 'signal-and-noise-tools' ),
		$r['error'] // A failed read is skipped, never a clean report.
	);
	if ( null !== $r['error'] ) {
		return $packed;
	}
	$packed['report'] = array(
		'people'          => array_slice( $r['people'], 0, SN_UNMATCHED_REPORT_PEOPLE ),
		'people_total'    => count( $r['people'] ),
		'human_views'     => array_sum( array_column( $r['people'], 'human' ) ),
		'people_visits'   => array_sum( array_column( $r['people'], 'visits' ) ),
		'served'          => array_slice( $r['served'], 0, SN_UNMATCHED_REPORT_SERVED ),
		'served_total'    => count( $r['served'] ),
		'automated'       => array_slice( $r['automated'], 0, SN_UNMATCHED_REPORT_AUTOMATED ),
		'automated_total' => count( $r['automated'] ),
		'capped'          => (bool) $r['capped'],
		'generated_at'    => time(),
		'coverage'        => sn_health_report_coverage( 'unmatched_paths' ),
	);
	return $packed;
}

/**
 * The headline, in the same words on both twins (plain text).
 *
 * @param array $report The check's report payload.
 * @return string
 */
function sn_unmatched_headline( array $report ) {
	$n     = (int) ( $report['people_total'] ?? 0 );
	$views = (int) ( $report['human_views'] ?? 0 );
	$served = (int) ( $report['served_total'] ?? 0 );
	if ( 0 === $n ) {
		return 0 === $served
			? __( 'No visitor reached a path that is not a note or page.', 'signal-and-noise-tools' )
			/* translators: %d: paths the site serves or already redirects. */
			: sprintf( _n( 'None of these paths answers with an error: the %d path the site serves or already redirects is listed below.', 'None of these paths answers with an error: the %d paths the site serves or already redirects are listed below.', $served, 'signal-and-noise-tools' ), $served );
	}
	$visits = (int) ( $report['people_visits'] ?? 0 );
	if ( 0 === $views && $visits > 0 ) {
		// Only feeds or suspect-only paths left: "0 human views" would read as nothing.
		/* translators: %d: visits. */
		$in_all = sprintf( _n( '%d visit in all', '%d visits in all', $visits, 'signal-and-noise-tools' ), $visits );
		/* translators: 1: paths, 2: "17 visits in all". */
		$line = sprintf( _n( '%1$d path visitors reached is not a note or page (no human page views, %2$s).', '%1$d paths visitors reached are not a note or page (no human page views, %2$s).', $n, 'signal-and-noise-tools' ), $n, $in_all );
	} else {
		/* translators: 1: paths, 2: human views. */
		$line = sprintf( _n( '%1$d path visitors reached is not a note or page (%2$d human views in all).', '%1$d paths visitors reached are not a note or page (%2$d human views in all).', $n, 'signal-and-noise-tools' ), $n, $views );
	}
	if ( ! empty( $report['capped'] ) ) {
		$line .= ' ' . __( 'The read stopped at its cap of stored rows, so the list may be short.', 'signal-and-noise-tools' );
	}
	return $line;
}

/** "3 human · 1 suspect", or "17 visits, no page views" for a feed (plain text). */
function sn_unmatched_visits_words( array $row ) {
	$parts = array();
	foreach ( array( 'human', 'suspect', 'bot' ) as $class ) {
		if ( ! empty( $row[ $class ] ) ) {
			$parts[] = (int) $row[ $class ] . ' ' . $class;
		}
	}
	if ( ! $parts && ! empty( $row['visits'] ) ) {
		/* translators: %d: visits. */
		return sprintf( _n( '%d visit, no page views', '%d visits, no page views', (int) $row['visits'], 'signal-and-noise-tools' ), (int) $row['visits'] );
	}
	return implode( ' · ', $parts );
}

/** "2026-10-02 to 2026-10-20", or the one day (plain text). */
function sn_unmatched_seen_words( array $row ) {
	$first = (string) ( $row['first_seen'] ?? '' );
	$last  = (string) ( $row['last_seen'] ?? '' );
	/* translators: 1: first day seen, 2: last day seen. */
	return $first === $last ? $last : sprintf( __( '%1$s to %2$s', 'signal-and-noise-tools' ), $first, $last );
}

/**
 * The status: what the live check got ("served (200)", "redirects (301)",
 * "404"), else what the 404 log saw, with the referring host when the log has
 * one, else "not in the 404 log" (plain text).
 */
function sn_unmatched_status_words( array $row ) {
	$code = $row['status'] ?? null;
	if ( null === $code ) {
		return __( 'not in the 404 log', 'signal-and-noise-tools' );
	}
	$code = (int) $code;
	if ( $code < 400 ) {
		/* translators: %d: HTTP status code. */
		return sprintf( $code < 300 ? __( 'served (%d)', 'signal-and-noise-tools' ) : __( 'redirects (%d)', 'signal-and-noise-tools' ), $code );
	}
	/* translators: 1: HTTP status code, 2: referring hostname. */
	return null === ( $row['referrer'] ?? null ) ? (string) $code : sprintf( __( '%1$d · from %2$s', 'signal-and-noise-tools' ), $code, (string) $row['referrer'] );
}

/** The suggested fix (plain text). */
function sn_unmatched_fix_words( array $row ) {
	if ( ! empty( $row['variant'] ) ) {
		return __( 'Same page, another spelling', 'signal-and-noise-tools' );
	}
	/* translators: %s: the suggested target path. */
	return null !== ( $row['suggest'] ?? null ) ? sprintf( __( 'Redirect to %s?', 'signal-and-noise-tools' ), (string) $row['suggest'] ) : __( 'No close match', 'signal-and-noise-tools' );
}
