<?php
/**
 * Tests for the custom date-range + preset resolver (durable; no AE).
 * Run: php tests/analytics-date-range.php
 * @since plugin v6.7.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
require __DIR__ . '/../inc/analytics-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: is_ymd\n";
ok( snt_analytics_is_ymd( '2026-06-13' ) === true, 'valid date' );
ok( snt_analytics_is_ymd( '2026-13-01' ) === false, 'bad month rejected' );
ok( snt_analytics_is_ymd( '2026-02-30' ) === false, 'impossible day rejected (checkdate)' );
ok( snt_analytics_is_ymd( 'junk' ) === false, 'non-date rejected' );

echo "\nGroup: preset_dates (anchor 2026-06-13, mid-Q2)\n";
$now = gmmktime( 0, 0, 0, 6, 13, 2026 );
ok( snt_analytics_preset_dates( 'ytd', $now ) === array( '2026-01-01', '2026-06-13' ), 'ytd = Jan 1 → today' );
ok( snt_analytics_preset_dates( 'last-month', $now ) === array( '2026-05-01', '2026-05-31' ), 'last-month = full May' );
ok( snt_analytics_preset_dates( 'last-quarter', $now ) === array( '2026-01-01', '2026-03-31' ), 'last-quarter = Q1' );
ok( snt_analytics_preset_dates( 'prev-year', $now ) === array( '2025-01-01', '2025-12-31' ), 'prev-year = full 2025' );

echo "\nGroup: preset_dates January boundary (anchor 2026-01-15, Q1)\n";
$jan = gmmktime( 0, 0, 0, 1, 15, 2026 );
ok( snt_analytics_preset_dates( 'last-month', $jan ) === array( '2025-12-01', '2025-12-31' ), 'Jan → last-month crosses year to Dec 2025' );
ok( snt_analytics_preset_dates( 'last-quarter', $jan ) === array( '2025-10-01', '2025-12-31' ), 'Q1 → last-quarter = Q4 2025' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
