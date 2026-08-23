<?php
/**
 * Signal & Noise Tools — a rolling history of Content-Health scans.
 *
 * SN_HEALTH_CACHE_KEY holds exactly one thing, and its name says so:
 * `sn_health_last_scan`. Every run overwrote the one before it, so the only
 * question the stored data could answer was "what is true now". Until v12.23.0
 * that was arguably enough, because the scan only ran when somebody clicked —
 * there was no series to keep. Now it runs daily, and a daily verdict with no
 * memory still cannot answer the question a daily verdict invites: is this
 * getting better or worse, and since when.
 *
 * WHAT IS RECORDED, and why it is a summary rather than the scan. A full result
 * carries every check's payload — findings, rows, tables — and 200 of those in
 * one option would be a few megabytes read on the Health tab. The row here is
 * the shape of a verdict: when, how long, how many findings and advisories, how
 * many checks ran, and which checks were flagged with their counts. That is
 * enough to plot a trend and to answer "which check has been red for a week",
 * which is the thing a series is actually for.
 *
 * THE NUMBERS ARE NOT RECOMPUTED. Every figure comes from the same helpers the
 * Health tab and the Dashboard read — sn_health_finding_total(),
 * sn_health_advisory_total(), sn_health_check_total(),
 * sn_health_flagged_checks(). A log that counted findings its own way would
 * eventually disagree with the panel above it, and then neither number could be
 * trusted without going to read which one was lying.
 *
 * IT IS A FIFO, AND IT FORGETS. At SN_HEALTH_HISTORY_CAP rows and one scan a
 * day, the window is about six and a half months; older rows are dropped, not
 * archived. So this can answer "has broken-links been flagged all week" and it
 * can NEVER answer "how many findings have there ever been" — the same eviction
 * that made the AI usage log unable to carry month-to-date spend, which is why
 * that module keeps a separate durable rollup. Nothing here should be summed as
 * if it were complete.
 *
 * @package SignalNoiseTools
 * @since 12.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rolling log of scan summaries. autoload=no: read on admin screens only. */
const SN_HEALTH_HISTORY_OPT = 'sn_health_scan_history';

/** Rows kept. At one scan a day this is ~6.5 months. */
const SN_HEALTH_HISTORY_CAP = 200;

/**
 * One history row from a scan result. Pure: no reads, no writes.
 *
 * Returns array() for anything that is not a scan, so a malformed result is
 * never recorded as a verdict of zero findings — an absent row and a row saying
 * "clean" are different claims.
 *
 * @param array $scan A sn_health_run_scan() result.
 * @return array
 */
function sn_health_history_row( $scan ) {
	if ( ! is_array( $scan ) || ! isset( $scan['checks'] ) || ! is_array( $scan['checks'] ) ) {
		return array();
	}

	$flagged = array();
	if ( function_exists( 'sn_health_flagged_checks' ) ) {
		foreach ( (array) sn_health_flagged_checks( $scan ) as $key => $check ) {
			$n = (int) ( $check['count'] ?? 0 );
			if ( $n > 0 ) {
				$flagged[ (string) $key ] = $n;
			}
		}
	}

	return array(
		'ts'         => (int) ( $scan['scanned_at'] ?? time() ),
		'ms'         => (int) ( $scan['elapsed_ms'] ?? 0 ),
		'findings'   => function_exists( 'sn_health_finding_total' ) ? (int) sn_health_finding_total( $scan ) : 0,
		'advisories' => function_exists( 'sn_health_advisory_total' ) ? (int) sn_health_advisory_total( $scan ) : 0,
		'checks'     => function_exists( 'sn_health_check_total' ) ? (int) sn_health_check_total( $scan ) : 0,
		'flagged'    => $flagged,
	);
}

/**
 * Append a scan to the rolling log, oldest evicted first.
 *
 * @param array $scan A sn_health_run_scan() result.
 * @return void
 */
function sn_health_history_append( $scan ) {
	$row = sn_health_history_row( $scan );
	if ( ! $row ) {
		return;
	}
	$log   = get_option( SN_HEALTH_HISTORY_OPT );
	$log   = is_array( $log ) ? $log : array();
	$log[] = $row;
	if ( count( $log ) > SN_HEALTH_HISTORY_CAP ) {
		$log = array_slice( $log, -SN_HEALTH_HISTORY_CAP );
	}
	update_option( SN_HEALTH_HISTORY_OPT, $log, false );
}

add_action( 'sn_health_scan_stored', 'sn_health_history_append' );

/**
 * The log, oldest first.
 *
 * @param int $limit Newest N rows, or 0 for all kept.
 * @return array
 */
function sn_health_history( $limit = 0 ) {
	$log   = get_option( SN_HEALTH_HISTORY_OPT );
	$log   = is_array( $log ) ? $log : array();
	$limit = (int) $limit;
	return $limit > 0 ? array_slice( $log, -$limit ) : $log;
}

/**
 * How many consecutive most-recent scans flagged a given check.
 *
 * The question a series exists to answer: "how long has this been red?" Counts
 * back from the newest row and stops at the first scan that did not flag it, so
 * a check that cleared and returned reports its CURRENT streak, not its total.
 *
 * Returns 0 when the newest scan did not flag it — including when the log is
 * empty, which is why callers should treat 0 as "not currently red" and never as
 * "measured clean over the window".
 *
 * @param string $check Check key, e.g. 'broken_links'.
 * @return int
 */
function sn_health_history_streak( $check ) {
	$check  = (string) $check;
	$streak = 0;
	foreach ( array_reverse( sn_health_history() ) as $row ) {
		if ( (int) ( $row['flagged'][ $check ] ?? 0 ) > 0 ) {
			$streak++;
			continue;
		}
		break;
	}
	return $streak;
}
