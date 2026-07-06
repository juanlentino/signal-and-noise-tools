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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
