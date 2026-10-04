<?php
/**
 * Standalone test: the dual-write check for the second-generation analytics
 * datasets (inc/analytics-v2-compare.php).
 *
 * Run: php tests/analytics-v2-compare.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
const SN_ANALYTICS_DATASET = 'sn_pageviews';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function add_action() {}
$GLOBALS['sql'] = array(); $GLOBALS['answers'] = array();
function sn_analytics_query( $sql ) { $GLOBALS['sql'][] = $sql; return array_shift( $GLOBALS['answers'] ); }
require __DIR__ . '/../inc/analytics-v2-compare.php';

echo "\nThe statement\n";
$stmt = sn_analytics_v2_count_sql( "sn_pageviews_v2", 4, true );
ok( "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, blob1 AS ev, sum(_sample_interval) AS n, sum(if(double11 > 0, _sample_interval, 0)) AS with_pid FROM sn_pageviews_v2 WHERE timestamp >= toStartOfDay(now() - INTERVAL '3' DAY) GROUP BY day, ev" === $stmt, 'the exact v2 statement: weighted counts, aliases in GROUP BY, no function there' );
ok( false === strpos( sn_analytics_v2_count_sql( 'sn_pageviews', 4 ), 'double11' ), 'the legacy read asks for no pageview ID (the column does not exist there)' );
ok( false !== strpos( sn_analytics_v2_count_sql( 'x; DROP', 99 ), "FROM sn_pageviews WHERE timestamp >= toStartOfDay(now() - INTERVAL '13' DAY)" ), 'an unknown dataset falls back to the legacy name and the window is clamped: nothing typed reaches the statement' );

echo "\nThe comparison\n";
$L = array( array( 'day' => '2026-10-03', 'ev' => 'pv', 'n' => 60 ), array( 'day' => '2026-10-03', 'ev' => 'ce', 'n' => 9 ), array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40 ), array( 'day' => '2026-10-05', 'ev' => 'sc', 'n' => 80 ), array( 'day' => '2026-10-05', 'ev' => 'ce', 'n' => 5 ), array( 'day' => '2026-10-05', 'ev' => 'cp', 'n' => 7 ) );
$P = array( array( 'day' => '2026-10-03', 'ev' => 'pv', 'n' => 4, 'with_pid' => 4 ), array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40, 'with_pid' => 38 ), array( 'day' => '2026-10-05', 'ev' => 'sc', 'n' => 80, 'with_pid' => 80 ) );
$E = array( array( 'day' => '2026-10-05', 'ev' => 'ce', 'n' => 5, 'with_pid' => 5 ), array( 'day' => '2026-10-05', 'ev' => 'cp', 'n' => 7, 'with_pid' => 7 ) );
$c = sn_analytics_v2_compare( $L, $P, $E, '2026-10-05' );
ok( true === $c['ok'] && true === $c['read'] && 0 === $c['mismatched'], 'equal counts from the first full day on: ok' );
ok( 'partial' === $c['days'][0]['state'] && 60 === $c['days'][0]['legacy_pageview_side'] && 4 === $c['days'][0]['v2_pageviews'], 'the day the dual write began is partial, not a mismatch, and still shows both counts' );
ok( array( 'day' => '2026-10-05', 'legacy_pageview_side' => 120, 'v2_pageviews' => 120, 'legacy_events' => 12, 'v2_events' => 12, 'with_pid' => 130, 'state' => 'match' ) === $c['days'][1], 'pageview-side and custom events are compared apart; with_pid sums both new datasets' );
$P2 = $P; $P2[1]['n'] = 39;
$m = sn_analytics_v2_compare( $L, $P2, $E, '2026-10-05' );
ok( false === $m['ok'] && 1 === $m['mismatched'] && 'mismatch' === $m['days'][1]['state'], 'one row short on a full day is a mismatch' );
$m = sn_analytics_v2_compare( $L, $P, array(), '2026-10-05' );
ok( false === $m['ok'] && 'mismatch' === $m['days'][1]['state'], 'custom events missing from the events dataset is a mismatch even when pageviews agree' );
$n = sn_analytics_v2_compare( $L, null, $E, '2026-10-05' );
ok( false === $n['ok'] && false === $n['read'] && 0 === $n['mismatched'] && array() === $n['days'], 'a failed read is "not read": never a mismatch, never a match' );
ok( true === sn_analytics_v2_compare( array(), array(), array(), '2026-10-05' )['ok'], 'three empty datasets agree' );

echo "\nThe live read\n";
$GLOBALS['answers'] = array( $L, $P, $E );
$r = sn_analytics_v2_check( 4, '2026-10-05' );
ok( 3 === count( $GLOBALS['sql'] ) && false !== strpos( $GLOBALS['sql'][0], 'FROM sn_pageviews WHERE' ) && false !== strpos( $GLOBALS['sql'][1], 'FROM sn_pageviews_v2' ) && false !== strpos( $GLOBALS['sql'][2], 'FROM sn_events_v2' ), 'three requests, one per dataset, legacy first' );
ok( true === $r['ok'] && '2026-10-05' === $r['first_full_day'] && array( 'sn_pageviews', 'sn_pageviews_v2', 'sn_events_v2' ) === $r['datasets'], 'the answer names the datasets and the first full day' );
ok( 'sn_pageviews_v2' === SN_ANALYTICS_DATASET_PV_V2 && 'sn_events_v2' === SN_ANALYTICS_DATASET_EVENTS_V2, 'the names are the worker\'s (wrangler.toml, /_sn/version datasets)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
