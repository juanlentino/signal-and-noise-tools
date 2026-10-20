<?php
/**
 * Signal & Noise: scanner probes in the stored 5xx (2026-10-19).
 *
 * A direct request to a core LIBRARY file (a .php under /wp-includes/ or
 * /wp-admin/includes/) fatals, because the file expects WordPress to have
 * loaded it. WordPress never routes a request there, so a 5xx on one is a
 * scanner, not the site failing: 26 of the week's 177 stored 5xx were these.
 * They are counted apart as `probes` (still visible, with paths) and left out
 * of `total`.
 *
 * Deliberately NOT probes: the /wp-admin/*.php screens (edit.php,
 * customize.php). They are routed pages the owner opens, so a 500 there may be
 * a real admin failure; SNT_EDGE_DASHBOARD_PATH_RE files them as dashboard.
 *
 * Self-contained: the edge rollup and the desktop install verdict both
 * require it, and tests/edge-error-probes.php loads it alone.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core library and bootstrap files: never a legitimate direct request. The
 * root five are loaded by WordPress itself, never routed (wp-login.php,
 * wp-cron.php, wp-comments-post.php and the like are entry points, not these).
 */
const SN_EDGE_PROBE_PATH_RE = '#^/((wp-includes|wp-admin/includes)/.*\.php|wp-(settings|load|blog-header|config|config-sample)\.php)$#i';

/**
 * Whether a stored 5xx path is a scanner probe. PURE.
 *
 * @param string $path Request path.
 * @return bool
 */
function sn_edge_error_is_probe( $path ) {
	return 1 === preg_match( SN_EDGE_PROBE_PATH_RE, (string) $path );
}

/**
 * Split err_path rows into the failures and the probes. PURE.
 *
 * @param array $rows err_path rows {value, requests, bytes}, most first.
 * @return array{paths:array,probes:array{total:int,paths:array}} Top 10 of each.
 */
function sn_edge_errors_split_probes( array $rows ) {
	$paths  = array();
	$probes = array();
	$total  = 0;
	foreach ( $rows as $r ) {
		if ( sn_edge_error_is_probe( (string) ( $r['value'] ?? '' ) ) ) {
			$total   += (int) ( $r['requests'] ?? 0 );
			$probes[] = $r;
		} else {
			$paths[] = $r;
		}
	}
	return array(
		'paths'  => array_slice( $paths, 0, 10 ),
		'probes' => array( 'total' => $total, 'paths' => array_slice( $probes, 0, 10 ) ),
	);
}

/**
 * Give each day row its `probes` and take them out of its `total`, clamped
 * at 0. PURE. The day totals come from err_source, which has no path, so
 * who-asked (visitor, worker, ...) is NOT reduced: those still sum to
 * total + probes.
 *
 * @param array $days Day rows, each with day and total.
 * @param array $rows err_path rows {day, value, requests}.
 * @return array The same rows, each with `probes`.
 */
function sn_edge_errors_days_probes( array $days, array $rows ) {
	$by_day = array();
	foreach ( $rows as $r ) {
		if ( sn_edge_error_is_probe( (string) ( $r['value'] ?? '' ) ) ) {
			$day            = (string) ( $r['day'] ?? '' );
			$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + (int) ( $r['requests'] ?? 0 );
		}
	}
	foreach ( $days as $i => $d ) {
		$n                    = $by_day[ (string) ( $d['day'] ?? '' ) ] ?? 0;
		$days[ $i ]['probes'] = $n;
		$days[ $i ]['total']  = max( 0, (int) ( $d['total'] ?? 0 ) - $n );
	}
	return $days;
}

/**
 * The stored err_path rows per day over [$from,$to], for the day split.
 *
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array Rows {day, value, requests}.
 */
function sn_edge_errors_path_days( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	// The table name is the prefix and a constant; every value is a placeholder.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT day, value, SUM(requests) AS requests
		 FROM {$table} WHERE day >= %s AND day <= %s AND dim = %s
		 GROUP BY day, value",
		(string) $from,
		(string) $to,
		'err_path'
	), ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $rows;
}
