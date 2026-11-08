<?php
/**
 * Signal & Noise Tools: when each 5xx happened.
 *
 * The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s
 * "on the home page" could not be held up against anything: there was no time
 * to look for. Each error group now also keeps its minute (UTC) and its
 * Cloudflare colo. No IP address is stored.
 *
 * 2026-11-08: no network. The zone's plan refuses clientAsn and
 * clientASNDescription on this dataset, so every run since the first failed
 * whole and stored nothing (its reason sat in sn_edge_errors_when_last). Read
 * live, those 522s were app.juanlentino.com, a Railway app on the same zone;
 * the query now reads the site's own hosts only (sn_edge_site_hosts()).
 *
 * ITS OWN QUERY, for the reason sn_edge_errors_query() is apart from the attack
 * query: GraphQL fails a whole document on one unknown field, and the counts
 * must survive a refusal here. Grouping by minute also multiplies the rows,
 * which would crowd the counts' own limit.
 *
 * Local door only. The remote edge-errors twin copies named keys, so `when`
 * never reaches its contract.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_EDGE_ERRORS_WHEN_OPT   = 'sn_edge_errors_when_last'; // The query's last outcome: at, day, error, capped.
const SN_EDGE_ERRORS_WHEN_LIMIT = 100; // Groups per day; 5xx are rare here (26 on 2026-11-02, the worst of that week).

/**
 * The 5xx of a window, one group per minute, status, colo and path.
 *
 * @return string GraphQL document.
 */
function sn_edge_errors_when_query() {
	return 'query($zone:string!,$hosts:[string!]!,$from:Time!,$to:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'errors:httpRequestsAdaptiveGroups(limit:' . SN_EDGE_ERRORS_WHEN_LIMIT . ',filter:{clientRequestHTTPHost_in:$hosts,datetime_geq:$from,datetime_lt:$to,edgeResponseStatus_geq:500,AND:[{requestSource_neq:"earlyHintsCache"},{requestSource_neq:"edgeWorkerCacheAPI"}]},orderBy:[datetimeMinute_ASC]){'
		. 'count avg{sampleInterval}'
		. 'dimensions{datetimeMinute clientRequestPath edgeResponseStatus coloCode requestSource}}'
		. '}}}';
}

/**
 * Map the groups onto err_when rows, each filed under the day it happened. PURE.
 *
 * Value: "<minute> <asker> <edge> <colo> <path>", cut to the column's 160 by
 * shortening the path, never the prefix.
 *
 * @param array $rows GraphQL `errors` groups.
 * @return array<int,array{day:string,dim:string,value:string,requests:int,bytes:int}>
 */
function sn_edge_errors_when_rows( array $rows ) {
	$out = array();
	foreach ( $rows as $g ) {
		$d      = is_array( $g ) && is_array( $g['dimensions'] ?? null ) ? $g['dimensions'] : array();
		$minute = (string) ( $d['datetimeMinute'] ?? '' );
		$path   = (string) ( $d['clientRequestPath'] ?? '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $minute ) || '' === $path ) {
			continue;
		}
		$from = (string) ( $d['requestSource'] ?? '' );
		$ask  = 'eyeball' === $from ? 'visitor' : ( 0 === strpos( $from, 'edgeWorker' ) ? 'worker' : ( '' === $from ? 'unrecorded' : 'other' ) );
		$colo = (string) ( $d['coloCode'] ?? '' );
		$path = str_replace( ' ', '%20', $path ); // The reader splits on spaces.
		$head = substr( $minute, 0, 16 ) . ' ' . $ask . ' ' . (int) ( $d['edgeResponseStatus'] ?? 0 ) . ' ' . ( '' !== $colo ? $colo : '-' ) . ' ';
		// mb_strcut, not substr: half a character is invalid UTF-8 in a bound parameter.
		$val = $head . mb_strcut( $path, 0, 160 - strlen( $head ), 'UTF-8' );
		$req = function_exists( 'sn_edge_corrected' ) ? sn_edge_corrected( $g ) : (int) ( $g['count'] ?? 0 );
		if ( isset( $out[ $val ] ) ) {
			$out[ $val ]['requests'] += $req;
			continue;
		}
		$out[ $val ] = array( 'day' => substr( $minute, 0, 10 ), 'dim' => 'err_when', 'value' => $val, 'requests' => $req, 'bytes' => 0 );
	}
	return array_values( $out );
}

/**
 * The stored rows back as fields, newest first, with the query's last outcome.
 * `query` null means no run has stored one, so an empty list is not a clean read.
 * `more` says older rows exist past the limit.
 *
 * Its own read, not sn_edge_top_dim(): that keeps the rows with the most
 * requests, and an error is usually one request, so a busy week would lose the
 * newest ones. The value starts with its minute, so sorting it sorts by time.
 *
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array{rows:array,more:bool,query:array|null}
 */
function sn_edge_errors_when_reading( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_EDGE_DIMS_TABLE;
	// The table name is the prefix and a constant; every value is a placeholder.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT value, SUM(requests) AS requests
		 FROM {$table} WHERE day >= %s AND day <= %s AND dim = %s
		 GROUP BY value ORDER BY value DESC LIMIT %d",
		(string) $from,
		(string) $to,
		'err_when',
		SN_EDGE_ERRORS_WHEN_LIMIT
	), ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = array();
	foreach ( $found as $r ) {
		if ( 1 === preg_match( '/^(\S+) (\S+) (\d+) (\S+) (\S+)$/', (string) ( $r['value'] ?? '' ), $m ) ) {
			$rows[] = array( 'at' => $m[1], 'asker' => $m[2], 'edge' => (int) $m[3], 'colo' => $m[4], 'path' => $m[5], 'requests' => (int) ( $r['requests'] ?? 0 ) );
		}
	}
	$query = get_option( SN_EDGE_ERRORS_WHEN_OPT, null );
	return array( 'rows' => $rows, 'more' => SN_EDGE_ERRORS_WHEN_LIMIT <= count( $found ), 'query' => is_array( $query ) ? $query : null );
}
