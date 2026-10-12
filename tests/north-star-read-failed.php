<?php
/**
 * The north star when the analytics read fails (2026.10.4 audit, batch 4):
 * a failed query is not missing credentials, and it is held a minute, not
 * the hour a good reading is cached for. Home said "Needs the analytics
 * credentials." for an hour after one failed read, and Refresh changed
 * nothing (the Refresh half is pinned in tests/openstation-app-dashboard.php).
 *
 * Run: php tests/north-star-read-failed.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['__ttl']  = array();
$GLOBALS['__on']   = true;
$GLOBALS['__read'] = false; // the session fetch: false = configured=false (no SQL, or the read failed)
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $d; }
function add_action() {}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__ttl'][] = $t; return true; }
function get_option( $k, $d = null ) { return $d; }
function get_posts( $a ) { return array(); }
function sn_analytics_config() { return $GLOBALS['__on'] ? array( 'account' => 'x' ) : null; }
function sn_analytics_fetch_session_events( $from, $to, $scope ) {
	return array( 'visits' => array(), 'capped' => false, 'configured' => $GLOBALS['__read'] );
}
require __DIR__ . '/../inc/north-star.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "North star: a failed read is not missing credentials\n\n";

$failed = snt_nsm_reading( true );
ok( false === $failed['configured'] && true === ( $failed['read_failed'] ?? null ), 'configured analytics, failed read: read_failed, never "needs the credentials"' );
ok( array( 60 ) === $GLOBALS['__ttl'], 'a failed reading is held a minute, not the hour a good one is cached for' );

$GLOBALS['__ttl'] = array();
$GLOBALS['__on']  = false;
$off = snt_nsm_reading( true );
ok( false === $off['configured'] && false === ( $off['read_failed'] ?? null ), 'analytics not configured: not a failed read, the credentials are what is missing' );

$GLOBALS['__ttl']  = array();
$GLOBALS['__on']   = true;
$GLOBALS['__read'] = true;
$good = snt_nsm_reading( true );
ok( true === $good['configured'] && false === $good['read_failed'] && array( HOUR_IN_SECONDS ) === $GLOBALS['__ttl'], 'a good read: configured, not failed, cached the hour' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
