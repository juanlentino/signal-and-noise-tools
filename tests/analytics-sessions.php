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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
