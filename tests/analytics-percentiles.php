<?php
/**
 * Tests for inc/analytics-percentiles.php — on-demand scroll/time percentiles.
 * Builder shape (quantileExactWeighted, explicit date bounds, injection-safe) +
 * the cached read accessor. Mirrors tests/analytics-buckets.php harness.
 * Run: php tests/analytics-percentiles.php
 * @since plugin v6.8.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_ROLLUP_TTL', 15 * MINUTE_IN_SECONDS );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );

// Transient seam.
$GLOBALS['__pc_trans'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__pc_trans'] ) ? $GLOBALS['__pc_trans'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__pc_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__pc_trans'][ $k ] ); return true; }

// AE read-client seam. Default: a well-formed one-row result. Tests flip
// __pc_query_result to null to exercise the failure path.
$GLOBALS['__pc_query_calls']  = array();
$GLOBALS['__pc_query_result'] = array( array( 'p50' => 63.0, 'p75' => 84.0, 'p90' => 95.0 ) );
function sn_analytics_query( $sql ) {
	$GLOBALS['__pc_query_calls'][] = $sql;
	return $GLOBALS['__pc_query_result'];
}

require_once __DIR__ . '/../inc/analytics-percentiles.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function pc_reset() {
	$GLOBALS['__pc_trans']        = array();
	$GLOBALS['__pc_query_calls']  = array();
	$GLOBALS['__pc_query_result'] = array( array( 'p50' => 63.0, 'p75' => 84.0, 'p90' => 95.0 ) );
}

echo "Analytics percentiles layer\n\n";

echo "Group: metrics config\n";
$m = sn_analytics_percentiles_metrics();
ok( $m['scroll']['event'] === 'sc' && $m['scroll']['col'] === 'double1', 'config: scroll → sc / double1' );
ok( $m['time']['event'] === 'tm' && $m['time']['col'] === 'double2', 'config: time → tm / double2' );
ok( $m['scroll']['format'] === 'pct' && $m['time']['format'] === 'time', 'config: formats pct / time' );

echo "\nGroup: SQL builder (quantileExactWeighted, parametric, value-first)\n";
$sql = sn_analytics_percentiles_sql( 'sc', 'double1', '2026-06-01', '2026-06-30', 'human' );
ok( strpos( $sql, 'quantileExactWeighted(0.5)(double1, _sample_interval) AS p50' ) !== false, 'sql: p50 parametric, value-first, weighted' );
ok( strpos( $sql, 'quantileExactWeighted(0.75)(double1, _sample_interval) AS p75' ) !== false, 'sql: p75' );
ok( strpos( $sql, 'quantileExactWeighted(0.9)(double1, _sample_interval) AS p90' ) !== false, 'sql: p90' );
ok( strpos( $sql, 'FROM sn_pageviews' ) !== false, 'sql: targets the dataset' );
ok( strpos( $sql, "WHERE blob1 = 'sc'" ) !== false, 'sql: event-filtered' );
ok( strpos( $sql, "blob7 = 'human'" ) !== false, 'sql: class-filtered' );
ok( strpos( $sql, "timestamp >= toDateTime('2026-06-01 00:00:00')" ) !== false, 'sql: explicit lower date bound' );
ok( strpos( $sql, "timestamp <= toDateTime('2026-06-30 23:59:59')" ) !== false, 'sql: explicit inclusive upper date bound' );
ok( strpos( $sql, 'count(' ) === false, 'sql: no count() (dialect-clean)' );
ok( strpos( $sql, 'quantileWeighted(' ) === false, 'sql: not the flat quantileWeighted alias' );

$tsql = sn_analytics_percentiles_sql( 'tm', 'double2', '2026-06-01', '2026-06-30', 'human' );
ok( strpos( $tsql, "WHERE blob1 = 'tm'" ) !== false && strpos( $tsql, 'double2' ) !== false, 'sql(time): tm / double2' );

echo "\nGroup: SQL builder injection-safety\n";
ok( strpos( sn_analytics_percentiles_sql( "sc'; DROP", 'double1', '2026-06-01', '2026-06-30', 'human' ), 'DROP' ) === false, 'sql: event sanitised' );
ok( strpos( sn_analytics_percentiles_sql( 'sc', "double1); DROP", '2026-06-01', '2026-06-30', 'human' ), 'DROP' ) === false, 'sql: col sanitised' );
ok( strpos( sn_analytics_percentiles_sql( 'sc', 'double1', "2026-06-01'; DROP", '2026-06-30', 'human' ), 'DROP' ) === false, 'sql: from re-validated YMD (no injection)' );
ok( strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-06-01', '2026-06-30', "human'; DROP" ), 'DROP' ) === false, 'sql: class allowlisted' );
ok( strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-06-01', '2026-06-30', 'martian' ), "blob7 = 'human'" ) !== false, 'sql: unknown class → human' );

echo "\nGroup: read accessor — success shape + caching\n";
pc_reset();
$r = sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'human' );
ok( is_array( $r ) && count( $r ) === 3, 'accessor: returns 3 rows' );
ok( $r[0]['label'] === 'p50' && (float) $r[0]['value'] === 63.0, 'accessor: p50 value parsed' );
ok( $r[2]['label'] === 'p90' && (float) $r[2]['value'] === 95.0, 'accessor: p90 value parsed' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: one AE query on cold cache' );
// Second identical call → cache hit, no new query.
$r2 = sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'human' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: cache hit issues no second query' );
ok( $r2 === $r, 'accessor: cached value identical' );

echo "\nGroup: read accessor — failure degrades to null (cached briefly)\n";
pc_reset();
$GLOBALS['__pc_query_result'] = null; // AE failure / unconfigured
$f = sn_analytics_percentiles( 'time', '2026-06-01', '2026-06-30', 'human' );
ok( null === $f, 'accessor: AE null → returns null (empty-state trigger)' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: failure issued one query' );
$f2 = sn_analytics_percentiles( 'time', '2026-06-01', '2026-06-30', 'human' );
ok( null === $f2 && count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: failure cached → no retry storm' );

echo "\nGroup: read accessor — input guards\n";
pc_reset();
ok( null === sn_analytics_percentiles( 'martian', '2026-06-01', '2026-06-30', 'human' ), 'accessor: unknown metric → null' );
ok( null === sn_analytics_percentiles( 'scroll', 'bad-date', '2026-06-30', 'human' ), 'accessor: invalid from → null' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 0, 'accessor: guarded inputs never hit AE' );
pc_reset();
sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'martian' );
ok( strpos( $GLOBALS['__pc_query_calls'][0], "blob7 = 'human'" ) !== false, 'accessor: unknown class coerced to human in the query' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
