<?php
/**
 * Unit test for the session-rollup row normalizer. Run: php tests/analytics-session-rollup.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
// The rollup module registers hooks at load; stub them to no-ops.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
require __DIR__ . '/../inc/analytics-session-rollup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: sn_session_rollup_normalize\n";
$rows = array(
	array( 'day' => '2026-06-01', 'class' => 'human', 'visits' => '12.0', 'bounce_pct' => '40', 'ppv' => '1.8', 'median_dur' => '55' ),
	array( 'day' => 'bad-date',   'class' => 'human', 'visits' => 5 ),          // dropped
	array( 'day' => '2026-06-01', 'class' => 'martian', 'visits' => 3 ),        // dropped (class)
);
$clean = sn_session_rollup_normalize( $rows );
ok( 1 === count( $clean ), 'only the valid row survives' );
ok( 12 === $clean[0]['visits'], 'visits coerced to int' );
ok( '2026-06-01' === $clean[0]['day'] && 'human' === $clean[0]['class'], 'day/class preserved' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
