<?php
/**
 * Home's seven days are the Analytics app's 7d (review on #2020, P3-5): the
 * site's days, not UTC's. In New York at 22:00 on October 11 it is already
 * October 12 in UTC, so a UTC week starts and ends a day late and Home's
 * Visits differs from the app's 7d Visits it links to.
 *
 * Run: php tests/dashboard-home-week.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
require __DIR__ . '/../inc/analytics-admin.php';
require __DIR__ . '/../inc/dash-zone-measurement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$now = gmmktime( 2, 0, 0, 10, 12, 2026 ); // 22:00 on October 11 in New York
ok( '2026-10-12' === gmdate( 'Y-m-d', $now ), 'sanity: it is October 12 in UTC' );
ok( array( '2026-10-05', '2026-10-11' ) === snt_dashboard_week( $now ), 'Home\'s week is the site\'s: October 5 to 11, not UTC\'s 6 to 12' );
ok( snt_analytics_range_dates( 7, $now ) === snt_dashboard_week( $now ), '...exactly the Analytics app\'s 7d window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
