<?php
/**
 * Signal & Noise Tools: "I requested indexing", and a live check of what Google
 * did since (2026-10-31).
 *
 * Google's Indexing API covers job postings and livestreams only, so asking
 * Google to index a note stays one click in Search Console. Everything around
 * that click lives here. The owner marks a row requested: the mark is the day
 * of the mark, not of the click, which the site cannot see. "Check now"
 * re-inspects the marked rows and the request list on demand
 * (inc/search-console-check-now.php). Each row's
 * status reads Google's own answer against the mark. A mark younger than
 * SNT_GSC_REQUEST_QUIET_DAYS with no crawl after it is "waiting on Google":
 * the index_gap watch and the morning brief stop asking for it. A crawl after
 * the mark that still did not index asks again.
 *
 * Marks live in one autoload=no option keyed by join path. Only the owner's
 * two buttons write (inc/admin-post-actions/gsc-indexing.php); every reader
 * reads, and no reader spends inspection quota.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_GSC_REQUESTED_OPTION   = 'snt_gsc_requested';
const SNT_GSC_REQUEST_QUIET_DAYS = 14;
const SNT_GSC_REQUEST_KEEP_DAYS  = 30; // an indexed row stays listed as "indexed after your request" this long.
const SNT_GSC_REQUEST_MAX_DAYS   = 90; // any mark is pruned after this.

/** The marks: join path => unix time of the mark. */
function snt_gsc_requested_marks() {
	$m = get_option( SNT_GSC_REQUESTED_OPTION, array() );
	return is_array( $m ) ? array_filter( array_map( 'intval', $m ) ) : array();
}

/**
 * One row's status. PURE. last_crawl is Google's lastCrawlTime as unix time,
 * 0 when Google reports none.
 *
 * @param array $entry        Coverage map entry (may be empty).
 * @param int   $requested_at The mark, 0 when none.
 * @param int   $now
 * @return array{state:string,requested_at:int,last_crawl:int,quiet:bool}
 */
function snt_gsc_request_status( $entry, $requested_at, $now ) {
	$entry = is_array( $entry ) ? $entry : array();
	$crawl = (int) strtotime( (string) ( $entry['last_crawl_time'] ?? '' ) );
	$at    = max( 0, (int) $requested_at );
	if ( ! empty( $entry['indexed'] ) ) {
		$state = 'indexed';
	} elseif ( 0 === $at ) {
		$state = 'not_requested';
	} else {
		$state = $crawl > $at ? 'crawled_since' : 'waiting';
	}
	return array(
		'state'        => $state,
		'requested_at' => $at,
		'last_crawl'   => max( 0, $crawl ),
		'quiet'        => 'waiting' === $state && ( (int) $now - $at ) < SNT_GSC_REQUEST_QUIET_DAYS * DAY_IN_SECONDS,
	);
}

/**
 * Statuses for the listed paths, plus the marked pages Google has since indexed. PURE.
 *
 * @param array    $entries Coverage map entries, keyed by join path.
 * @param array    $marks   snt_gsc_requested_marks().
 * @param string[] $paths   The request and stuck rows' paths.
 * @param int      $now
 * @return array{status:array,indexed_after_request:array}
 */
function snt_gsc_request_statuses( array $entries, array $marks, array $paths, $now ) {
	$marks  = snt_gsc_requested_prune( $marks, $entries, $now ); // the 30- and 90-day rules hold on every read, not only on a write.
	$status = array();
	foreach ( array_unique( $paths ) as $p ) {
		$status[ (string) $p ] = snt_gsc_request_status( $entries[ $p ] ?? array(), $marks[ $p ] ?? 0, $now );
	}
	$after = array();
	foreach ( $marks as $p => $at ) {
		if ( ! empty( $entries[ $p ]['indexed'] ) ) {
			$after[] = array( 'path' => (string) $p, 'url' => (string) ( $entries[ $p ]['url'] ?? '' ), 'requested_at' => (int) $at );
		}
	}
	return array( 'status' => $status, 'indexed_after_request' => $after );
}

/** Marked paths still waiting on Google inside the quiet window. PURE. */
function snt_gsc_quiet_paths( array $entries, array $marks, $now ) {
	$out = array();
	foreach ( $marks as $p => $at ) {
		if ( snt_gsc_request_status( $entries[ $p ] ?? array(), $at, $now )['quiet'] ) {
			$out[] = (string) $p;
		}
	}
	return $out;
}

/** Drop marks off the map, indexed past the keep window, or past the max. PURE. */
function snt_gsc_requested_prune( array $marks, array $entries, $now ) {
	$keep = array();
	foreach ( $marks as $p => $at ) {
		$age = (int) $now - (int) $at;
		if ( isset( $entries[ $p ] ) && $age < SNT_GSC_REQUEST_MAX_DAYS * DAY_IN_SECONDS && ! ( ! empty( $entries[ $p ]['indexed'] ) && $age >= SNT_GSC_REQUEST_KEEP_DAYS * DAY_IN_SECONDS ) ) {
			$keep[ $p ] = (int) $at;
		}
	}
	return $keep;
}

/**
 * Mark a path requested. Only a path the inspection map holds and Google has
 * not indexed: the form's value is a key, never free text.
 *
 * @param string $path Join path.
 * @param int    $now
 * @return bool
 */
function snt_gsc_mark_requested( $path, $now ) {
	$d       = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	$entries = is_array( $d ) ? (array) ( $d['entries'] ?? array() ) : array();
	$path    = (string) $path;
	if ( ! isset( $entries[ $path ] ) || ! empty( $entries[ $path ]['indexed'] ) ) {
		return false;
	}
	$marks          = snt_gsc_requested_marks();
	$marks[ $path ] = (int) $now;
	update_option( SNT_GSC_REQUESTED_OPTION, snt_gsc_requested_prune( $marks, $entries, $now ), false );
	return true;
}

require_once __DIR__ . '/search-console-check-now.php'; // Check now: the live inspection the owner presses.
