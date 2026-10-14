<?php
/**
 * Signal & Noise: edge-analytics rollup, the writes (the daily and dims upserts, the status bucket, the 5xx dims).
 *
 * Split from inc/edge-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** UPSERT exact daily rows. @return int rows written. */
function sn_edge_daily_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DAILY_TABLE;
	$cols  = array( 'requests', 'cached_requests', 'bytes', 'cached_bytes', 'threats', 'page_views', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx' );
	$ph    = array();
	$vals  = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['day'] ) ) {
			continue;
		}
		$ph[]   = '(%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)';
		$vals[] = (string) $r['day'];
		foreach ( $cols as $c ) {
			$vals[] = max( 0, (int) ( $r[ $c ] ?? 0 ) );
		}
	}
	if ( empty( $ph ) ) {
		return 0;
	}
	$updates = array();
	foreach ( $cols as $c ) {
		$updates[] = "{$c}=VALUES({$c})";
	}
	$sql = "INSERT INTO {$table} (day, " . implode( ', ', $cols ) . ') VALUES '
		. implode( ', ', $ph ) . ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
	return false === $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ? 0 : count( $ph );
}

/** UPSERT breakdown rows (day, dim, value, requests, bytes). @return int rows written. */
function sn_edge_dims_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}
	// Drop malformed rows first, then CHUNK. sn_edge_run_rollup() re-pulls
	// SN_EDGE_BACKFILL_DAYS days of countryMap + adaptive threat/colo/attack dims on
	// EVERY run, so this row set is unbounded — one INSERT of the whole set (5
	// placeholders each) can blow past MySQL's 65,535-placeholder statement limit,
	// making $wpdb->query() return false → 0 rows written → and, re-pulled each run,
	// never recovering. 100 rows/chunk (500 placeholders) matches every sibling upsert.
	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || empty( $r['day'] ) || '' === (string) ( $r['value'] ?? '' ) ) {
			continue;
		}
		$clean[] = $r;
	}
	if ( empty( $clean ) ) {
		return 0;
	}
	global $wpdb;
	$table   = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	$written = 0;
	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$ph   = array();
		$vals = array();
		foreach ( $chunk as $r ) {
			$ph[]   = '(%s, %s, %s, %d, %d)';
			array_push( $vals, (string) $r['day'], (string) $r['dim'], mb_substr( (string) $r['value'], 0, 160, 'UTF-8' ), max( 0, (int) ( $r['requests'] ?? 0 ) ), max( 0, (int) ( $r['bytes'] ?? 0 ) ) );
		}
		$sql = "INSERT INTO {$table} (day, dim, value, requests, bytes) VALUES "
			. implode( ', ', $ph ) . ' ON DUPLICATE KEY UPDATE requests=VALUES(requests), bytes=VALUES(bytes)';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		if ( false !== $wpdb->query( $wpdb->prepare( $sql, $vals ) ) ) {
			$written += count( $ph );
		}
	}
	return $written;
}

/** Bucket an HTTP status code to its class column key (2xx..5xx); others ignored. */
function sn_edge_status_bucket( $status ) {
	$c = (int) floor( (int) $status / 100 );
	return ( $c >= 2 && $c <= 5 ) ? 'status_' . $c . 'xx' : '';
}

/**
 * Map 5xx rows onto the two dimensions worth storing (#1002).
 *
 * PURE, and separate from the rollup, because the rollup can only be exercised
 * through a stubbed database — the first version of this lived inline and every
 * assertion about it read zero rows, which looked like a broken feature and was
 * a test reading the wrong side of a DB stub.
 *
 *   err_path    which URLs failed
 *   err_path_asker  per path, who asked and who answered (the break alert)
 *   err_source  WHO answered. `edge=503 origin=503` is the origin, or the cache
 *               in front of it, failing. `edge=503 origin=-` is Cloudflare or a
 *               Worker answering by itself. That distinction is the one datum
 *               eight external reproduction attempts could not produce.
 *
 * An absent origin status renders as `-`, never 0: it means nothing upstream
 * answered, and a 0 in a column of status codes reads as a status code.
 *
 * @since 13.96.3
 * @param array $rows GraphQL `errors` groups.
 * @return array<string,array<string,int>> dim => value => corrected requests.
 */
function sn_edge_errors_dims( array $rows ) {
	$out = array();
	foreach ( $rows as $g ) {
		$d    = is_array( $g['dimensions'] ?? null ) ? $g['dimensions'] : array();
		$path = (string) ( $d['clientRequestPath'] ?? '' );
		if ( '' === $path ) {
			continue;
		}
		$req   = function_exists( 'sn_edge_corrected' ) ? sn_edge_corrected( $g ) : (int) ( $g['count'] ?? 0 );
		$edge  = (int) ( $d['edgeResponseStatus'] ?? 0 );
		$orig  = isset( $d['originResponseStatus'] ) && (int) $d['originResponseStatus'] > 0
			? (string) (int) $d['originResponseStatus']
			: '-';
		$cache = (string) ( $d['cacheStatus'] ?? '' );
		$from  = (string) ( $d['requestSource'] ?? '' );

		$out['err_path'][ $path ] = ( $out['err_path'][ $path ] ?? 0 ) + $req;
		$src = ( '' !== $from ? 'src=' . $from . ' ' : '' ) . 'edge=' . $edge . ' origin=' . $orig . ( '' !== $cache ? ' cache=' . $cache : '' );
		$out['err_source'][ $src ] = ( $out['err_source'][ $src ] ?? 0 ) + $req;
		// #1006: which path got which status. "<edge> <cache> <path>" in one value,
		// capped at the column's 160 by cutting the PATH end, never the prefix.
		$prefix = $edge . ' ' . ( '' !== $cache ? $cache : '-' ) . ' ';
		$ps     = $prefix . substr( $path, 0, max( 0, 160 - strlen( $prefix ) ) );
		$out['err_path_status'][ $ps ] = ( $out['err_path_status'][ $ps ] ?? 0 ) + $req;
		// Who asked for which path, with who answered: "<asker> <edge> <origin>
		// <cache> <path>", the path cut to the column. err_source has the asker
		// but no path and err_path_status the path but no asker, so a break
		// alert could not tell a visitor's 522 from a Worker's 503 (2026-10-08).
		// The asker words are sn_edge_error_asker()'s, inline: this function stays
		// self-contained (tests/edge-analytics-sees-5xx.php lifts it out alone).
		$ask = 'eyeball' === $from ? 'visitor' : ( 0 === strpos( $from, 'edgeWorker' ) ? 'worker' : ( '' === $from ? 'unrecorded' : 'other' ) );
		$pa  = $ask . ' ' . $edge . ' ' . $orig . ' ' . ( '' !== $cache ? $cache : '-' ) . ' ';
		$pa = $pa . substr( $path, 0, max( 0, 160 - strlen( $pa ) ) );
		$out['err_path_asker'][ $pa ] = ( $out['err_path_asker'][ $pa ] ?? 0 ) + $req;
	}

	return $out;
}

