<?php
/**
 * Tests for the anomaly-arc derived functions (A1 + A6 data layer).
 * Run: php tests/analytics-anomalies.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'SN_ANALYTICS_CLASSES' ) ) { define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) ); }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }

$GLOBALS['__paths']  = array();
$GLOBALS['__totals'] = array();
function sn_analytics_top_paths( $from, $to, $class = 'human', $limit = 25 ) { return $GLOBALS['__paths']; }
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__totals'][ "$from|$to" ] ?? array( 'views' => 0, 'visits' => 0, 'scroll_avg' => 0, 'time_avg' => 0 ); }

require_once __DIR__ . '/../inc/analytics-derived.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Anomaly-arc derived functions\n\n";

echo "Group: stat summary + z-score\n";
$s = sn_analytics_stat_summary( array( 2, 4, 4, 4, 5, 5, 7, 9 ) );
ok( abs( $s['mean'] - 5.0 ) < 1e-9, 'stat: mean of the sample is 5' );
ok( abs( $s['sd'] - 2.0 ) < 1e-9, 'stat: population sd of the sample is 2' );
ok( 8 === $s['n'], 'stat: n counts the sample' );
$e = sn_analytics_stat_summary( array() );
ok( 0 === $e['n'] && 0.0 === $e['sd'], 'stat: empty input → n=0, sd=0 (no divide-by-zero)' );
ok( abs( sn_analytics_zscore( 9, 5, 2 ) - 2.0 ) < 1e-9, 'zscore: (9-5)/2 = 2' );
ok( 0.0 === sn_analytics_zscore( 9, 5, 0 ), 'zscore: sd=0 → 0 (never divides by zero)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
