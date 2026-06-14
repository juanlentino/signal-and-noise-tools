<?php
/**
 * Tests for inc/analytics-drilldown.php — cross-tab dimension drill-down.
 * Parse + SQL builder (proven AE primitives, no LIMIT, value-escaped) + the
 * whitelisted cached accessor. Mirrors tests/analytics-percentiles.php harness.
 * Run: php tests/analytics-drilldown.php
 * @since plugin v6.9.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DIM_COLUMNS', array(
	'referrer' => 'blob3', 'country' => 'blob4', 'device' => 'blob5', 'browser' => 'blob8',
	'os' => 'blob9', 'region' => 'blob10', 'city' => 'blob11', 'network' => 'blob12',
	'colo' => 'blob13', 'protocol' => 'blob14', 'tls' => 'blob15',
) );

// Transient seam (records TTL for assertions).
$GLOBALS['__dd_trans'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__dd_trans'] ) ? $GLOBALS['__dd_trans'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__dd_trans'][ $k ] = $v; $GLOBALS['__dd_last_ttl'] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__dd_trans'][ $k ] ); return true; }

// Durable top-N seam (the whitelist source).
$GLOBALS['__dd_top'] = array( array( 'value' => 'US', 'views' => 9 ), array( 'value' => "O'Hare", 'views' => 4 ) );
function sn_analytics_top_dimension( $dim, $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__dd_top']; }

// AE seam.
$GLOBALS['__dd_query_calls']  = array();
$GLOBALS['__dd_query_result'] = array(
	array( 'path' => '/b', 'views' => 5, 'visits' => 3 ),
	array( 'path' => '/a', 'views' => 20, 'visits' => 12 ),
);
function sn_analytics_query( $sql ) { $GLOBALS['__dd_query_calls'][] = $sql; return $GLOBALS['__dd_query_result']; }

require_once __DIR__ . '/../inc/analytics-drilldown.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function dd_reset() {
	$GLOBALS['__dd_trans']        = array();
	$GLOBALS['__dd_last_ttl']     = null;
	$GLOBALS['__dd_query_calls']  = array();
	$GLOBALS['__dd_top']          = array( array( 'value' => 'US', 'views' => 9 ), array( 'value' => "O'Hare", 'views' => 4 ) );
	$GLOBALS['__dd_query_result'] = array(
		array( 'path' => '/b', 'views' => 5, 'visits' => 3 ),
		array( 'path' => '/a', 'views' => 20, 'visits' => 12 ),
	);
}

echo "Analytics drill-down layer\n\n";

echo "Group: parse\n";
ok( sn_analytics_drilldown_parse( 'country:US' ) === array( 'country', 'US' ), 'parse: dim:value' );
ok( sn_analytics_drilldown_parse( 'referrer:a:b' ) === array( 'referrer', 'a:b' ), 'parse: splits on FIRST colon (value keeps colons)' );
ok( null === sn_analytics_drilldown_parse( 'martian:x' ), 'parse: unknown dim → null' );
ok( null === sn_analytics_drilldown_parse( 'country:' ), 'parse: empty value → null' );
ok( null === sn_analytics_drilldown_parse( 'noColon' ), 'parse: no colon → null' );

echo "\nGroup: SQL builder (proven AE primitives, no LIMIT, value-escaped)\n";
$sql = sn_analytics_drilldown_sql( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
ok( strpos( $sql, 'SELECT blob2 AS path,' ) !== false, 'sql: selects path (blob2) as the child' );
ok( strpos( $sql, 'sum(_sample_interval) AS views' ) !== false, 'sql: sample-corrected views' );
ok( strpos( $sql, 'count(DISTINCT index1) AS visits' ) !== false, 'sql: visits via count(DISTINCT bare column)' );
ok( strpos( $sql, "WHERE blob1 = 'pv' AND blob4 = 'US' AND blob7 = 'human'" ) !== false, 'sql: pv + parent col=value + class filters' );
ok( strpos( $sql, "timestamp >= toDateTime('2026-06-01 00:00:00')" ) !== false, 'sql: lower date bound' );
ok( strpos( $sql, "timestamp <= toDateTime('2026-06-30 23:59:59')" ) !== false, 'sql: upper date bound' );
ok( strpos( $sql, 'GROUP BY path' ) !== false && strpos( $sql, 'ORDER BY views DESC' ) !== false, 'sql: group + order' );
ok( stripos( $sql, 'LIMIT' ) === false, 'sql: NO LIMIT (unproven against AE — PHP-slices instead)' );
ok( strpos( $sql, 'count(*)' ) === false, 'sql: no count(*) (dialect-clean)' );
ok( '' === sn_analytics_drilldown_sql( 'martian', 'x', '2026-06-01', '2026-06-30', 'human' ), 'sql: unknown dim → empty' );

echo "\nGroup: SQL builder injection-safety\n";
$ev = sn_analytics_drilldown_sql( 'city', "O'Hare", '2026-06-01', '2026-06-30', 'human' );
ok( strpos( $ev, "blob11 = 'O\\'Hare'" ) !== false, 'sql: single-quote in value is escaped for the AE literal' );
ok( strpos( sn_analytics_drilldown_sql( 'country', "US", '2026-06-01', '2026-06-30', "human'; DROP" ), 'DROP' ) === false, 'sql: class allowlisted' );
ok( strpos( sn_analytics_drilldown_sql( 'country', 'US', "2026'; DROP", '2026-06-30', 'human' ), 'DROP' ) === false, 'sql: from re-validated YMD' );

echo "\nGroup: accessor — whitelist gates AE\n";
dd_reset();
$r = sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
ok( is_array( $r ) && count( $r ) === 2, 'accessor: returns rows for a top-N value' );
ok( $r[0]['path'] === '/a' && $r[0]['views'] === 20, 'accessor: PHP-sorted by views desc' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 1, 'accessor: one AE query for a valid value' );
dd_reset();
ok( null === sn_analytics_drilldown( 'country', "ZZ' OR 1=1", '2026-06-01', '2026-06-30', 'human' ), 'accessor: value NOT in top-N → null' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 0, 'accessor: rejected value never reaches AE (injection guard)' );

echo "\nGroup: accessor — top-15 slice\n";
dd_reset();
$many = array();
for ( $i = 0; $i < 30; $i++ ) { $many[] = array( 'path' => "/p$i", 'views' => $i, 'visits' => 1 ); }
$GLOBALS['__dd_query_result'] = $many;
$GLOBALS['__dd_top'] = array( array( 'value' => 'US', 'views' => 9 ) );
$r = sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
ok( count( $r ) === 15, 'accessor: slices to top 15' );
ok( $r[0]['views'] === 29, 'accessor: highest-views page first' );

echo "\nGroup: accessor — caching + failure\n";
dd_reset();
sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 1, 'accessor: cache hit issues no second query' );
ok( $GLOBALS['__dd_last_ttl'] === 5 * 60, 'accessor: 5-minute cache TTL' );
dd_reset();
$GLOBALS['__dd_query_result'] = null;
ok( null === sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' ), 'accessor: AE null → null (empty-state)' );
$c = sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
ok( null === $c && count( $GLOBALS['__dd_query_calls'] ) === 1, 'accessor: failure negative-cached (no retry storm)' );

echo "\nGroup: accessor — input guards\n";
dd_reset();
ok( null === sn_analytics_drilldown( 'martian', 'x', '2026-06-01', '2026-06-30', 'human' ), 'accessor: unknown dim → null' );
ok( null === sn_analytics_drilldown( 'country', 'US', 'bad', '2026-06-30', 'human' ), 'accessor: bad from → null' );
ok( null === sn_analytics_drilldown( 'country', str_repeat( 'x', 300 ), '2026-06-01', '2026-06-30', 'human' ), 'accessor: over-long value → null' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 0, 'accessor: guarded inputs never hit AE' );

echo "\nGroup: accessor — cache key distinguishes values (not a constant key)\n";
dd_reset();
sn_analytics_drilldown( 'country', 'US', '2026-06-01', '2026-06-30', 'human' );
sn_analytics_drilldown( 'country', "O'Hare", '2026-06-01', '2026-06-30', 'human' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 2, 'cache key: a different value MISSES (distinct keys, not a constant)' );

echo "\nGroup: accessor → builder — escaping survives end-to-end\n";
dd_reset();
sn_analytics_drilldown( 'city', "O'Hare", '2026-06-01', '2026-06-30', 'human' );
ok( count( $GLOBALS['__dd_query_calls'] ) === 1, 'accessor: quote-bearing top-N value passes the whitelist' );
ok( strpos( $GLOBALS['__dd_query_calls'][0], "blob11 = 'O\\'Hare'" ) !== false, 'accessor→builder: the quote is escaped in the issued SQL (not just in the builder unit test)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
