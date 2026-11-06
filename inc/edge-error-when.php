<?php
/**
 * Signal & Noise Tools: when each 5xx happened, and from which network.
 *
 * The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s
 * on the home page could not be held up against the origin's firewall: there
 * was no time to look for. Each error group now also keeps its minute (UTC),
 * its Cloudflare colo and the client's network. No IP address is stored.
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
 * The 5xx of a window, one group per minute, status, colo, network and path.
 *
 * @return string GraphQL document.
 */
function sn_edge_errors_when_query() {
	return 'query($zone:string!,$from:Time!,$to:Time!){viewer{zones(filter:{zoneTag:$zone}){'
		. 'errors:httpRequestsAdaptiveGroups(limit:' . SN_EDGE_ERRORS_WHEN_LIMIT . ',filter:{datetime_geq:$from,datetime_lt:$to,edgeResponseStatus_geq:500,AND:[{requestSource_neq:"earlyHintsCache"},{requestSource_neq:"edgeWorkerCacheAPI"}]},orderBy:[datetimeMinute_ASC]){'
		. 'count avg{sampleInterval}'
		. 'dimensions{datetimeMinute clientRequestPath edgeResponseStatus coloCode clientAsn clientASNDescription requestSource}}'
		. '}}}';
}

/**
 * Map the groups onto err_when rows, each filed under the day it happened. PURE.
 *
 * Value: "<minute> <asker> <edge> <colo> <asn> <path> <network>", cut to the
 * column's 160 by shortening the path and the network, never the prefix.
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
		$name = str_replace( ' ', '-', (string) ( $d['clientASNDescription'] ?? '' ) );
		$head = substr( $minute, 0, 16 ) . ' ' . $ask . ' ' . (int) ( $d['edgeResponseStatus'] ?? 0 ) . ' ' . ( '' !== $colo ? $colo : '-' ) . ' ' . (int) ( $d['clientAsn'] ?? 0 ) . ' ';
		$name = substr( $name, 0, 32 );
		$path = substr( $path, 0, 160 - strlen( $head ) - ( '' !== $name ? strlen( $name ) + 1 : 0 ) );
		$val  = $head . $path . ( '' !== $name ? ' ' . $name : '' );
		$req  = function_exists( 'sn_edge_corrected' ) ? sn_edge_corrected( $g ) : (int) ( $g['count'] ?? 0 );
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
 *
 * @param string $from First day, YYYY-MM-DD.
 * @param string $to   Last day, YYYY-MM-DD.
 * @return array{rows:array,query:array|null}
 */
function sn_edge_errors_when_reading( $from, $to ) {
	$rows = array();
	foreach ( sn_edge_top_dim( 'err_when', (string) $from, (string) $to, SN_EDGE_ERRORS_WHEN_LIMIT ) as $r ) {
		if ( 1 === preg_match( '/^(\S+) (\S+) (\d+) (\S+) (\d+) (\S+) ?(.*)$/', (string) ( $r['value'] ?? '' ), $m ) ) {
			$rows[] = array( 'at' => $m[1], 'asker' => $m[2], 'edge' => (int) $m[3], 'colo' => $m[4], 'asn' => (int) $m[5], 'path' => $m[6], 'network' => $m[7], 'requests' => (int) ( $r['requests'] ?? 0 ) );
		}
	}
	usort( $rows, static fn( $a, $b ) => strcmp( $b['at'], $a['at'] ) );
	$query = get_option( SN_EDGE_ERRORS_WHEN_OPT, null );
	return array( 'rows' => $rows, 'query' => is_array( $query ) ? $query : null );
}
