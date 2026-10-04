<?php
/**
 * Research links followed: visitor-days that clicked from the site out to
 * where the research lives (SSRN, doi.org, Zenodo, ORCID, the AES journal).
 *
 * The beacon records an outbound click as a `ce` row plus one `cp` property
 * row carrying the destination host (blob16 outbound, blob17 host, blob18 the
 * hostname; analytics worker src/index.js). The session query never reads
 * `cp` rows and other features share it, so this is its own small query.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

require_once __DIR__ . '/analytics-human-rule.php'; // the ONE counted-human rule

/** Hosts that count as research destinations; a subdomain matches its parent. */
const SNT_NSM_RESEARCH_HOSTS = array( 'ssrn.com', 'doi.org', 'zenodo.org', 'orcid.org', 'aes.org' );

/**
 * Is a hostname one of the research destinations (or a subdomain of one)? PURE.
 *
 * @param string $host Hostname.
 * @return bool
 */
function snt_nsm_is_research_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	foreach ( (array) apply_filters( 'snt_nsm_research_hosts', SNT_NSM_RESEARCH_HOSTS ) as $h ) {
		if ( $host === $h || ( strlen( $host ) > strlen( $h ) && '.' . $h === substr( $host, -strlen( $h ) - 1 ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Distinct visitor-days per rolling week (week 0 = the last 7 days) among
 * rows of {vid, ts, host}. PURE.
 *
 * @param array $rows Rows.
 * @param int   $now  Epoch seconds.
 * @return int[] SNT_NSM_WEEKS counts.
 */
function snt_nsm_research_weeks( array $rows, $now ) {
	$seen = array_fill( 0, SNT_NSM_WEEKS, array() );
	foreach ( $rows as $r ) {
		if ( ! snt_nsm_is_research_host( $r['host'] ?? '' ) || '' === (string) ( $r['vid'] ?? '' ) ) {
			continue;
		}
		$i = (int) floor( ( $now - (int) ( $r['ts'] ?? 0 ) ) / ( 7 * 86400 ) );
		if ( $i >= 0 && $i < SNT_NSM_WEEKS ) {
			$seen[ $i ][ (string) $r['vid'] ] = true;
		}
	}
	return array_map( 'count', $seen );
}

/**
 * This week's research-link visitor-days, or null when the read failed.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @param int    $now  Epoch seconds.
 * @return int|null
 */
function snt_nsm_research_links( $from, $to, $now ) {
	// The dates go into the SQL string, so they must be exactly Y-m-d.
	if ( ! function_exists( 'sn_analytics_query' ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
		return null;
	}
	// Property rows: the events dataset in the second generation, where the
	// name, property and value sit one column later (sn_analytics_col()).
	$dataset = sn_analytics_source( (string) $from, 'events' );
	$c       = static fn( $col ) => sn_analytics_col( $col, $dataset );
	$rows    = sn_analytics_query(
		'SELECT index1 AS vid, toUnixTimestamp(timestamp) AS ts, ' . $c( 'blob18' ) . " AS host FROM {$dataset}"
		. " WHERE timestamp >= toDateTime('{$from} 00:00:00') AND timestamp <= toDateTime('{$to} 23:59:59')"
		. " AND " . sn_analytics_class_where( 'human' ) . " AND blob1 = 'cp' AND " . $c( 'blob16' ) . " = 'outbound' AND " . $c( 'blob17' ) . " = 'host' LIMIT 10000"
	);
	return is_array( $rows ) ? snt_nsm_research_weeks( $rows, $now )[0] : null;
}
