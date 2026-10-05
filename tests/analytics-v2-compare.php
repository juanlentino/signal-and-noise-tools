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
ok( "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, blob1 AS ev, sum(_sample_interval) AS n, count() AS r, count(DISTINCT index1) AS v, sum(if(double11 > 0, _sample_interval, 0)) AS with_pid FROM sn_pageviews_v2 WHERE timestamp >= toStartOfDay(now() - INTERVAL '3' DAY) GROUP BY day, ev" === $stmt, 'the exact v2 statement: weighted counts, aliases in GROUP BY, no function there' );
ok( false === strpos( sn_analytics_v2_count_sql( 'sn_pageviews', 4 ), 'double11' ), 'the legacy read asks for no pageview ID (the column does not exist there)' );
ok( false !== strpos( sn_analytics_v2_count_sql( 'x; DROP', 99 ), "FROM sn_pageviews WHERE timestamp >= toStartOfDay(now() - INTERVAL '13' DAY)" ), 'an unknown dataset falls back to the legacy name and the window is clamped: nothing typed reaches the statement' );

echo "\nThe comparison\n";
$L = array( array( 'day' => '2026-10-03', 'ev' => 'pv', 'n' => 60 ), array( 'day' => '2026-10-03', 'ev' => 'ce', 'n' => 9 ), array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40 ), array( 'day' => '2026-10-05', 'ev' => 'sc', 'n' => 80 ), array( 'day' => '2026-10-05', 'ev' => 'ce', 'n' => 5 ), array( 'day' => '2026-10-05', 'ev' => 'cp', 'n' => 7 ) );
$P = array( array( 'day' => '2026-10-03', 'ev' => 'pv', 'n' => 4, 'with_pid' => 4 ), array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40, 'with_pid' => 38 ), array( 'day' => '2026-10-05', 'ev' => 'sc', 'n' => 80, 'with_pid' => 80 ), array( 'day' => '2026-10-05', 'ev' => 'ce', 'n' => 5, 'with_pid' => 5 ) ); // the ce row is in both new datasets (worker 1.25.0)
$E = array( array( 'day' => '2026-10-05', 'ev' => 'ce', 'n' => 5, 'with_pid' => 5 ), array( 'day' => '2026-10-05', 'ev' => 'cp', 'n' => 7, 'with_pid' => 7 ) );
$c = sn_analytics_v2_compare( $L, $P, $E, '2026-10-05' );
ok( true === $c['ok'] && true === $c['read'] && 0 === $c['mismatched'], 'equal counts from the first full day on: ok' );
ok( 'partial' === $c['days'][0]['state'] && 69 === $c['days'][0]['legacy_pageview_side'] && 4 === $c['days'][0]['v2_pageviews'], 'the day the dual write began is partial, not a mismatch, and still shows both counts' );
ok( array( 'day' => '2026-10-05', 'legacy_pageview_side' => 125, 'v2_pageviews' => 125, 'legacy_events' => 12, 'v2_events' => 12, 'with_pid' => 135, 'sampled' => false, 'sampled_events' => array(), 'differs' => array(), 'events_proven' => true, 'state' => 'match' ) === $c['days'][1], 'every legacy row but cp against the pageviews dataset, ce and cp against the events dataset: a ce row counts on both sides' );
$noce = array_slice( $P, 0, 3 );
ok( 'mismatch' === sn_analytics_v2_compare( $L, $noce, $E, '2026-10-05' )['days'][1]['state'], 'a pageviews dataset missing the custom events\' base rows is a mismatch (the worker 1.24.0 shape)' );
$P2 = $P; $P2[1]['n'] = 39;
$m = sn_analytics_v2_compare( $L, $P2, $E, '2026-10-05' );
ok( false === $m['ok'] && 1 === $m['mismatched'] && 'mismatch' === $m['days'][1]['state'], 'one row short on a full day is a mismatch' );
$m = sn_analytics_v2_compare( $L, $P, array(), '2026-10-05' );
ok( false === $m['ok'] && 'mismatch' === $m['days'][1]['state'], 'custom events missing from the events dataset is a mismatch even when pageviews agree' );
$S = $P2; $S[1]['r'] = 20; // 20 stored rows standing for 39: Analytics Engine sampled this day.
$m = sn_analytics_v2_compare( $L, $S, $E, '2026-10-05' );
ok( true === $m['ok'] && 0 === $m['mismatched'] && 'sampled' === $m['days'][1]['state'] && true === $m['days'][1]['sampled'], 'unequal counts on a sampled day are inconclusive, not a mismatch' );
$X = $P; $X[1]['r'] = 40;
ok( 'match' === sn_analytics_v2_compare( $L, $X, $E, '2026-10-05' )['days'][1]['state'], 'rows that each stand for themselves (r equals n) are exact' );
$comp = $P; $comp[1]['n'] = 39; $comp[2]['n'] = 81; // one pageview short, one scroll event over: the totals still agree.
$m = sn_analytics_v2_compare( $L, $comp, $E, '2026-10-05' );
ok( 'mismatch' === $m['days'][1]['state'] && $m['days'][1]['legacy_pageview_side'] === $m['days'][1]['v2_pageviews'] && 2 === count( $m['days'][1]['differs'] ), 'two errors that cancel in the total are still a mismatch: the comparison is event by event' );
$eq = $P; $eq[1]['r'] = 20; // equal estimates, but the pageviews were sampled.
ok( 'sampled' === sn_analytics_v2_compare( $L, $eq, $E, '2026-10-05' )['days'][1]['state'], 'pageviews that are an estimate cannot make a match, even when the numbers agree' );
$sc = $P; $sc[2]['r'] = 30; $sc[2]['n'] = 77; // scroll events sampled and unequal; pageviews exact and equal.
$m = sn_analytics_v2_compare( $L, $sc, $E, '2026-10-05' );
ok( 'sampled' === $m['days'][1]['state'] && array( 'sc' ) === $m['days'][1]['sampled_events'] && false === $m['days'][1]['events_proven'], 'one sampled event type withholds the match and is named: the rollups read that type too' );
$quiet = sn_analytics_v2_compare( array( array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40 ) ), array( array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40 ) ), array(), '2026-10-05' );
ok( 'match' === $quiet['days'][0]['state'] && false === $quiet['days'][0]['events_proven'], 'a day with no custom events matches on its pageviews and proves nothing about the events dataset' );
$n = sn_analytics_v2_compare( $L, null, $E, '2026-10-05' );
ok( false === $n['ok'] && false === $n['read'] && 0 === $n['mismatched'] && array() === $n['days'], 'a failed read is "not read": never a mismatch, never a match' );
ok( true === sn_analytics_v2_compare( array(), array(), array(), '2026-10-05' )['ok'], 'three empty datasets agree' );

echo "\nWhat equal counts cannot hide\n";
$lv = array( array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40, 'r' => 40, 'v' => 31 ) );
$m  = sn_analytics_v2_compare( $lv, array( array( 'day' => '2026-10-05', 'ev' => 'pv', 'n' => 40, 'r' => 40, 'v' => 1 ) ), array(), '2026-10-05' );
ok( 'mismatch' === $m['days'][0]['state'] && array( 'pv (pageviews visitors: 31 vs 1)' ) === $m['days'][0]['differs'], 'the same rows under different visitor hashes is a mismatch: visits and sessions are counted from them' );
ok( 'match' === sn_analytics_v2_compare( $lv, $lv, array(), '2026-10-05' )['days'][0]['state'], 'and equal hashes match' );
$gone = sn_analytics_v2_compare( array_merge( $lv, array( array( 'day' => '2026-10-05', 'ev' => 'sc', 'n' => 77, 'r' => 30 ) ) ), $lv, array(), '2026-10-05' );
ok( 'mismatch' === $gone['days'][0]['state'] && array( 'sc (pageviews: 77 vs 0)' ) === $gone['days'][0]['differs'], 'a sampled event with no row at all on the other side is a mismatch, not an estimate' );
$cp = sn_analytics_v2_compare( $lv, array_merge( $lv, array( array( 'day' => '2026-10-05', 'ev' => 'cp', 'n' => 3, 'r' => 3 ) ) ), array(), '2026-10-05' );
ok( 'mismatch' === $cp['days'][0]['state'] && array( 'cp (pageviews: must hold none, has 3)' ) === $cp['days'][0]['differs'], 'property rows in the pageviews dataset break the contract and the match' );

echo "\nThe live read\n";
$GLOBALS['answers'] = array( $L, $P, $E );
$r = sn_analytics_v2_check( 4, '2026-10-05' );
ok( 3 === count( $GLOBALS['sql'] ) && false !== strpos( $GLOBALS['sql'][0], 'FROM sn_pageviews WHERE' ) && false !== strpos( $GLOBALS['sql'][1], 'FROM sn_pageviews_v2' ) && false !== strpos( $GLOBALS['sql'][2], 'FROM sn_events_v2' ), 'three requests, one per dataset, legacy first' );
ok( true === $r['ok'] && '2026-10-05' === $r['first_full_day'] && array( 'sn_pageviews', 'sn_pageviews_v2', 'sn_events_v2' ) === $r['datasets'], 'the answer names the datasets and the first full day' );
ok( 'sn_pageviews_v2' === SN_ANALYTICS_DATASET_PV_V2 && 'sn_events_v2' === SN_ANALYTICS_DATASET_EVENTS_V2, 'the names are the worker\'s (wrangler.toml, /_sn/version datasets)' );

echo "\nA failed request\n";
function sn_analytics_last_error() { return array( 'code' => 403, 'message' => 'no permission', 'url' => 'x', 'when' => 1 ); }
$GLOBALS['sql'] = array(); $GLOBALS['answers'] = array( $L, null, $E );
$f = sn_analytics_v2_check( 4, '2026-10-05' );
ok( false === $f['read'] && 'sn_pageviews_v2' === $f['failed'] && 'HTTP 403 no permission' === $f['error'], 'a failed request names its dataset and keeps its reason' );
ok( 2 === count( $GLOBALS['sql'] ), 'and nothing is asked after it, so a later success cannot clear that reason' );

echo "\nWho was sampled (diagnostic)\n";
$sq = sn_analytics_v2_sampled_sql( 'sn_pageviews_v2', 4 );
ok( false !== strpos( $sq, 'FROM sn_pageviews_v2 ' ) && false !== strpos( $sq, 'AND _sample_interval > 1' ) && false !== strpos( $sq, 'GROUP BY day, vid LIMIT 201' ), 'reads only the sampled rows, per day and visitor-day, one row past the list size' );
ok( false !== strpos( $sq, "max(if(blob7 != 'bot' AND (" ) && false !== strpos( $sq, ') AS human' ), 'human is the read-time rule (not a stored bot, and the network rule), per row' );
ok( false !== strpos( sn_analytics_v2_sampled_sql( "x'; DROP", 4 ), 'FROM sn_pageviews ' ), 'an unknown dataset falls back to the legacy name' );
$capl = array( 'hashes' => array( 'bbbb2222' ), 'ok' => true, 'truncated' => false );
$rows3 = array(
	'sn_pageviews'    => array( array( 'day' => '2026-10-05', 'vid' => 'AAAA1111', 'n' => 40, 'r' => 4, 'stored_bot' => 1, 'human' => 1 ), array( 'day' => '2026-10-05', 'vid' => 'bbbb2222', 'n' => 20, 'r' => 2, 'stored_bot' => 0, 'human' => 1 ) ),
	'sn_pageviews_v2' => array( array( 'day' => '2026-10-05', 'vid' => 'cccc3333', 'n' => 9, 'r' => 3, 'stored_bot' => 0, 'human' => 0 ) ),
	'sn_events_v2'    => array(),
);
$sv = sn_analytics_v2_sampled_visitors( $rows3, $capl );
ok( true === $sv['conclusive'] && 3 === count( $sv['rows'] ), 'every read in, nothing cut, the cap list read: conclusive' );
ok( true === $sv['rows'][0]['stored_bot'] && 'aaaa1111' === $sv['rows'][0]['vid'] && 4 === $sv['rows'][0]['rows'] && 40 === $sv['rows'][0]['stands_for'], 'a visitor-day, its stored rows and what they stand for' );
ok( 1 === $sv['counted_human'], 'counted_human: a visitor-day with human rows counts even beside a bot row; over-cap and hosting-only (human 0) do not' );
$rows3['sn_events_v2'] = null;
ok( false === sn_analytics_v2_sampled_visitors( $rows3, $capl )['conclusive'], 'a failed read is inconclusive' );
$rows3['sn_events_v2'] = array();
ok( false === sn_analytics_v2_sampled_visitors( $rows3, array( 'hashes' => array(), 'ok' => false, 'truncated' => false ) )['conclusive'] && false === sn_analytics_v2_sampled_visitors( $rows3, array( 'hashes' => array(), 'ok' => true, 'truncated' => true ) )['conclusive'], 'no over-cap list, or a cut-short one, is inconclusive' );
$many = array_map( static fn( $i ) => array( 'day' => '2026-10-05', 'vid' => sprintf( '%08x', $i ), 'n' => 2, 'r' => 1, 'stored_bot' => 1, 'human' => 0 ), range( 1, SN_ANALYTICS_V2_SAMPLED_MAX + 1 ) );
$cut = sn_analytics_v2_sampled_visitors( array( 'sn_pageviews' => $many ), $capl );
ok( true === $cut['truncated'] && false === $cut['conclusive'] && SN_ANALYTICS_V2_SAMPLED_MAX === count( $cut['rows'] ), 'a list past its size says so, keeps the size, and is inconclusive' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
