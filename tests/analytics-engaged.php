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
$GLOBALS['__dist'] = array();
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) { return $GLOBALS['__dist']; }
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
