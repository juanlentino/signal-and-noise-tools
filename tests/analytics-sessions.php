<?php
/**
 * Unit tests for the cookieless within-day session engine (inc/analytics-sessions.php).
 * Run: php tests/analytics-sessions.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
require __DIR__ . '/../inc/analytics-sessions.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: sn_analytics_session_config\n";
$cfg = sn_analytics_session_config();
ok( is_array( $cfg ), 'returns an array' );
ok( 1800 === $cfg['gap_sec'], 'default gap is 1800s (30 min)' );
ok( 50 === $cfg['engaged_scroll'], 'default engaged scroll is 50%' );
ok( 15000 === $cfg['engaged_ms'], 'default engaged dwell is 15000ms' );
ok( 50000 === $cfg['row_cap'], 'default row cap is 50000' );

echo "\nGroup: sn_sessionize\n";
function ev( $vid, $ts, $type, $path = '', $ce = '', $scroll = 0, $dwell = 0 ) {
	return array( 'vid' => $vid, 'ts' => $ts, 'ev' => $type, 'path' => $path, 'ref' => '', 'ce' => $ce, 'scroll' => $scroll, 'dwell' => $dwell );
}
// Two visitors; visitor A has a >30min gap → two visits; visitor B one visit.
$rows = array(
	ev( 'A', 1000, 'pv', '/a' ),
	ev( 'A', 1100, 'pv', '/b' ),        // +100s → same visit
	ev( 'A', 1100 + 1801, 'pv', '/c' ), // +1801s → new visit
	ev( 'B', 5000, 'pv', '/x' ),
);
$visits = sn_sessionize( $rows, 1800 );
ok( 3 === count( $visits ), 'A splits into 2 visits + B one visit = 3' );
ok( 2 === count( $visits[0] ), 'A first visit has 2 events' );
ok( 1 === count( $visits[1] ), 'A second visit has 1 event' );
ok( '/x' === $visits[2][0]['path'], 'B visit carries its event' );
// Boundary: exactly gap_sec apart stays in the same visit (only STRICTLY greater splits).
$edge = sn_sessionize( array( ev( 'C', 0, 'pv', '/a' ), ev( 'C', 1800, 'pv', '/b' ) ), 1800 );
ok( 1 === count( $edge ), 'gap == gap_sec does NOT split (only > splits)' );
// Unsorted input is ordered by ts within a visitor.
$uns = sn_sessionize( array( ev( 'D', 200, 'pv', '/2' ), ev( 'D', 100, 'pv', '/1' ) ), 1800 );
ok( '/1' === $uns[0][0]['path'], 'events sorted by ts within visitor' );

echo "\nGroup: sn_visit_summary\n";
$visit = array(
	ev( 'A', 100, 'pv', '/post' ),
	ev( 'A', 105, 'sc', '/post', '', 80, 0 ),      // 80% scroll on /post
	ev( 'A', 140, 'tm', '/post', '', 0, 20000 ),   // 20s dwell on /post
	ev( 'A', 150, 'pv', '/next' ),
	ev( 'A', 160, 'ce', '/next', 'subscribe' ),
);
$s = sn_visit_summary( $visit, 50, 15000 );
ok( '/post' === $s['entry'], 'entry is first pageview path' );
ok( '/next' === $s['exit'], 'exit is last pageview path' );
ok( array( '/post', '/next' ) === $s['path'], 'path lists pageviews in order' );
ok( 2 === $s['pageviews'], 'two pageviews' );
ok( 60 === $s['duration'], 'duration = last ts - first ts (160-100)' );
ok( true === $s['engaged'], '/post cleared 50% + 15s → engaged' );
ok( in_array( 'subscribe', $s['goals'], true ), 'subscribe goal captured' );
// Not engaged: scroll below floor.
$s2 = sn_visit_summary( array( ev( 'B', 0, 'pv', '/x' ), ev( 'B', 2, 'sc', '/x', '', 40, 0 ), ev( 'B', 3, 'tm', '/x', '', 0, 60000 ) ), 50, 15000 );
ok( false === $s2['engaged'], 'scroll 40% < 50% → not engaged despite long dwell' );
// Bounce shape: single pageview.
$s3 = sn_visit_summary( array( ev( 'C', 0, 'pv', '/only' ) ), 50, 15000 );
ok( 1 === $s3['pageviews'] && '/only' === $s3['entry'] && '/only' === $s3['exit'], 'single-pageview visit' );

echo "\nGroup: sn_session_metrics\n";
$summaries = array(
	array( 'entry' => '/a', 'exit' => '/a', 'path' => array( '/a' ), 'pageviews' => 1, 'duration' => 0,  'engaged' => false, 'goals' => array(), 'events' => array() ),
	array( 'entry' => '/a', 'exit' => '/c', 'path' => array( '/a', '/b', '/c' ), 'pageviews' => 3, 'duration' => 120, 'engaged' => true, 'goals' => array(), 'events' => array() ),
	array( 'entry' => '/b', 'exit' => '/b', 'path' => array( '/b' ), 'pageviews' => 1, 'duration' => 40, 'engaged' => true, 'goals' => array(), 'events' => array() ),
);
$m = sn_session_metrics( $summaries );
ok( 3 === $m['visits'], 'visits = 3' );
ok( abs( $m['bounce_rate'] - ( 2 / 3 ) ) < 0.001, 'bounce_rate = 2 single-pageview / 3' );
ok( abs( $m['pages_per_visit'] - ( 5 / 3 ) ) < 0.001, 'pages_per_visit = 5/3' );
ok( 40 === $m['median_duration'], 'median duration of {0,120,40} = 40' );
ok( 2 === $m['engaged_visits'], '2 engaged visits' );
ok( 0 === sn_session_metrics( array() )['visits'], 'empty input → 0 visits, no divide-by-zero' );

echo "\nGroup: sn_session_paths\n";
$sp = array(
	array( 'path' => array( '/a', '/b', '/c' ) ),
	array( 'path' => array( '/a', '/b' ) ),
	array( 'path' => array( '/a' ) ),
);
$paths = sn_session_paths( $sp, 10 );
ok( isset( $paths[0]['from'], $paths[0]['to'], $paths[0]['count'] ), 'rows have from/to/count' );
ok( '/a' === $paths[0]['from'] && '/b' === $paths[0]['to'] && 2 === $paths[0]['count'], 'A→B is top transition with count 2' );
$found = false;
foreach ( $paths as $p ) { if ( '/b' === $p['from'] && '/c' === $p['to'] ) { $found = ( 1 === $p['count'] ); } }
ok( $found, 'B→C counted once' );
ok( array() === sn_session_paths( array(), 10 ), 'empty input → empty list' );

echo "\nGroup: sn_funnel_report\n";
function vs_events( $events ) { return sn_visit_summary( $events, 50, 15000 ); }
$f_summaries = array(
	vs_events( array( ev( 'A', 0, 'pv', '/home' ), ev( 'A', 10, 'pv', '/post/x' ), ev( 'A', 20, 'ce', '/post/x', 'subscribe' ) ) ), // full
	vs_events( array( ev( 'B', 0, 'pv', '/home' ), ev( 'B', 10, 'pv', '/post/y' ) ) ),                                              // stops at step 2
	vs_events( array( ev( 'C', 0, 'pv', '/home' ) ) ),                                                                             // stops at step 1
	vs_events( array( ev( 'D', 0, 'pv', '/about' ) ) ),                                                                            // never enters
);
$funnel = array(
	array( 'match' => 'path', 'value' => '/home',  'prefix' => false ),
	array( 'match' => 'path', 'value' => '/post/', 'prefix' => true ),
	array( 'match' => 'ce',   'value' => 'subscribe', 'prefix' => false ),
);
$rep = sn_funnel_report( $f_summaries, $funnel );
ok( 3 === $rep[0]['reached'], 'step 1 reached by 3 visits (entered /home)' );
ok( 2 === $rep[1]['reached'], 'step 2 (/post/ prefix) reached by 2' );
ok( 1 === $rep[2]['reached'], 'step 3 (subscribe) reached by 1' );
ok( abs( $rep[1]['rate'] - ( 2 / 3 ) ) < 0.001, 'step 2 rate is relative to step 1 count' );
ok( 0 === $rep[0]['drop'], 'first step has no drop' );
ok( 1 === $rep[1]['drop'], 'step 2 dropped 1 from step 1' );
// Ordering matters: subscribe BEFORE reaching /post/ does not count step 3.
$order = array( vs_events( array( ev( 'E', 0, 'ce', '/home', 'subscribe' ), ev( 'E', 5, 'pv', '/home' ), ev( 'E', 9, 'pv', '/post/z' ) ) ) );
$rep2  = sn_funnel_report( $order, $funnel );
ok( 0 === $rep2[2]['reached'], 'subscribe before step 2 does not complete the funnel' );

echo "\nGroup: sn_analytics_session_sql\n";
$sql = sn_analytics_session_sql( '2026-06-01', '2026-06-30', 'human', 50000 );
ok( false !== strpos( $sql, 'FROM sn_pageviews' ), 'targets the sn_pageviews dataset' );
ok( false !== strpos( $sql, "blob7 = 'human'" ), 'filters the requested traffic class' );
ok( false !== strpos( $sql, "toDateTime('2026-06-01 00:00:00')" ) && false !== strpos( $sql, "toDateTime('2026-06-30 23:59:59')" ), 'bounds the window by DateTime-typed literals (AE 422s on DateTime vs String)' );
ok( false === strpos( $sql, 'ORDER BY' ), 'no ORDER BY — AE 422s ordering on index1/timestamp; sn_sessionize sorts per visitor in PHP' );
ok( false !== strpos( $sql, 'LIMIT 50000' ), 'applies the row cap' );
ok( '' === sn_analytics_session_sql( 'not-a-date', '2026-06-30', 'human', 100 ), 'bad from-date rejected → empty SQL' );
ok( '' === sn_analytics_session_sql( '2026-06-01', '2026-06-30', 'robot', 100 ), 'non-whitelisted class rejected → empty SQL' );

echo "\nGroup: sn_pageview_visits\n";
$mix = array( array( 'pageviews' => 2 ), array( 'pageviews' => 0 ), array( 'pageviews' => 1 ) );
$pvv = sn_pageview_visits( $mix );
ok( 2 === count( $pvv ), 'drops pageview-less (server/RSS/orphan-beacon) groups' );
ok( 2 === $pvv[0]['pageviews'] && 1 === $pvv[1]['pageviews'], 'keeps only pv-bearing visits, re-indexed' );
ok( array() === sn_pageview_visits( array() ), 'empty input → empty' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
