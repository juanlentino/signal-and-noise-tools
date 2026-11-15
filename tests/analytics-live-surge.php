<?php
/**
 * Tests for inc/analytics-live-surge.php: the live-surge ML pipeline. The slot log
 * (completed 5-minute slots, eight days), and the verdict: the last completed
 * slot against the same time of day on earlier days, robust z over median and
 * MAD, learning until four days are in.
 * Run: php tests/analytics-live-surge.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function add_filter( $h, $c = null, $p = 10, $a = 1 ) {}
function sn_analytics_stat_median( $v ) { sort( $v ); $n = count( $v ); if ( ! $n ) { return null; } $m = intdiv( $n, 2 ); return $n % 2 ? $v[ $m ] : ( $v[ $m - 1 ] + $v[ $m ] ) / 2; }
require dirname( __DIR__ ) . '/inc/analytics-live-surge.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$now = 1791490000;               // mid-slot
$cur = intdiv( $now, 300 ) * 300; // current (partial) slot start
$hour = array();
for ( $i = 11; $i >= 0; $i-- ) { $hour[] = array( 't' => $cur - $i * 300, 'readers' => 12 - $i ); }

echo "Group: the log\n";
$log = sn_analytics_live_log_merge( array(), $hour, $now );
ok( 11 === count( $log ) && ! isset( $log[ $cur ] ), 'completed slots are logged; the current partial one never is' );
ok( 11 === $log[ $cur - 300 ], 'a slot keeps its count' );
$old = array( $now - 9 * 86400 => 5, $now - 2 * 86400 => 1 );
$log = sn_analytics_live_log_merge( $old, $hour, $now );
ok( ! isset( $log[ $now - 9 * 86400 ] ) && isset( $log[ $now - 2 * 86400 ] ), 'older than eight days falls off; younger stays' );

echo "\nGroup: the verdict\n";
$slot = $cur - 300;
$base = function ( array $vals ) use ( $slot ) { $l = array(); foreach ( $vals as $d => $v ) { $l[ $slot - ( $d + 1 ) * 86400 ] = $v; } return $l; };
$v = sn_analytics_live_surge( $base( array( 1, 2, 1 ) ), $slot, 9 );
ok( 'learning' === $v['state'] && 3 === $v['days'], 'under four days of history: learning, never a verdict' );
$v = sn_analytics_live_surge( $base( array( 1, 2, 1, 2, 1, 1, 2 ) ), $slot, 9 );
ok( 'surge' === $v['state'] && $v['usual'] > 0 && $v['ratio'] >= 4, 'nine readers where 1 or 2 is usual: a surge, with the usual and the ratio' );
$v = sn_analytics_live_surge( $base( array( 1, 2, 1, 2, 1, 1, 2 ) ), $slot, 2 );
ok( 'usual' === $v['state'], 'two readers where 1 or 2 is usual: usual' );
$v = sn_analytics_live_surge( $base( array( 0, 0, 0, 0, 0 ) ), $slot, 2 );
ok( 'usual' === $v['state'], 'a rigid zero baseline does not make two readers a surge (one reader is the noise floor, three the minimum)' );
$v = sn_analytics_live_surge( $base( array( 0, 0, 0, 0, 0 ) ), $slot, 4 );
ok( 'surge' === $v['state'] && null === $v['ratio'], 'four readers where nobody usually is: a surge, with no ratio to a zero' );
$near = array( $slot - 86400 - 300 => 1, $slot - 2 * 86400 + 300 => 1, $slot - 3 * 86400 => 1, $slot - 4 * 86400 => 2, $slot - 86400 + 7200 => 50 );
$v = sn_analytics_live_surge( $near, $slot, 1 );
ok( 4 === $v['days'] && 'usual' === $v['state'], 'the baseline is the same time of day, one slot either side; two hours off is not in it' );
$v = sn_analytics_live_surge( $base( array( 1, 1, 1, 1 ) ) + array( $slot - 600 => 40 ), $slot, 1 );
ok( 'usual' === $v['state'], 'the last hour of today is not part of its own baseline' );


echo "\nGroup: readers apart from arrivals (2026-11-14)\n";
$GLOBALS['__o'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__o'][ $k ] = $v; return true; }
$tn  = time();
$tc  = intdiv( $tn, 300 ) * 300;
$hr  = array();
for ( $i = 11; $i >= 0; $i-- ) { $hr[] = array( 't' => $tc - $i * 300, 'readers' => 8, 'engaged' => 6 ); }
$v = sn_analytics_live_surge_step( $hr );
ok( 'visitors' === $v['basis'] && 0 === $v['readers_days'] && isset( $GLOBALS['__o'][ SN_ANALYTICS_SURGE_LOG_READERS ][ $tc - 300 ] ), 'with no readers baseline yet, surge judges visitors as before, says so, and starts the readers log' );
$seed = array();
for ( $d = 1; $d <= 5; $d++ ) { $seed[ $tc - 300 - $d * 86400 ] = 1; }
$GLOBALS['__o'][ SN_ANALYTICS_SURGE_LOG_READERS ] = $seed;
$v = sn_analytics_live_surge_step( $hr );
ok( 'readers' === $v['basis'] && 'surge' === $v['state'] && 6 === $v['readers'], 'once the readers baseline holds four days, surge judges readers: 6 against a usual 1 is a surge' );
unset( $hr[10]['engaged'] );
ok( 'visitors' === sn_analytics_live_surge_step( $hr )['basis'], 'an hour read before the split judges visitors' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
