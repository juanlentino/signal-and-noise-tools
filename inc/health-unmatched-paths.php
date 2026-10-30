<?php
/**
 * Signal & Noise Tools: the unmatched-paths report (report only, 2026-10-29).
 *
 * What visitors asked for that is not a note or a page: the paths every reader
 * has always counted as "(unmatched)", named for the owner. Built at scan time
 * from the stored analytics (inc/unmatched-paths-read.php) and joined to the
 * 404 log for status and referrer. Zero findings by design: the paths are the
 * deliverable, the fix is the owner's (a link at its source, or a redirect in
 * Site > Redirects), and nothing here creates one.
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

/**
 * The check.
 *
 * @return array
 */
function sn_health_check_unmatched_paths() {
	$packed = sn_health_pack_check(
		__( 'Unmatched paths: what visitors asked for that is not a note or page (report only)', 'signal-and-noise-tools' ),
		array(), // Report-first: the paths are the deliverable.
		__( 'Report only: fix the link where it lives, or add a redirect in Site > Redirects. Nothing here creates one.', 'signal-and-noise-tools' ),
		null
	);
	$r = snt_unmatched_report();
	$packed['report'] = array(
		'people'          => array_slice( $r['people'], 0, SN_UNMATCHED_REPORT_PEOPLE ),
		'people_total'    => count( $r['people'] ),
		'human_views'     => array_sum( array_column( $r['people'], 'human' ) ),
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
	if ( 0 === $n ) {
		return __( 'No visitor reached a path that is not a note or page.', 'signal-and-noise-tools' );
	}
	/* translators: 1: paths, 2: human views. */
	$line = sprintf( _n( '%1$d path visitors reached is not a note or page (%2$d human views in all).', '%1$d paths visitors reached are not a note or page (%2$d human views in all).', $n, 'signal-and-noise-tools' ), $n, $views );
	if ( ! empty( $report['capped'] ) ) {
		$line .= ' ' . __( 'The read stopped at its cap of stored rows, so the list may be short.', 'signal-and-noise-tools' );
	}
	return $line;
}

/** "3 human · 1 suspect" (plain text). */
function sn_unmatched_visits_words( array $row ) {
	$parts = array();
	foreach ( array( 'human', 'suspect', 'bot' ) as $class ) {
		if ( ! empty( $row[ $class ] ) ) {
			$parts[] = (int) $row[ $class ] . ' ' . $class;
		}
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

/** What the 404 log saw: "404 · from linkedin.com", "404", or "not in the 404 log" (plain text). */
function sn_unmatched_status_words( array $row ) {
	if ( 404 !== ( $row['status'] ?? null ) ) {
		return __( 'not in the 404 log', 'signal-and-noise-tools' );
	}
	/* translators: %s: referring hostname. */
	return null === ( $row['referrer'] ?? null ) ? '404' : sprintf( __( '404 · from %s', 'signal-and-noise-tools' ), (string) $row['referrer'] );
}

/** The suggested fix (plain text). */
function sn_unmatched_fix_words( array $row ) {
	if ( ! empty( $row['variant'] ) ) {
		return __( 'Same page, another spelling', 'signal-and-noise-tools' );
	}
	/* translators: %s: the suggested target path. */
	return null !== ( $row['suggest'] ?? null ) ? sprintf( __( 'Redirect to %s?', 'signal-and-noise-tools' ), (string) $row['suggest'] ) : __( 'No close match', 'signal-and-noise-tools' );
}
