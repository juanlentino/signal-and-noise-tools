<?php
/**
 * Standalone test: which Analytics Engine dataset a read uses
 * (inc/analytics-generation.php), and the second-generation statement of the
 * reads whose columns moved.
 *
 * Run: php tests/analytics-generation.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
const SN_ANALYTICS_DATASET = 'sn_pageviews';
const SN_ANALYTICS_CLASSES = array( 'human', 'suspect', 'bot' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function add_action() {}
function add_filter() {}
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
require __DIR__ . '/../inc/analytics-generation.php';
require __DIR__ . '/../inc/analytics-events-rollup.php';
require __DIR__ . '/../inc/analytics-utm.php';
require __DIR__ . '/../inc/analytics-sessions.php';
require __DIR__ . '/../inc/analytics-percentiles.php';
require __DIR__ . '/../inc/analytics-dims.php';

$oct20 = gmmktime( 12, 0, 0, 10, 20, 2026 );
$oct08 = gmmktime( 12, 0, 0, 10, 8, 2026 );

echo "\nThe choice\n";
sn_analytics_v2_verified( false );
ok( 'sn_pageviews' === sn_analytics_source( '2026-10-10' ) && 'sn_pageviews' === sn_analytics_source( '2026-10-10', 'events' ), 'unverified: the legacy dataset, whatever the window' );
sn_analytics_v2_verified( true );
ok( 'sn_pageviews_v2' === sn_analytics_source( '2026-10-05' ) && 'sn_events_v2' === sn_analytics_source( '2026-10-05', 'events' ), 'verified and a window starting on the first full day: the second generation' );
ok( 'sn_pageviews' === sn_analytics_source( '2026-10-04' ) && 'sn_pageviews' === sn_analytics_source( '2026-10-04', 'events' ), 'a window reaching before the first full day stays on the legacy dataset, which holds all of it' );
ok( 'sn_pageviews' === sn_analytics_source( 'not-a-day' ) && 'sn_pageviews' === sn_analytics_source( '' ), 'a day that is not a day is the legacy dataset' );
ok( '2026-10-12' === sn_analytics_trailing_from( 7, $oct20 ) && '2026-10-19' === sn_analytics_trailing_from( 0, $oct20 ), 'a trailing window starts one day earlier than its days: the rollups floor to a local day' );

echo "\nThe clean day\n";
sn_analytics_v2_clean_from( '2026-10-09' );
ok( 'sn_pageviews' === sn_analytics_source( '2026-10-08' ) && 'sn_pageviews_v2' === sn_analytics_source( '2026-10-09' ), 'after a mismatch on Oct 8, a window that still holds that day stays on the legacy dataset; one that starts after it does not' );
sn_analytics_v2_verified( true );

echo "\nThe columns that moved\n";
ok( 'blob16' === sn_analytics_col( 'blob19', 'sn_pageviews_v2' ) && 'blob17' === sn_analytics_col( 'blob16', 'sn_pageviews_v2' ) && 'blob4' === sn_analytics_col( 'blob4', 'sn_pageviews_v2' ), 'pageviews: timezone 19 to 16, the custom event name 16 to 17, the rest where they were' );
ok( array( 'blob17', 'blob18', 'blob19', 'blob16' ) === array_map( static fn( $c ) => sn_analytics_col( $c, 'sn_events_v2' ), array( 'blob16', 'blob17', 'blob18', 'blob19' ) ), 'events: name, property, value one column later; timezone 16' );
ok( 'blob16' === sn_analytics_col( 'blob16', 'sn_pageviews' ) && 'blob19' === sn_analytics_col( 'blob19', 'sn_pageviews' ), 'the legacy dataset keeps every column' );

echo "\nThe verdict\n";
$day = static fn( $d, $s ) => array( 'day' => $d, 'state' => $s );
$v = sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-04', 'partial' ), $day( '2026-10-05', 'match' ), $day( '2026-10-06', 'match' ) ) ), gmmktime( 21, 45, 0, 10, 6, 2026 ) );
ok( true === $v['ok'] && '2026-10-05' === $v['day'], 'a complete day that matches verifies; today, still filling, does not count' );
ok( false === sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-05', 'match' ) ) ), gmmktime( 21, 45, 0, 10, 5, 2026 ) )['ok'], 'a match on today alone is not yet a verdict' );
$m = sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-05', 'match' ), $day( '2026-10-06', 'mismatch' ) ) ), $oct08 );
ok( false === $m['ok'] && '2026-10-07' === $m['clean_from'] && false !== strpos( $m['why'], 'since the mismatch before 2026-10-07' ), 'a mismatch refuses and moves the clean day past it; an earlier match no longer counts' );
$m2 = sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-06', 'mismatch' ), $day( '2026-10-07', 'match' ) ) ), $oct08 );
ok( true === $m2['ok'] && '2026-10-07' === $m2['clean_from'] && '2026-10-07' === $m2['day'], 'a clean day after the mismatch verifies again, from that day on' );
$m3 = sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-12', 'match' ) ) ), gmmktime( 21, 45, 0, 10, 13, 2026 ), $m2 );
ok( true === $m3['ok'] && '2026-10-07' === $m3['clean_from'], 'the clean day is remembered after the check stops looking that far back' );
ok( true === sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-07', 'match' ), $day( '2026-10-08', 'mismatch' ) ) ), $oct08 )['ok'], 'a mismatch on today, still filling and read in three separate requests, is ignored like a match on today' );
ok( true === sn_analytics_v2_verdict( array( 'read' => true, 'days' => array( $day( '2026-10-05', 'match' ), $day( '2026-10-06', 'sampled' ) ) ), $oct08 )['ok'], 'a sampled day is neutral: it neither verifies nor refuses' );
$n = sn_analytics_v2_verdict( array( 'read' => false, 'failed' => 'sn_events_v2', 'error' => 'HTTP 403' ), $oct08 );
ok( false === $n['ok'] && 0 === strpos( $n['why'], 'not read: sn_events_v2' ), 'a failed read is no verdict' );

echo "\nA flipped verdict drops the cached over-cap list\n";
$GLOBALS['deleted'] = array();
function delete_transient( $k ) { $GLOBALS['deleted'][] = $k; return true; }
function sn_analytics_config() { return array( 'account_id' => 'a', 'token' => 't' ); }
$GLOBALS['next_check'] = array( 'read' => true, 'days' => array( array( 'day' => '2026-10-07', 'state' => 'match' ) ) );
function sn_analytics_v2_check( $days, $from ) { return $GLOBALS['next_check']; }
$GLOBALS['opt'] = array();
$r1 = sn_analytics_v2_verify( $oct08 );
ok( true === $r1['ok'] && array( SNT_ANALYTICS_VDAY_CACHE_KEY ) === $GLOBALS['deleted'], 'unverified to verified: the cached list is dropped' );
$GLOBALS['deleted'] = array(); sn_analytics_v2_verify( $oct08 );
ok( array() === $GLOBALS['deleted'], 'verified again: nothing changed, nothing dropped' );
$GLOBALS['next_check'] = array( 'read' => true, 'days' => array( array( 'day' => '2026-10-07', 'state' => 'mismatch' ) ) );
$r3 = sn_analytics_v2_verify( $oct08 );
ok( false === $r3['ok'] && array( SNT_ANALYTICS_VDAY_CACHE_KEY ) === $GLOBALS['deleted'] && false === sn_analytics_v2_verified(), 'a mismatch reverts the reads and drops the list read from the dataset just left' );
$GLOBALS['next_check'] = array( 'read' => false ); $GLOBALS['deleted'] = array();
ok( null === sn_analytics_v2_verify( $oct08 ) && false === $GLOBALS['opt'][ SN_ANALYTICS_V2_VERIFIED_OPT ]['ok'] && array() === $GLOBALS['deleted'], 'a failed read stores nothing and keeps the previous verdict' );

echo "\nThe statements, second generation\n";
sn_analytics_v2_verified( true ); sn_analytics_clock( $oct20 );
$e = sn_analytics_events_rollup_sql( 7 );
ok( false !== strpos( $e, 'blob17 AS name,' ) && false !== strpos( $e, 'FROM sn_events_v2' ) && false !== strpos( $e, "blob1 = 'ce'" ), 'custom events: the events dataset, name in blob17' );
$p = sn_analytics_event_props_rollup_sql( 7 );
ok( false !== strpos( $p, 'blob18 AS property, blob19 AS value,' ) && false !== strpos( $p, 'FROM sn_events_v2' ), 'event properties: the events dataset, property and value in blob18 and blob19' );
$u = sn_analytics_utm_rollup_sql( 7 );
ok( false !== strpos( $u, 'blob17 AS us, blob18 AS um, blob19 AS uc, blob20 AS utc,' ) && false !== strpos( $u, 'FROM sn_pageviews_v2' ) && false !== strpos( $u, "blob1 = 'pv' AND (blob17 != '' OR blob18 != '' OR blob19 != '' OR blob20 != '')" ) && false !== strpos( $u, 'GROUP BY day, us, um, uc, utc, class' ), 'UTM: grouped on the fields themselves, pageviews only (a custom event name in blob17 can never read as a source)' );
$s = sn_analytics_session_sql( '2026-10-06', '2026-10-19', 'human', 100 );
ok( false !== strpos( $s, 'blob17 AS ce,' ) && false !== strpos( $s, 'FROM sn_pageviews_v2' ), 'sessions: one read, the custom event name from blob17' );
ok( false !== strpos( sn_analytics_percentiles_sql( 'sc', 'double1', '2026-10-06', '2026-10-19', 'human' ), 'FROM sn_pageviews_v2' ), 'percentiles follow their own window' );
ok( false !== strpos( sn_analytics_dims_rollup_sql( 'timezone', 7 ), 'blob16 AS value,' ) && false !== strpos( sn_analytics_dims_rollup_sql( 'country', 7 ), 'blob4 AS value,' ), 'the timezone dimension reads blob16; the others did not move' );

echo "\nThe same statements, before the window clears the first full day\n";
sn_analytics_clock( $oct08 ); // a 7-day window from Oct 8 reaches Sep 30.
ok( false !== strpos( sn_analytics_events_rollup_sql( 7 ), 'blob16 AS name,' ) && false !== strpos( sn_analytics_events_rollup_sql( 7 ), 'FROM sn_pageviews ' ), 'verified, but the window reaches back: the legacy statement, column for column' );
ok( false !== strpos( sn_analytics_utm_rollup_sql( 7 ), 'blob20 AS packed,' ) && false !== strpos( sn_analytics_dims_rollup_sql( 'timezone', 7 ), 'blob19 AS value,' ), 'UTM packed and timezone blob19, as before' );
ok( false !== strpos( sn_analytics_session_sql( '2026-10-01', '2026-10-07', 'human', 100 ), 'blob16 AS ce,' ) && false !== strpos( sn_analytics_session_sql( '2026-10-01', '2026-10-07', 'human', 100 ), 'FROM sn_pageviews ' ), 'sessions over an older window too' );
sn_analytics_clock( 0 );

echo "\nUTM rows, repacked\n";
$sep = "\x1f";
$rows = sn_analytics_utm_pack_rows( array( array( 'us' => 'google', 'um' => 'cpc', 'uc' => 'Sale', 'utc' => 'kw' . $sep . 'ad1', 'views' => 3 ), array( 'us' => 'x', 'um' => '', 'uc' => '', 'utc' => '', 'views' => 1 ), array( 'packed' => 'legacy', 'views' => 2 ) ) );
ok( 'google' . $sep . 'cpc' . $sep . 'Sale' . $sep . 'kw' . $sep . 'ad1' === $rows[0]['packed'], 'five fields, in the legacy order' );
ok( 'x' . $sep . $sep . $sep . $sep === $rows[1]['packed'] && 5 === count( explode( $sep, $rows[1]['packed'] ) ), 'no term and no content still makes five fields' );
ok( array( 'google', 'cpc', 'Sale', 'kw', 'ad1' ) === array_values( sn_analytics_utm_split( $rows[0]['packed'] ) ), 'the existing splitter reads it back' );
ok( 'legacy' === $rows[2]['packed'], 'a legacy row passes through untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
