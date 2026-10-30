<?php
/**
 * Signal & Noise Tools: unmatched paths, the read half (2026-10-29). Reads the
 * daily analytics table, which keeps the path every pageview reported, and
 * hands the rows to the pure half (inc/unmatched-paths.php). All history the
 * table holds counts; it has never stored the HTTP status or the referrer per
 * path, so those read "not recorded" until the beacon sends them.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/unmatched-paths.php';

const SNT_UNMATCHED_MAX_STORED = 20000; // Stored (path, class) groups read; past it the report says so.

/**
 * Every stored (path, class) group, all time, with its first and last day.
 *
 * @return array{rows:array,capped:bool}
 */
function snt_unmatched_stored_rows() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! defined( 'SN_ANALYTICS_DAILY_TABLE' ) ) {
		return array( 'rows' => array(), 'capped' => false );
	}
	$sql  = 'SELECT path, class, MIN(day) AS first_day, MAX(day) AS last_day, SUM(views) AS views, SUM(visits) AS visits FROM '
		. $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE . ' GROUP BY path, class LIMIT %d';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, SNT_UNMATCHED_MAX_STORED + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is the prefix and a constant; the cap is a placeholder.
	$rows = is_array( $rows ) ? $rows : array();
	return array( 'rows' => array_slice( $rows, 0, SNT_UNMATCHED_MAX_STORED ), 'capped' => count( $rows ) > SNT_UNMATCHED_MAX_STORED );
}

/**
 * The whole report: people and automated rows, each joined to what the 404
 * log saw, and whether the read was cut. The probe test and the suggester are
 * the 404 log's own (inc/redirects-404-log.php), so the site keeps one probe
 * vocabulary and one way of guessing a redirect.
 *
 * @param int $suggest_limit People rows that get a redirect suggestion.
 * @return array{people:array,automated:array,capped:bool}
 */
function snt_unmatched_report( $suggest_limit = PHP_INT_MAX ) {
	$real       = function_exists( 'snt_arows_real_paths' ) ? snt_arows_real_paths() : array( '/' => true );
	$candidates = function_exists( 'sn_404_published_paths' ) ? sn_404_published_paths() : array();
	$suggest    = static fn( $clean ) => function_exists( 'sn_404_suggest_target' ) ? sn_404_suggest_target( $clean, $candidates ) : '';
	$is_probe   = static fn( $clean ) => function_exists( 'sn_404_should_capture' ) && ! sn_404_should_capture( $clean );
	$read       = snt_unmatched_stored_rows();
	$report     = snt_unmatched_report_rows( $read['rows'], $real, $suggest, $is_probe, $suggest_limit );
	$log        = function_exists( 'sn_404_log_all' ) ? sn_404_log_all() : array();
	return array(
		'people'    => snt_unmatched_join_404( $report['people'], $log ),
		'automated' => snt_unmatched_join_404( $report['automated'], $log ),
		'capped'    => $read['capped'],
	);
}
