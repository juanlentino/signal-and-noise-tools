<?php
/**
 * The sampled-day record (inc/analytics-sampled-days.php): the fold sums both
 * dataset sides per day and event, splits out the counted-human class, the
 * merge only adds or overwrites (never deletes, keeps a frozen old day), and
 * a day is estimated only when a HUMAN row that day was sampled.
 * Run: php tests/analytics-sampled-days.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }
function add_action() {}
$GLOBALS['o'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
// The rollup's own window and split helpers (real ones live in inc/analytics-human-rule.php / -generation.php).
function sn_analytics_rollup_window_exprs( $days, $tz = '' ) { return array( "DAYCOL({$tz})", "LOWER({$days})" ); }
function sn_analytics_split_range_ok( $r ) { return 0 === strpos( (string) $r, ' AND timestamp ' ); }

require __DIR__ . '/../inc/analytics-sampled-days.php';

echo "SQL\n";
$sql = sn_analytics_sampled_sql( 'sn_pageviews_v2', " AND timestamp >= toDateTime('2026-10-05 04:00:00')", 'America/New_York', 'CLASSEXPR' );
ok( false !== strpos( $sql, 'DAYCOL(America/New_York) AS day' ) && false !== strpos( $sql, 'LOWER(90)' ), 'buckets by the site day over 90 days, the rollup\'s own expressions' );
ok( false !== strpos( $sql, 'CLASSEXPR AS class' ) && false !== strpos( $sql, 'count() AS r, sum(_sample_interval) AS n' ), 'counts stored rows and what they stand for, per class' );
ok( false !== strpos( $sql, "GROUP BY day, ev, class" ) && false !== strpos( $sql, "toDateTime('2026-10-05 04:00:00')" ), 'groups by day, event, class and keeps the split range' );
ok( false === strpos( sn_analytics_sampled_sql( 'x', ' OR 1=1', '', 'C' ), 'OR 1=1' ), 'a range that is not one side of the split is dropped' );

echo "Fold\n";
$fold = sn_analytics_sampled_fold( array(
	array( array( 'day' => '2026-11-11', 'ev' => 'pv', 'class' => 'human', 'r' => 2, 'n' => 4 ), array( 'day' => '2026-11-11', 'ev' => 'pv', 'class' => 'bot', 'r' => 5, 'n' => 5 ) ),
	array( array( 'day' => '2026-11-11', 'ev' => 'pv', 'class' => 'human', 'r' => '1', 'n' => '1' ), array( 'day' => 'bad', 'ev' => 'pv', 'r' => 1, 'n' => 1 ) ),
) );
ok( array( 'stored' => 8, 'stands_for' => 10, 'human_stored' => 3, 'human_stands_for' => 5 ) === $fold['2026-11-11']['pv'], 'sums both sides and all classes; human kept apart' );
ok( ! isset( $fold['bad'] ), 'a malformed day is skipped' );

echo "Merge\n";
$stored = array(
	'2026-08-01' => array( 'pv' => array( 'stored' => 9, 'stands_for' => 9, 'human_stored' => 9, 'human_stands_for' => 9 ) ),
	'2026-08-25' => array( 'pv' => array( 'stored' => 7, 'stands_for' => 7, 'human_stored' => 7, 'human_stands_for' => 7 ) ),
	'2026-11-10' => array( 'pv' => array( 'stored' => 1, 'stands_for' => 1, 'human_stored' => 1, 'human_stands_for' => 1 ) ),
);
$fresh = array(
	'2026-08-25' => array( 'pv' => array( 'stored' => 2, 'stands_for' => 2, 'human_stored' => 2, 'human_stands_for' => 2 ) ),
	'2026-11-10' => array( 'pv' => array( 'stored' => 3, 'stands_for' => 6, 'human_stored' => 3, 'human_stands_for' => 6 ) ),
	'2026-11-11' => $fold['2026-11-11'],
);
$m = sn_analytics_sampled_merge( $stored, $fresh, '2026-11-17' );
ok( isset( $m['2026-08-01'] ), 'a day past Analytics Engine\'s window is never deleted' );
ok( 7 === $m['2026-08-25']['pv']['stored'], 'a frozen (old) day keeps what was first stored, a thinner re-read does not overwrite it' );
ok( 3 === $m['2026-11-10']['pv']['stored'] && isset( $m['2026-11-11'] ), 'a recent day is overwritten from fresh data; a new day is added' );

echo "Report and helper\n";
$r = sn_analytics_sampled_report( $m );
ok( '2026-11-10' === $r['onset'], 'onset is the earliest day with a human row sampled' );
$d11 = $r['days'][ array_search( '2026-11-11', array_column( $r['days'], 'day' ), true ) ];
ok( true === $d11['estimated'] && true === $d11['events']['pv']['human']['sampled'] && 10 === $d11['events']['pv']['stands_for'], 'per event: sampled, stored, stands_for, all and human' );
$bot_only = sn_analytics_sampled_report( array( '2026-11-12' => array( 'pv' => array( 'stored' => 1, 'stands_for' => 5, 'human_stored' => 2, 'human_stands_for' => 2 ) ) ) );
ok( false === $bot_only['days'][0]['estimated'] && true === $bot_only['days'][0]['events']['pv']['sampled'] && null === $bot_only['onset'], 'a day sampled only outside the human class is not estimated' );
$GLOBALS['o'][ SN_ANALYTICS_SAMPLED_OPT ] = $m;
ok( sn_analytics_day_estimated( '2026-11-11' ) && ! sn_analytics_day_estimated( '2026-08-01' ) && ! sn_analytics_day_estimated( '2026-12-01' ), 'sn_analytics_day_estimated reads the stored record' );

echo "\n{$pass} passed, {$fail} failed.\n";
exit( $fail ? 1 : 0 );
