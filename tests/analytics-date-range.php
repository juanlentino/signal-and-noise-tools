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

function sn_analytics_min_day() { return '2026-05-08'; }

echo "\nGroup: resolve_custom_window (now 2026-06-13, min_day 2026-05-08)\n";
$cnow = gmmktime( 0, 0, 0, 6, 13, 2026 );
ok( snt_analytics_resolve_custom_window( '2026-05-20', '2026-06-10', $cnow ) === array( '2026-05-20', '2026-06-10' ), 'valid window passes through' );
ok( snt_analytics_resolve_custom_window( '2026-06-10', '2026-05-20', $cnow ) === array( '2026-05-20', '2026-06-10' ), 'swapped from/to corrected' );
ok( snt_analytics_resolve_custom_window( '2026-05-20', '2030-01-01', $cnow ) === array( '2026-05-20', '2026-06-13' ), 'future to clamped to today' );
ok( snt_analytics_resolve_custom_window( '2000-01-01', '2026-06-10', $cnow ) === array( '2026-05-08', '2026-06-10' ), 'from before min_day clamped to min_day' );
ok( snt_analytics_resolve_custom_window( 'junk', '2026-06-10', $cnow ) === null, 'malformed from → null' );
ok( snt_analytics_resolve_custom_window( '2026-02-30', '2026-06-10', $cnow ) === null, 'impossible date → null' );

echo "\nGroup: resolve_window (single entry — token + dates)\n";
ok( snt_analytics_resolve_window( '7', '', '', $cnow ) === array( 7, '2026-06-07', '2026-06-13' ), 'int range delegates to range_dates' );
ok( snt_analytics_resolve_window( 'all', '', '', $cnow )[0] === 'all', 'all token preserved' );
ok( snt_analytics_resolve_window( 'ytd', '', '', $cnow ) === array( 'ytd', '2026-01-01', '2026-06-13' ), 'preset resolved' );
ok( snt_analytics_resolve_window( 'custom', '2026-05-20', '2026-06-10', $cnow ) === array( 'custom', '2026-05-20', '2026-06-10' ), 'valid custom resolved' );
ok( snt_analytics_resolve_window( 'custom', 'junk', '', $cnow ) === array( 7, '2026-06-07', '2026-06-13' ), 'invalid custom → 7d fallback' );
ok( snt_analytics_resolve_window( 'martian', '', '', $cnow )[0] === 7, 'unknown range → 7 default' );

echo "\nGroup: v9.34.0 semantic periods + rolling 14\n";
$sun = strtotime( '2026-07-12 12:00:00 UTC' ); // a Sunday
$mon = strtotime( '2026-07-06 09:00:00 UTC' ); // a Monday
ok( snt_analytics_preset_dates( 'this-week', $sun ) === array( '2026-07-06', '2026-07-12' ), 'this-week = ISO Monday → today' );
ok( snt_analytics_preset_dates( 'this-week', $mon ) === array( '2026-07-06', '2026-07-06' ), 'this-week on a Monday = a single day' );
ok( snt_analytics_preset_dates( 'this-month', $sun ) === array( '2026-07-01', '2026-07-12' ), 'this-month = 1st → today (MTD)' );
ok( snt_analytics_preset_dates( 'this-quarter', $sun ) === array( '2026-07-01', '2026-07-12' ), 'this-quarter = Q3 start → today' );
$jan15 = strtotime( '2026-01-15 12:00:00 UTC' );
ok( snt_analytics_preset_dates( 'this-quarter', $jan15 ) === array( '2026-01-01', '2026-01-15' ), 'this-quarter in Jan = Q1 start' );
ok( snt_analytics_resolve_window( 'this-month', '', '', $sun ) === array( 'this-month', '2026-07-01', '2026-07-12' ), 'resolve_window: semantic token resolves + survives' );
ok( snt_analytics_resolve_window( '14', '', '', $sun ) === array( 14, '2026-06-29', '2026-07-12' ), 'rolling 14d accepted (kept as a rolling option, distinct from the calendar periods)' );

echo "\nGroup: v9.34.0 comparison windows\n";
ok( snt_analytics_compare_window( '2026-07-06', '2026-07-12', 'prev' ) === array( '2026-06-29', '2026-07-05' ), 'prev: same-length window immediately before (adjacent, no overlap)' );
ok( snt_analytics_compare_window( '2026-07-01', '2026-07-01', 'prev' ) === array( '2026-06-30', '2026-06-30' ), 'prev: single-day window' );
ok( snt_analytics_compare_window( '2026-07-01', '2026-07-10', 'prev' ) === array( '2026-06-21', '2026-06-30' ), 'prev: 10-day length preserved across the month boundary' );
ok( snt_analytics_compare_window( '2026-07-06', '2026-07-12', 'yoy' ) === array( '2025-07-06', '2025-07-12' ), 'yoy: same dates one year earlier' );
ok( snt_analytics_compare_window( '2024-02-29', '2024-03-05', 'yoy' ) === array( '2023-02-28', '2023-03-05' ), 'yoy: Feb 29 clamps to Feb 28 (no prior-year twin)' );
ok( 'prev' === snt_analytics_resolve_compare( 'prev' ) && 'yoy' === snt_analytics_resolve_compare( 'yoy' ) && 'off' === snt_analytics_resolve_compare( 'nonsense' ) && 'off' === snt_analytics_resolve_compare( '' ), 'resolve_compare: whitelist with off default' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
