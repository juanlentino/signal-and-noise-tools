<?php
/**
 * Signal & Noise Tools: unmatched paths, the read half (2026-10-29). Reads the
 * daily analytics table, which keeps the path every pageview reported, and
 * hands the rows to the pure half (inc/unmatched-paths.php). All history the
 * table holds counts. It has never stored the HTTP status or the referrer per
 * path; those come from the 404 log (snt_unmatched_join_404()).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/unmatched-paths.php';

const SNT_UNMATCHED_MAX_STORED = 20000; // Stored (path, class) groups read; past it the report says so.
const SNT_UNMATCHED_PROBE_MAX  = 50;    // People rows checked live per scan, the most viewed first.

/**
 * Every stored (path, class) group, all time, with its first and last day,
 * the most viewed first, so a cap drops the tail. A read that failed says so
 * in `error`: an empty answer would read as "nothing to fix".
 *
 * @return array{rows:array,capped:bool,error:?string}
 */
function snt_unmatched_stored_rows() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! defined( 'SN_ANALYTICS_DAILY_TABLE' ) ) {
		return array( 'rows' => array(), 'capped' => false, 'error' => __( 'The analytics table is not available.', 'signal-and-noise-tools' ) );
	}
	$sql  = 'SELECT path, class, MIN(day) AS first_day, MAX(day) AS last_day, SUM(views) AS views, SUM(visits) AS visits FROM '
		. $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE . ' GROUP BY path, class ORDER BY SUM(views) DESC LIMIT %d';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, SNT_UNMATCHED_MAX_STORED + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is the prefix and a constant; the cap is a placeholder.
	if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
		return array( 'rows' => array(), 'capped' => false, 'error' => __( 'The analytics read failed, so the paths are not known.', 'signal-and-noise-tools' ) );
	}
	return array( 'rows' => array_slice( $rows, 0, SNT_UNMATCHED_MAX_STORED ), 'capped' => count( $rows ) > SNT_UNMATCHED_MAX_STORED, 'error' => null );
}

/**
 * The site's live answer for one cleaned path: the broken-links check's own
 * probe (sn_health_link_status(): same host only, HEAD, no redirect followed,
 * cached a day, a Cloudflare challenge read as no answer). Null when it did not
 * answer, so the row keeps what the 404 log said.
 *
 * @param string $clean A cleaned path with no placeholder.
 * @return int|null
 */
function snt_unmatched_live_status( $clean ) {
	if ( ! function_exists( 'sn_health_link_status' ) ) {
		return null;
	}
	$r = sn_health_link_status( home_url( user_trailingslashit( $clean ) ) );
	return empty( $r['skipped'] ) && ! empty( $r['code'] ) ? (int) $r['code'] : null;
}

/**
 * The whole report: people, the paths the site serves or already redirects,
 * and automated rows, each joined to what the 404 log saw, the top people rows
 * checked live, and whether the read was cut. The probe test and the suggester
 * are the 404 log's own (inc/redirects-404-log.php), so the site keeps one
 * probe vocabulary and one way of guessing a redirect.
 *
 * @param int $suggest_limit People rows that get a redirect suggestion.
 * @return array{people:array,served:array,automated:array,capped:bool,error:?string}
 */
function snt_unmatched_report( $suggest_limit = PHP_INT_MAX ) {
	$real       = function_exists( 'snt_arows_real_paths' ) ? snt_arows_real_paths() : array( '/' => true );
	$candidates = function_exists( 'sn_404_published_paths' ) ? sn_404_published_paths() : array();
	$suggest    = static fn( $clean ) => function_exists( 'sn_404_suggest_target' ) ? sn_404_suggest_target( $clean, $candidates ) : '';
	$is_probe   = static fn( $clean ) => function_exists( 'sn_404_should_capture' ) && ! sn_404_should_capture( $clean );
	$read       = snt_unmatched_stored_rows();
	$report     = snt_unmatched_report_rows( $read['rows'], $real, $suggest, $is_probe, $suggest_limit );
	$log        = function_exists( 'sn_404_log_all' ) ? sn_404_log_all() : array();
	$people     = snt_unmatched_check_live( snt_unmatched_join_404( $report['people'], $log ), 'snt_unmatched_live_status', SNT_UNMATCHED_PROBE_MAX );
	$is_served  = static fn( $row ) => null !== $row['served'];
	return array(
		'people'    => array_values( array_filter( $people, static fn( $row ) => ! $is_served( $row ) ) ),
		'served'    => array_values( array_filter( $people, $is_served ) ),
		'automated' => snt_unmatched_join_404( $report['automated'], $log ),
		'capped'    => $read['capped'],
		'error'     => $read['error'],
	);
}
