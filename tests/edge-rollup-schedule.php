<?php
/**
 * Guard: the daily edge rollup runs at 01:15 UTC, so a day's 5xx are stored
 * about an hour after the day closes and a break mails within ~2h, not ~21h.
 * A rollup scheduled at another time (20:43 UTC on the live site, the moment
 * it was first seen unscheduled) is moved once; when the move would leave
 * yesterday unread until tomorrow's run, one run now stores it.
 *
 * Run: php tests/edge-rollup-schedule.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DAY_IN_SECONDS', 86400 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( __DIR__ . '/../inc/edge-rollup.php' );
preg_match( "/const SN_EDGE_ROLLUP_AT = '([0-9:]+)';/", $src, $at );
define( 'SN_EDGE_ROLLUP_AT', $at[1] ?? '' );
define( 'SN_EDGE_ROLLUP_HOOK', 'sn_edge_rollup_cron' );
define( 'SN_EDGE_ERRORS_READ_OPT', 'sn_edge_errors_read_days' );
foreach ( array( 'sn_edge_rollup_next_run', 'sn_edge_maybe_schedule' ) as $name ) {
	if ( ! preg_match( '/function ' . $name . '\(.*?\n}\n/s', $src, $m ) ) { ok( false, "$name() found" ); continue; }
	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only extraction.
}

$GLOBALS['next'] = false; $GLOBALS['ev'] = array(); $GLOBALS['opt'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['next']; }
function wp_clear_scheduled_hook( $h ) { $GLOBALS['ev'][] = array( 'clear', $h ); $GLOBALS['next'] = false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['ev'][] = array( 'event', $t, $r ); $GLOBALS['next'] = $t; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['ev'][] = array( 'single', $t ); }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }

echo "\nThe run time\n";
ok( '01:15' === SN_EDGE_ROLLUP_AT, 'the rollup runs at 01:15 UTC' );
$t = strtotime( '2026-10-10 00:30:00 UTC' );
ok( strtotime( '2026-10-10 01:15:00 UTC' ) === sn_edge_rollup_next_run( $t ), 'before 01:15, the next run is today' );
ok( strtotime( '2026-10-11 01:15:00 UTC' ) === sn_edge_rollup_next_run( strtotime( '2026-10-10 01:15:00 UTC' ) ), 'at 01:15 exactly, the next run is tomorrow (strictly after now)' );

echo "\nScheduling\n";
sn_edge_maybe_schedule();
ok( 1 === count( $GLOBALS['ev'] ) && 'event' === $GLOBALS['ev'][0][0] && '01:15' === gmdate( 'H:i', $GLOBALS['ev'][0][1] ) && 'daily' === $GLOBALS['ev'][0][2], 'unscheduled: one daily event at 01:15 UTC' );
$GLOBALS['ev'] = array();
sn_edge_maybe_schedule();
ok( array() === $GLOBALS['ev'], 'already at 01:15: left alone' );
$GLOBALS['next'] = strtotime( 'tomorrow 20:43:00 UTC' ); $GLOBALS['opt'] = array( SN_EDGE_ERRORS_READ_OPT => array( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) => '' ) );
sn_edge_maybe_schedule();
ok( array( 'clear', SN_EDGE_ROLLUP_HOOK ) === $GLOBALS['ev'][0] && 'event' === $GLOBALS['ev'][1][0] && '01:15' === gmdate( 'H:i', $GLOBALS['ev'][1][1] ) && 2 === count( $GLOBALS['ev'] ), 'a 20:43 run is moved to 01:15; yesterday already stored, so no extra run' );
$GLOBALS['ev'] = array(); $GLOBALS['next'] = strtotime( 'tomorrow 20:43:00 UTC' ); $GLOBALS['opt'] = array();
sn_edge_maybe_schedule();
ok( in_array( 'single', array_column( $GLOBALS['ev'], 0 ), true ), 'moving it with yesterday unread runs once now, so the move never skips a day' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
