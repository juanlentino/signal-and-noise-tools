<?php
/**
 * Tests for inc/analytics-events-rollup.php — the live custom-event (ce/cp)
 * rollup layer that feeds the existing wp_sn_analytics_events +
 * wp_sn_analytics_event_props tables.
 * Run: php tests/analytics-events-rollup.php
 * @since plugin v6.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

// AE read-client seam (analytics-api.php not loaded here).
$GLOBALS['__er_query_return']  = null;   // array|callable returned by sn_analytics_query
$GLOBALS['__er_query_calls']   = array();
$GLOBALS['__er_config_present'] = true;
function sn_analytics_config() { return $GLOBALS['__er_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
function sn_analytics_query( $sql ) {
	$GLOBALS['__er_query_calls'][] = $sql;
	if ( is_array( $GLOBALS['__er_query_return'] ) ) {
		return $GLOBALS['__er_query_return'];
	}
	if ( is_callable( $GLOBALS['__er_query_return'] ) ) {
		$fn = $GLOBALS['__er_query_return'];
		return $fn( $sql );
	}
	return null;
}

// Capture upsert payloads (the real upserts live in analytics-events.php, which
// is NOT loaded here — stub them to capture the mapped rows).
$GLOBALS['__er_events_upserts'] = array();
$GLOBALS['__er_props_upserts']  = array();
function sn_analytics_events_upsert( $rows ) { $GLOBALS['__er_events_upserts'][] = $rows; return is_array( $rows ) ? count( $rows ) : 0; }
function sn_analytics_event_props_upsert( $rows ) { $GLOBALS['__er_props_upserts'][] = $rows; return is_array( $rows ) ? count( $rows ) : 0; }

require_once __DIR__ . '/../inc/analytics-events-rollup.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function er_reset() {
	$GLOBALS['__er_query_return']  = null;
	$GLOBALS['__er_query_calls']   = array();
	$GLOBALS['__er_config_present'] = true;
	$GLOBALS['__er_events_upserts'] = array();
	$GLOBALS['__er_props_upserts']  = array();
}

echo "Analytics live custom-event (ce/cp) rollup layer\n\n";

echo "Group: events rollup SQL (blob1='ce')\n";
er_reset();
$esql = sn_analytics_events_rollup_sql( 7 );
ok( strpos( $esql, "WHERE blob1 = 'ce'" ) !== false, 'events-sql: filters to ce events' );
ok( strpos( $esql, "blob7 = 'human'" ) !== false, 'events-sql: human-only' );
ok( strpos( $esql, 'blob16 AS name' ) !== false, 'events-sql: blob16 → name' );
ok( strpos( $esql, 'sum(_sample_interval) AS events' ) !== false, 'events-sql: events = sample-corrected sum' );
ok( strpos( $esql, 'count(DISTINCT index1) AS visitors' ) !== false, 'events-sql: visitors = distinct visitor-day hashes (bare column)' );
ok( strpos( $esql, 'GROUP BY day, name' ) !== false, 'events-sql: groups by day, name' );
ok( strpos( $esql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'events-sql: floored trailing window' );
ok( strpos( $esql, 'count(*)' ) === false && strpos( $esql, 'count(DISTINCT if' ) === false, 'events-sql: no AE-invalid count(*)/count(DISTINCT <expr>)' );
ok( stripos( $esql, ' LIMIT ' ) === false, 'events-sql: no LIMIT (PHP-slices instead)' );
ok( strpos( sn_analytics_events_rollup_sql( '7; DROP TABLE x' ), 'DROP TABLE' ) === false, 'events-sql: $days integer-cast (no injection)' );

echo "\nGroup: event-props rollup SQL (blob1='cp')\n";
er_reset();
$psql = sn_analytics_event_props_rollup_sql( 7 );
ok( strpos( $psql, "WHERE blob1 = 'cp'" ) !== false, 'props-sql: filters to cp rows' );
ok( strpos( $psql, "blob7 = 'human'" ) !== false, 'props-sql: human-only' );
ok( strpos( $psql, 'blob17 AS property' ) !== false, 'props-sql: blob17 → property' );
ok( strpos( $psql, 'blob18 AS value' ) !== false, 'props-sql: blob18 → value' );
ok( strpos( $psql, 'sum(_sample_interval) AS events' ) !== false, 'props-sql: events = sample-corrected sum' );
ok( strpos( $psql, 'count(DISTINCT index1) AS visitors' ) !== false, 'props-sql: visitors via bare-column DISTINCT' );
ok( strpos( $psql, 'GROUP BY day, property, value' ) !== false, 'props-sql: groups by day, property, value' );
ok( strpos( $psql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'props-sql: floored trailing window' );
ok( strpos( $psql, 'count(*)' ) === false && strpos( $psql, 'count(DISTINCT if' ) === false, 'props-sql: no AE-invalid count forms' );
ok( stripos( $psql, ' LIMIT ' ) === false, 'props-sql: no LIMIT' );

echo "\nGroup: run_rollup — caps drop the tail (behavioral) + mapping\n";
er_reset();
$GLOBALS['__er_query_return'] = function ( $sql ) {
	if ( strpos( $sql, "blob1 = 'ce'" ) !== false ) {
		$rows = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$rows[] = array( 'day' => '2026-06-13', 'name' => 'ev-' . $i, 'events' => 1000 - $i, 'visitors' => 10 );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array( 'day' => '2026-06-12', 'name' => 'b-' . $i, 'events' => 5 - $i, 'visitors' => 2 );
		}
		return $rows;
	}
	if ( strpos( $sql, "blob1 = 'cp'" ) !== false ) {
		$rows = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$rows[] = array( 'day' => '2026-06-13', 'property' => 'p' . ( $i % 5 ), 'value' => 'v-' . $i, 'events' => 2000 - $i, 'visitors' => 7 );
		}
		return $rows;
	}
	return null;
};
sn_analytics_events_run_rollup();

ok( count( $GLOBALS['__er_query_calls'] ) === 2, 'run: issues two AE queries (events + props)' );

ok( count( $GLOBALS['__er_events_upserts'] ) === 1, 'run: one events upsert call' );
$ev_rows = $GLOBALS['__er_events_upserts'][0];
$ev_by_day = array();
foreach ( $ev_rows as $r ) { $ev_by_day[ $r['day'] ][] = $r; }
ok( count( $ev_by_day['2026-06-13'] ) === SN_ANALYTICS_EVENTS_ROLLUP_NAME_CAP, 'run: day-A events capped to top 100 names' );
ok( count( $ev_by_day['2026-06-12'] ) === 3, 'run: day-B (under cap) keeps all 3 names' );
$kept_names = array_map( function ( $r ) { return $r['name']; }, $ev_by_day['2026-06-13'] );
ok( in_array( 'ev-0', $kept_names, true ), 'run: highest-events name (ev-0) survives the cap' );
ok( ! in_array( 'ev-149', $kept_names, true ), 'run: lowest-events name (ev-149) is dropped by the cap' );
$er0 = $ev_rows[0];
ok( isset( $er0['day'], $er0['name'], $er0['visitors'], $er0['events'] ), 'run: events rows map to {day,name,visitors,events}' );

ok( count( $GLOBALS['__er_props_upserts'] ) === 1, 'run: one event_props upsert call' );
$pr_rows = $GLOBALS['__er_props_upserts'][0];
$pr_by_day = array();
foreach ( $pr_rows as $r ) { $pr_by_day[ $r['day'] ][] = $r; }
ok( count( $pr_by_day['2026-06-13'] ) === SN_ANALYTICS_EVENTS_ROLLUP_PROP_CAP, 'run: day-A props capped to top 200 (property,value)' );
$pr0 = $pr_rows[0];
ok( isset( $pr0['day'], $pr0['property'], $pr0['value'], $pr0['visitors'], $pr0['events'] ), 'run: props rows map to {day,property,value,visitors,events}' );

echo "\nGroup: run_rollup — unconfigured + empty + null are no-ops\n";
er_reset();
$GLOBALS['__er_config_present'] = false;
sn_analytics_events_run_rollup();
ok( count( $GLOBALS['__er_query_calls'] ) === 0, 'run: no AE query when unconfigured' );
ok( count( $GLOBALS['__er_events_upserts'] ) === 0 && count( $GLOBALS['__er_props_upserts'] ) === 0, 'run: no upsert when unconfigured' );

er_reset();
$GLOBALS['__er_query_return'] = array(); // empty AE result on every query
sn_analytics_events_run_rollup();
ok( count( $GLOBALS['__er_query_calls'] ) === 2, 'run: still issues both queries on an idle window' );
ok( count( $GLOBALS['__er_events_upserts'] ) === 0 && count( $GLOBALS['__er_props_upserts'] ) === 0, 'run: empty result → no upsert (no-clobber)' );

er_reset();
$GLOBALS['__er_query_return'] = null; // query failure (null) on every query
sn_analytics_events_run_rollup();
ok( count( $GLOBALS['__er_events_upserts'] ) === 0 && count( $GLOBALS['__er_props_upserts'] ) === 0, 'run: null query result → no upsert' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
