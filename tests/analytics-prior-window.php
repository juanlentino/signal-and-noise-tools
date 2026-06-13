<?php
/**
 * Tests for sn_analytics_prior_window() — the DRY prior-window helper extracted
 * from sn_analytics_period_deltas().
 * Run: php tests/analytics-prior-window.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );

require __DIR__ . '/../inc/analytics-derived.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

echo "\nGroup: prior_window\n";
list( $pf, $pt ) = sn_analytics_prior_window( '2026-06-06', '2026-06-12' ); // 7-day window
ok( $pt === '2026-06-05', 'prior `to` = day before `from`' );
ok( $pf === '2026-05-30', 'prior window is the same 7-day length' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
