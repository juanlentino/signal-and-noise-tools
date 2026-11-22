<?php
/**
 * CLS shift sources (inc/analytics-vitals-sources.php): which page elements
 * account for the not-good CLS readings. The SQL shape, the fold (sort, cap,
 * sample correction, worst as a score), the unattributed count, a failed leg
 * that leaves the rest of the vitals payload standing. The ability's schema
 * and description: tests/analytics-vitals-sources-on-core.php.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_AE_ROW_CAP', 200 );
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__t'] = array();
$GLOBALS['__ttl'] = array();
function get_transient( $k ) {
	if ( SNT_ANALYTICS_VDAY_CACHE_KEY === $k ) { return array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); }
	return $GLOBALS['__t'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; $GLOBALS['__ttl'][ $k ] = $ttl; return true; }
$GLOBALS['__q'] = array();
$GLOBALS['__src_rows'] = array();
$GLOBALS['__none_rows'] = array( array( 'w' => '7' ) );
function sn_analytics_query( $sql ) {
	$GLOBALS['__q'][] = $sql;
	if ( false !== strpos( $sql, "blob17 != ''" ) ) { return $GLOBALS['__src_rows']; }
	if ( false !== strpos( $sql, "blob17 = ''" ) ) { return $GLOBALS['__none_rows']; }
	return false !== strpos( $sql, 'blob2 IN' ) ? array() : array( array( 'e' => 'vc', 'k' => 'mobile', 'v' => '120', 'w' => '40' ) );
}
function sn_analytics_top_paths( $from, $to, $class, $limit ) { return array( array( 'path' => '/notes/a/' ) ); }
$GLOBALS['__ae_err'] = null;
function sn_analytics_last_error( $since = 0 ) { $e = $GLOBALS['__ae_err']; return is_array( $e ) && (int) $e['when'] >= (int) $since ? $e : null; }
function sn_analytics_canonical_path( $p ) { $t = rtrim( (string) $p, '/' ); return '' === $t ? '/' : $t; }

require_once __DIR__ . '/../inc/analytics-vitals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
$has = static fn( $name ) => function_exists( $name );

echo "CLS shift sources\n\n";

echo "Group: the SQL\n";
$sql = $has( 'sn_analytics_vitals_sources_sql' ) ? sn_analytics_vitals_sources_sql( true, '2026-09-14', '2026-10-11', 'human', 'sn_pageviews_v2' ) : '';
ok( 0 === strpos( $sql, "SELECT blob2 AS k, blob17 AS s, sum(_sample_interval) AS w, sum(if(double7 > 250, _sample_interval, 0)) AS p, max(double7) AS m FROM sn_pageviews_v2 WHERE blob1 = 'vc' AND double7 > 100 AND blob17 != ''" ), 'sources: path, source, weighted readings, weighted poor, worst; vc rows only, over the good threshold (x1000), with a source' );
ok( 2 === substr_count( $sql, 'blob17' ) && false === strpos( $sql, "blob1 IN" ), 'blob17 is read only beside blob1 = \'vc\' (on pv rows it is the UTM source, on ce rows the event name)' );
ok( false !== strpos( $sql, ' AND ' . sn_analytics_class_where( 'human' ) ) && false !== strpos( sn_analytics_vitals_sources_sql( true, '2026-09-14', '2026-10-11', 'bot', 'sn_pageviews_v2' ), sn_analytics_class_where( 'bot' ) ), 'the same traffic class filter as the rest of the payload' );
ok( ' GROUP BY k, s' === substr( $sql, -14 ) && 1 !== preg_match( '/GROUP BY[^\n]*\(/', $sql ), 'grouped by path and source, aliases only (AE refuses a function there)' );
ok( false !== strpos( $sql, "toDateTime('2026-09-14 00:00:00')" ) && false !== strpos( $sql, "toDateTime('2026-10-11 23:59:59')" ), 'the site-day window bounds' );
ok( false !== strpos( $sql, sn_analytics_excluded_path_sql() ), 'asset and admin paths are left out the way the daily table leaves them out' );
ok( false === strpos( $sql, 'HAVING' ) && false === strpos( $sql, '(SELECT' ) && false === strpos( $sql, 'LIMIT' ), 'no subquery, no HAVING, no LIMIT (a LIMIT would cut a group the PHP sort has not seen)' );
preg_match_all( '/\bif\(([^()]*)\)/', $sql, $ifs );
ok( array( 'double7 > 250, _sample_interval, 0' ) === $ifs[1], 'the one if() is the weighted-sum form the other reads already send' );
$half = sn_analytics_vitals_sources_sql( true, '2026-09-14', '2026-10-11', 'human', 'sn_pageviews', " AND timestamp < toDateTime('2026-10-05 04:00:00')" );
ok( false !== strpos( $half, 'FROM sn_pageviews WHERE' ) && false !== strpos( $half, "blob17 != ''" ) && false !== strpos( $half, "toDateTime('2026-10-05 04:00:00') GROUP BY" ), 'a stitched half: the legacy dataset spells the column the same, one side of the split' );
ok( false === strpos( sn_analytics_vitals_sources_sql( true, '2026-09-14', '2026-10-11', 'human', 'sn_pageviews', ' OR 1=1' ), '1=1' ), 'a range that is not one side of the split never reaches the SQL' );
$nsql = sn_analytics_vitals_sources_sql( false, '2026-09-14', '2026-10-11', 'human', 'sn_pageviews_v2' );
ok( 0 === strpos( $nsql, "SELECT sum(_sample_interval) AS w FROM sn_pageviews_v2 WHERE blob1 = 'vc' AND double7 > 100 AND blob17 = ''" ) && false === strpos( $nsql, 'GROUP BY' ), 'unattributed: one weighted count of the not-good readings that carried no source' );

echo "\nGroup: the fold\n";
$rows = array(
	array( 'k' => '/notes/a/', 's' => 'div.sn-footer__meta.is-layout-flex', 'w' => '6', 'p' => '2', 'm' => '310' ),
	array( 'k' => '/notes/a', 's' => 'div.sn-footer__meta.is-layout-flex', 'w' => '4', 'p' => '0', 'm' => '180' ),
	array( 'k' => '/', 's' => 'img#hero', 'w' => '25', 'p' => '25', 'm' => '1204.6' ),
	array( 'k' => '/notes/a', 's' => 'p', 'w' => '10', 'p' => '1', 'm' => '260' ),
	array( 'k' => "/x') OR 1=1 --", 's' => 'p', 'w' => '99', 'p' => '0', 'm' => '150' ),
	array( 'k' => '/notes/b', 's' => '', 'w' => '99', 'p' => '0', 'm' => '150' ),
);
$f = sn_analytics_vitals_sources_fold( $rows );
ok( array( 'path' => '/', 'source' => 'img#hero', 'readings' => 25, 'needs_improvement' => 0, 'poor' => 25, 'worst' => 1.205 ) === $f[0], 'a row: path, source, readings, the two bands as counts, worst as a score (stored x1000)' );
ok( '/notes/a' === $f[1]['path'] && 'div.sn-footer__meta.is-layout-flex' === $f[1]['source'] && 10 === $f[1]['readings'] && 8 === $f[1]['needs_improvement'] && 2 === $f[1]['poor'] && 0.31 === $f[1]['worst'], 'both spellings of a path (and both stitched halves) merge: readings and poor add, worst is the larger' );
ok( array( 25, 10, 10 ) === array_column( $f, 'readings' ) && 'p' === $f[2]['source'], 'sorted by readings, most first; a tie breaks on path then source, so the order is stable' );
ok( 3 === count( $f ), 'a path outside the allowlist and a row with no source are never listed' );
ok( 12 === sn_analytics_vitals_sources_fold( array( array( 'k' => '/a', 's' => 'p', 'w' => '12', 'p' => '4', 'm' => '300' ) ) )[0]['readings'], 'readings are the summed sample intervals (sample-corrected), not a row count' );
$many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$many[] = array( 'k' => '/p' . $i, 's' => 'div', 'w' => (string) ( $i + 1 ), 'p' => '0', 'm' => '120' );
}
$hostile = sn_analytics_vitals_sources_fold(
	array(
		array( 'k' => '/a', 's' => '<img onerror=x>', 'w' => '3', 'p' => '0', 'm' => '200' ),
		array( 'k' => '/a', 's' => '#starts-with-hash', 'w' => '3', 'p' => '0', 'm' => '200' ),
		array( 'k' => '/a', 's' => 'div.' . str_repeat( 'a', 200 ), 'w' => '3', 'p' => '0', 'm' => '200' ),
		array( 'k' => '/a', 's' => 'div.ok_1-x#y', 'w' => '3', 'p' => '0', 'm' => '200' ),
	)
);
ok( 1 === count( $hostile ) && 'div.ok_1-x#y' === $hostile[0]['source'], 'a stored source is checked again on the way out: markup, a leading #, and an overlong value are dropped' );
$capped = sn_analytics_vitals_sources_fold( $many );
ok( 50 === count( $capped ) && 60 === $capped[0]['readings'] && 11 === $capped[49]['readings'], 'capped at 50, after the sort: the 50 with the most readings' );

echo "\nGroup: the payload\n";
$GLOBALS['__src_rows'] = $rows;
$v = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( 4 === count( $GLOBALS['__q'] ), 'four queries: by device, by path, the sources, the unattributed count' );
ok( is_array( $v ) && 3 === count( $v['cls_sources'] ?? array() ) && '/' === $v['cls_sources'][0]['path'], 'the payload carries cls_sources' );
ok( 7 === ( $v['cls_sources_unattributed'] ?? null ) && '' === ( $v['cls_sources_failure'] ?? null ), 'and cls_sources_unattributed, with an empty failure' );
ok( 15 * MINUTE_IN_SECONDS === end( $GLOBALS['__ttl'] ), 'cached 15 minutes' );
sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( 4 === count( $GLOBALS['__q'] ), 'cached: a second read asks nothing' );

$GLOBALS['__t'] = array(); $GLOBALS['__q'] = array();
$GLOBALS['__src_rows'] = array();
$GLOBALS['__none_rows'] = array( array( 'w' => '3' ), array( 'w' => '2' ) );
$e = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( array() === $e['cls_sources'] && 5 === $e['cls_sources_unattributed'], 'no attributed reading: an empty list beside the unattributed count, so it is not read as "nothing shifted"' );
$GLOBALS['__t'] = array();
$GLOBALS['__none_rows'] = array();
$z = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( array() === $z['cls_sources'] && 0 === $z['cls_sources_unattributed'], 'a read that answered with no rows is a measured 0' );

$GLOBALS['__t'] = array(); $GLOBALS['__q'] = array();
$d0 = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 0 );
ok( 1 === count( $GLOBALS['__q'] ) && ! array_key_exists( 'cls_sources', $d0 ), 'devices-only (limit 0, the Engagement twins) still asks one query and carries no sources' );

echo "\nGroup: a failed sources read\n";
$GLOBALS['__t'] = array(); $GLOBALS['__q'] = array(); $GLOBALS['__ttl'] = array();
$GLOBALS['__src_rows'] = null;
$GLOBALS['__ae_err'] = array( 'url' => 'https://api.cloudflare.com/client/v4/accounts/SECRETACCT/analytics_engine/sql', 'code' => 422, 'message' => 'unknown column', 'when' => time() );
$bad = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( is_array( $bad ) && 40 === $bad['site']['cls']['samples'] && isset( $bad['devices']['mobile'], $bad['paths'][0] ), 'the rest of the payload stands' );
ok( array_key_exists( 'cls_sources', $bad ) && null === $bad['cls_sources'] && null === $bad['cls_sources_unattributed'], 'cls_sources is null and so is the count (unknown is never 0)' );
$why = sn_analytics_vitals_failure();
ok( 'the CLS sources read failed (Analytics Engine said HTTP 422: unknown column)' === $why && $why === $bad['cls_sources_failure'], 'the reason is recorded the way the other legs record theirs, and rides in the payload' );
ok( false === strpos( $why, 'SECRETACCT' ), 'the reason never carries the request URL' );
ok( 5 * MINUTE_IN_SECONDS === end( $GLOBALS['__ttl'] ), 'a payload with a failed leg is kept 5 minutes, like a failure' );
sn_analytics_vitals_failure( '' );
$again = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( null === $again['cls_sources'] && $why === sn_analytics_vitals_failure(), 'the cached payload still says why' );
$GLOBALS['__ae_err'] = null;
$GLOBALS['__t'] = array();
$GLOBALS['__src_rows'] = $rows;
$GLOBALS['__none_rows'] = null;
$half_bad = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( null === $half_bad['cls_sources'] && null === $half_bad['cls_sources_unattributed'] && 0 === strpos( sn_analytics_vitals_failure(), 'the CLS sources read failed' ), 'a failed unattributed count nulls both: a list without its count could read as complete' );
$GLOBALS['__t'] = array();
$GLOBALS['__none_rows'] = array();
$GLOBALS['__src_rows'] = array_fill( 0, 200, $rows[0] );
$cap = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( null === $cap['cls_sources'] && false !== strpos( $cap['cls_sources_failure'], 'row cap' ), 'a read at the row cap is partial: null, and the reason says the row cap' );
$GLOBALS['__t'] = array();
$GLOBALS['__src_rows'] = $rows;
$fine = sn_analytics_vitals( '2026-09-14', '2026-10-11', 'human', 10 );
ok( 3 === count( $fine['cls_sources'] ) && '' === sn_analytics_vitals_failure(), 'a read that answers leaves no reason behind' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
