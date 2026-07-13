<?php
/**
 * Tests for sn_analytics_engaged_rate() and sn_analytics_engaged_rate_delta().
 * Run: php tests/analytics-engaged.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

function sn_analytics_buckets_metrics() {
	return array( 'time' => array( 'buckets' => array(
		array( 'label' => '0–10s',  'lo' => 0,      'hi' => 10000 ),
		array( 'label' => '10–30s', 'lo' => 10000,  'hi' => 30000 ),
		array( 'label' => '30–60s', 'lo' => 30000,  'hi' => 60000 ),
		array( 'label' => '1–3m',   'lo' => 60000,  'hi' => 180000 ),
		array( 'label' => '3m+',    'lo' => 180000, 'hi' => null ),
	) ) );
}
$GLOBALS['__dist']           = array();
$GLOBALS['__dist_by']        = array();
$GLOBALS['__dist_by_window'] = array(); // D2: explicit-cwin override, keyed "$from|$to" — takes priority
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) {
	$key = "$f|$t";
	if ( isset( $GLOBALS['__dist_by_window'][ $key ] ) ) { return $GLOBALS['__dist_by_window'][ $key ]; }
	if ( isset( $GLOBALS['__dist_by'][ $f ] ) ) { return $GLOBALS['__dist_by'][ $f ]; }
	return $GLOBALS['__dist'];
}
require __DIR__ . '/../inc/analytics-derived.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

echo "\nGroup: engaged_rate\n";
$GLOBALS['__dist'] = array(
	array( 'label' => '0–10s', 'views' => 80 ),
	array( 'label' => '10–30s', 'views' => 10 ),
	array( 'label' => '30–60s', 'views' => 5 ),
	array( 'label' => '1–3m', 'views' => 3 ),
	array( 'label' => '3m+', 'views' => 2 ),
);
ok( sn_analytics_engaged_rate( 'a', 'b', 'human' ) === 20, '(10+5+3+2)/100 = 20%' );
$GLOBALS['__dist'] = array();
ok( sn_analytics_engaged_rate( 'a', 'b', 'human' ) === null, 'no timed pageviews → null' );

echo "\nGroup: engaged_rate_delta\n";
$GLOBALS['__dist'] = array(
	array( 'label' => '0–10s', 'views' => 50 ),
	array( 'label' => '10–30s', 'views' => 50 ),
);
$d = sn_analytics_engaged_rate_delta( '2026-06-06', '2026-06-12', 'human' );
ok( $d['current'] === 50 && $d['previous'] === 50, 'current & previous computed' );
ok( $d['dir'] === 'flat', 'equal windows → flat' );
ok( array_key_exists( 'pct', $d ), 'delta exposes pct key' );

echo "\nGroup: engaged_rate_delta null-prior\n";
$GLOBALS['__dist']    = array();                                  // default (prior window) = no data
$GLOBALS['__dist_by'] = array( '2026-06-06' => array(            // current window from-date
	array( 'label' => '0–10s', 'views' => 50 ),
	array( 'label' => '10–30s', 'views' => 50 ),
) );
$dn = sn_analytics_engaged_rate_delta( '2026-06-06', '2026-06-12', 'human' );
ok( $dn['current'] === 50, 'current window computed (50%)' );
ok( $dn['previous'] === null, 'prior window with no data → previous is null (not 0)' );
ok( $dn['dir'] === 'flat' && $dn['pct'] === null, 'null prior → flat, NO fabricated up-arrow' );

echo "\nGroup: D2 — explicit compare window (one frame)\n";
$GLOBALS['__dist_by_window'] = array(
	'2026-07-06|2026-07-12' => array( array( 'label' => '0–10s', 'views' => 70 ), array( 'label' => '10–30s', 'views' => 30 ) ),
	'2026-06-29|2026-07-05' => array( array( 'label' => '0–10s', 'views' => 80 ), array( 'label' => '10–30s', 'views' => 20 ) ),
	'2025-07-06|2025-07-12' => array( array( 'label' => '0–10s', 'views' => 10 ), array( 'label' => '10–30s', 'views' => 90 ) ),
);
$e_prev = sn_analytics_engaged_rate_delta( '2026-07-06', '2026-07-12', 'human' );
ok( 20 === ( $e_prev['previous'] ?? -1 ) && 'up' === $e_prev['dir'], 'engaged_rate_delta: null cwin keeps the prior-window basis (back-compat pin)' );
$e_yoy = sn_analytics_engaged_rate_delta( '2026-07-06', '2026-07-12', 'human', array( '2025-07-06', '2025-07-12' ) );
ok( 90 === ( $e_yoy['previous'] ?? -1 ) && 'down' === $e_yoy['dir'], 'engaged_rate_delta: explicit cwin is the basis (yoy window read, prior window ignored)' );
$GLOBALS['__dist_by_window'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
