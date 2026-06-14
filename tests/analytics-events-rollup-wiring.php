<?php
/**
 * Static wiring guard for inc/analytics-events-rollup.php: it must be required
 * in the plugin bootstrap (after analytics-events.php), called from the existing
 * rollup cron behind a function_exists guard, and the AE contract docblock must
 * document blob16/17/18.
 * Run: php tests/analytics-events-rollup-wiring.php
 * @since plugin v6.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }

$bootstrap = (string) file_get_contents( $root . '/signal-and-noise-tools.php' );
ok( strpos( $bootstrap, "require_once SNT_PATH . 'inc/analytics-events-rollup.php'" ) !== false, 'bootstrap: requires inc/analytics-events-rollup.php' );
$pos_evt = strpos( $bootstrap, "inc/analytics-events.php" );
$pos_rup = strpos( $bootstrap, "inc/analytics-events-rollup.php" );
ok( $pos_evt !== false && $pos_rup !== false && $pos_rup > $pos_evt, 'bootstrap: rollup required AFTER analytics-events.php (upsert dependency)' );

$cron = (string) file_get_contents( $root . '/inc/analytics-rollup.php' );
ok( strpos( $cron, "function_exists( 'sn_analytics_events_run_rollup' )" ) !== false, 'cron: guards the new runner with function_exists' );
ok( strpos( $cron, 'sn_analytics_events_run_rollup();' ) !== false, 'cron: calls sn_analytics_events_run_rollup() in the rollup pass' );
$run_fn = strstr( $cron, 'function sn_analytics_run_rollup()' );
ok( is_string( $run_fn ) && strpos( $run_fn, 'sn_analytics_events_run_rollup();' ) !== false, 'cron: the call is inside sn_analytics_run_rollup()' );

$api = (string) file_get_contents( $root . '/inc/analytics-api.php' );
ok( strpos( $api, 'blob16' ) !== false && strpos( $api, 'blob17' ) !== false && strpos( $api, 'blob18' ) !== false, 'contract: analytics-api docblock documents blob16/17/18' );
ok( stripos( $api, 'custom-event' ) !== false, 'contract: docblock describes the custom-event name/property/value triple' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
