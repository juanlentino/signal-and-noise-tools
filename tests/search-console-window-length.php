<?php
/**
 * Regression test (#1228): snt_gsc_window( $days ) must return an
 * $days-day window, not $days+1. The floor on the back-offset was
 * max(1, $days - 1), so window(1) computed start = end - 1 day (a
 * 2-day inclusive window) instead of start === end (a 1-day window).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require __DIR__ . '/../inc/search-console-client.php';

$days_to_diff = function ( $days ) {
	$w = snt_gsc_window( $days, 0 );
	$s = new DateTime( $w['start'] );
	$e = new DateTime( $w['end'] );
	return (int) $s->diff( $e )->days; // calendar days between start and end
};

ok( 0 === $days_to_diff( 1 ), 'days=1 -> start === end (a true 1-day window), not a 2-day window' );
ok( 6 === $days_to_diff( 7 ), 'days=7 -> a 6-day span between start/end (7 CALENDAR days inclusive)' );
ok( 27 === $days_to_diff( 28 ), 'days=28 (the default) -> unchanged, a 28-day inclusive window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
