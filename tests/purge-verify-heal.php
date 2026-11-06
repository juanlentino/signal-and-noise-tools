<?php
/**
 * Tests: a purge whose deferred verify was lost gets a new one.
 *
 * Born from 2026-11-05: an automatic update purged at 22:47:30 UTC, the theme
 * queued its verify, and the verify never ran and was no longer queued, so the
 * dashboard read "Purge dispatched, verifying" until the next purge.
 *
 * Run: php tests/purge-verify-heal.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__opts']    = array();
$GLOBALS['__actions'] = array();
$GLOBALS['__queued']  = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function add_action( $hook, $cb ) { $GLOBALS['__actions'][ $hook ][] = $cb; }

require __DIR__ . '/../inc/purge-verify-heal.php';

$now   = 1793929893;
$grace = SNT_PURGE_VERIFY_GRACE;

// The decision, pure.
$pending = array( 'time' => $now - HOUR_IN_SECONDS, 'epoch' => 42 );
ok( 600 === $grace, 'the grace is ten minutes, well past the 75-second verify' );
ok( 42 === snt_purge_verify_lost_epoch( $pending, $now ), 'a purge pending past the grace needs a new verify' );
ok( 42 === snt_purge_verify_lost_epoch( array( 'time' => $now - $grace, 'epoch' => 42 ), $now ), 'exactly at the grace counts' );
ok( 0 === snt_purge_verify_lost_epoch( array( 'time' => $now - $grace + 1, 'epoch' => 42 ), $now ), 'inside the grace the first verify still has its turn' );
ok( 0 === snt_purge_verify_lost_epoch( $pending + array( 'resolved' => true ), $now ), 'a fresh verdict is left alone' );
ok( 0 === snt_purge_verify_lost_epoch( $pending + array( 'resolved' => false ), $now ), 'a stale verdict is a verdict: it is reported, not retried' );
ok( 0 === snt_purge_verify_lost_epoch( array( 'time' => $now - HOUR_IN_SECONDS ), $now ), 'no epoch, nothing the verify could bind to' );
ok( 0 === snt_purge_verify_lost_epoch( array( 'time' => $now - HOUR_IN_SECONDS, 'epoch' => 0 ), $now ), 'a zero epoch is no epoch' );
ok( 0 === snt_purge_verify_lost_epoch( array( 'epoch' => 42 ), $now ), 'no time, not a purge we can age' );
ok( 0 === snt_purge_verify_lost_epoch( array(), $now ), 'an empty report needs nothing' );
ok( 0 === snt_purge_verify_lost_epoch( false, $now ), 'no report needs nothing' );
ok( 0 === snt_purge_verify_lost_epoch( 'garbage', $now ), 'a malformed report needs nothing' );

// The run, without the theme: it must not fatal, and there is nobody to ask.
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => time() - HOUR_IN_SECONDS, 'epoch' => 42 );
snt_purge_verify_heal();
ok( array() === $GLOBALS['__queued'], 'with the theme absent the run does nothing and does not fatal' );

// The theme's scheduler, which already refuses a duplicate for the same epoch.
// Conditional on purpose: a plain declaration is hoisted and the run above would
// see it, so the theme-absent case would test nothing.
if ( ! function_exists( 'sn_schedule_auto_purge_verify' ) ) {
	function sn_schedule_auto_purge_verify( $epoch ) { $GLOBALS['__queued'][] = $epoch; }
}

snt_purge_verify_heal();
ok( array( 42 ) === $GLOBALS['__queued'], 'a lost verify is queued again, bound to the report\'s epoch' );

$GLOBALS['__queued']                       = array();
$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => time() - HOUR_IN_SECONDS, 'epoch' => 42, 'resolved' => true );
snt_purge_verify_heal();
ok( array() === $GLOBALS['__queued'], 'a resolved purge queues nothing, so the check cannot loop' );

$GLOBALS['__opts']['sn_last_purge_report'] = array( 'time' => time(), 'epoch' => 43 );
snt_purge_verify_heal();
ok( array() === $GLOBALS['__queued'], 'a purge that just happened queues nothing' );

ok( in_array( 'snt_purge_verify_heal', $GLOBALS['__actions']['snt_alerts_hourly'] ?? array(), true ), 'it rides the hourly hook that is always scheduled' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ), "inc/purge-verify-heal.php" ), 'the plugin loads it' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
