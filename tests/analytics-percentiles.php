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
function get_transient( $k ) {
	$GLOBALS['__keys'][] = $k;
	if ( SNT_ANALYTICS_VDAY_CACHE_KEY === $k ) { return array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); } // the human rule's list, primed so call counts stay this module's own
	return array_key_exists( $k, $GLOBALS['__pc_trans'] ) ? $GLOBALS['__pc_trans'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__pc_trans'][ $k ] = $v; $GLOBALS['__pc_last_ttl'] = $ttl; return true; }
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
	$GLOBALS['__pc_last_ttl']     = null;
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
ok( strpos( $sql, sn_analytics_counted_condition( 'human', array() ) ) !== false, 'sql: class-filtered' );
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
ok( strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-06-01', '2026-06-30', 'martian' ), sn_analytics_counted_condition( 'human', array() ) ) !== false, 'sql: unknown class → human' );

echo "\nGroup: the window is the site's days, sent as UTC instants\n";
$ny = new DateTimeZone( 'America/New_York' );
ok( array( '2026-10-06 04:00:00', '2026-10-20 03:59:59' ) === sn_analytics_local_day_bounds_utc( '2026-10-06', '2026-10-19', $ny ), 'New York days Oct 6 to Oct 19 are 04:00 UTC to 03:59:59 UTC the day after' );
ok( array( '2026-11-01 04:00:00', '2026-11-02 04:59:59' ) === sn_analytics_local_day_bounds_utc( '2026-11-01', '2026-11-01', $ny ), 'the day the clocks go back is 25 hours long, and the bounds say so' );
ok( array( '2026-06-01 00:00:00', '2026-06-30 23:59:59' ) === sn_analytics_local_day_bounds_utc( '2026-06-01', '2026-06-30', new DateTimeZone( 'UTC' ) ), 'a UTC site keeps the UTC midnights' );
ok( false !== strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-06-01', '2026-06-30', 'human' ), "timestamp >= toDateTime('2026-06-01 00:00:00') AND timestamp <= toDateTime('2026-06-30 23:59:59')" ), 'the statement keeps its proven shape: a plain UTC literal inside toDateTime(), no timezone argument' );

if ( function_exists( 'sn_analytics_v2_clean_from' ) ) {
	function wp_timezone() { return new DateTimeZone( $GLOBALS['__tz'] ?? 'UTC' ); }
	sn_analytics_v2_clean_from( '2026-10-05' );
	$GLOBALS['__tz'] = 'Asia/Tokyo'; // Tokyo's Oct 5 starts at 15:00 UTC on Oct 4, before the clean day.
	ok( false !== strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-10-05', '2026-10-10', 'human' ), 'FROM sn_pageviews WHERE' ), 'east of UTC, a window starting on the clean day reaches the UTC day before it: the legacy dataset' );
	ok( false !== strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-10-06', '2026-10-10', 'human' ), 'FROM sn_pageviews_v2' ), 'one day later it is inside: the second generation' );
	$GLOBALS['__tz'] = 'America/New_York';
	ok( false !== strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-10-05', '2026-10-10', 'human' ), 'FROM sn_pageviews_v2' ), 'west of UTC the same window starts inside the clean day' );
	$GLOBALS['__tz'] = 'UTC';
	$GLOBALS['__keys'] = array();
	$pk = static fn() => array_values( array_filter( $GLOBALS['__keys'], static fn( $k ) => 0 === strpos( (string) $k, 'sn_pctl_' ) ) );
	sn_analytics_percentiles( 'scroll', '2026-10-06', '2026-10-10', 'human' ); $k2 = $pk()[0] ?? null;
	$GLOBALS['__keys'] = array();
	sn_analytics_v2_clean_from( '' );
	sn_analytics_percentiles( 'scroll', '2026-10-06', '2026-10-10', 'human' ); $k1 = $pk()[0] ?? null;
	ok( is_string( $k1 ) && is_string( $k2 ) && $k1 !== $k2, 'the cache key changes with the dataset, so a verdict that moves the window cannot serve the other generation\'s answer' );
}

echo "\nGroup: read accessor — success shape + caching\n";
pc_reset();
$r = sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'human' );
ok( is_array( $r ) && count( $r ) === 3, 'accessor: returns 3 rows' );
ok( $r[0]['label'] === 'p50' && (float) $r[0]['value'] === 63.0, 'accessor: p50 value parsed' );
ok( $r[2]['label'] === 'p90' && (float) $r[2]['value'] === 95.0, 'accessor: p90 value parsed' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: one AE query on cold cache' );
ok( $GLOBALS['__pc_last_ttl'] === SN_ANALYTICS_ROLLUP_TTL, 'accessor: success cached for the full rollup TTL' );
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
ok( $GLOBALS['__pc_last_ttl'] === 5 * 60 && $GLOBALS['__pc_last_ttl'] < SN_ANALYTICS_ROLLUP_TTL, 'accessor: failure cached for a SHORT TTL (5m < rollup TTL), not the full window' );
$f2 = sn_analytics_percentiles( 'time', '2026-06-01', '2026-06-30', 'human' );
ok( null === $f2 && count( $GLOBALS['__pc_query_calls'] ) === 1, 'accessor: failure cached → no retry storm' );

echo "\nGroup: read accessor — input guards\n";
pc_reset();
ok( null === sn_analytics_percentiles( 'martian', '2026-06-01', '2026-06-30', 'human' ), 'accessor: unknown metric → null' );
ok( null === sn_analytics_percentiles( 'scroll', 'bad-date', '2026-06-30', 'human' ), 'accessor: invalid from → null' );
ok( count( $GLOBALS['__pc_query_calls'] ) === 0, 'accessor: guarded inputs never hit AE' );
pc_reset();
sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'martian' );
ok( strpos( $GLOBALS['__pc_query_calls'][0], sn_analytics_counted_condition( 'human', array() ) ) !== false, 'accessor: unknown class coerced to human in the query' );

echo "\nGroup: cache key separates metric / window / class (no bleed)\n";
pc_reset();
sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'human' ); // 1
sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'bot' );   // 2 — distinct class
sn_analytics_percentiles( 'scroll', '2026-07-01', '2026-07-31', 'human' ); // 3 — distinct window
sn_analytics_percentiles( 'time', '2026-06-01', '2026-06-30', 'human' );   // 4 — distinct metric
ok( count( $GLOBALS['__pc_query_calls'] ) === 4, 'cache key: distinct metric/window/class each MISS (no cross-key bleed)' );
sn_analytics_percentiles( 'scroll', '2026-06-01', '2026-06-30', 'human' ); // repeat #1 → hit
ok( count( $GLOBALS['__pc_query_calls'] ) === 4, 'cache key: repeat of a primed key HITs (no extra query)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
