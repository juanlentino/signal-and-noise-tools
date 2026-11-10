<?php
/**
 * Guard: the nightly session rollup runs at 06:15 UTC.
 *
 * It writes yesterday's UTC day once and never rewrites it. The human class
 * leaves out visitor-days over the page-view cap through a list cached for an
 * hour, and a visitor-day starts at New York midnight (the analytics worker's
 * SN_ROTATE_TZ), so the one holding most of yesterday closes at 05:00 UTC in
 * winter and 04:00 UTC in summer. The run must come after the later of the two
 * plus the cache, or a visitor-day that crosses the cap after the run stays
 * stored as human. 00:30 UTC, the first plan, fails that.
 *
 * A run at another time (21:43 UTC on the live site) is moved once. When the
 * move would leave yesterday unwritten, one run for that day is queued, with
 * the day as its argument, and only the first move ever queues one (an option).
 *
 * Run: php tests/session-rollup-schedule.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['hooks'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][] = array( $h, $cb, $p, $n ); }
function add_filter() {}
require __DIR__ . '/../inc/analytics-session-rollup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
// The red run must print FAIL lines, not die on a function that is not there yet.
function call( $fn, ...$a ) { return function_exists( $fn ) ? $fn( ...$a ) : null; }
function utc( $s ) { return strtotime( $s . ' UTC' ); }
const MOVED = 'sn_session_rollup_moved';

// WP-Cron as WordPress keeps it: the daily has no arguments, so a one-off that
// carries a day is a separate event that neither a lookup nor a clear without
// arguments touches.
function wp_get_scheduled_event( $h, $args = array() ) { return ( array() === $args && $GLOBALS['daily'] ) ? (object) array( 'timestamp' => $GLOBALS['daily'], 'schedule' => 'daily' ) : false; }
function wp_next_scheduled( $h, $args = array() ) { return array() === $args ? $GLOBALS['daily'] : false; }
function wp_clear_scheduled_hook( $h, $args = array() ) { $GLOBALS['ev'][] = array( 'clear', $h, $args ); if ( array() === $args ) { $GLOBALS['daily'] = false; } }
function wp_schedule_event( $t, $r, $h, $args = array() ) { $GLOBALS['ev'][] = array( 'event', $t, $r, $h ); $GLOBALS['daily'] = $t; }
function wp_schedule_single_event( $t, $h, $args = array() ) { $GLOBALS['ev'][] = array( 'single', $t, $h, $args ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
function add_option( $k, $v = '', $dep = '', $autoload = null ) { if ( array_key_exists( $k, $GLOBALS['opt'] ) ) { return false; } $GLOBALS['opt'][ $k ] = $v; return true; }
function reset_cron( $daily, array $opt = array() ) { $GLOBALS['daily'] = $daily; $GLOBALS['ev'] = array(); $GLOBALS['opt'] = $opt; }
function events( $kind ) { return array_values( array_filter( $GLOBALS['ev'], static fn( $e ) => $kind === $e[0] ) ); }

echo "\nThe run time\n";
$at = defined( 'SN_SESSION_ROLLUP_AT' ) ? SN_SESSION_ROLLUP_AT : '';
ok( '06:15' === $at, 'the rollup runs at 06:15 UTC' );
preg_match( '/set_transient\(\s*SNT_ANALYTICS_VDAY_CACHE_KEY,\s*\$out,\s*(\d+)\s*\)/', (string) file_get_contents( __DIR__ . '/../inc/analytics-human-rule.php' ), $ttl );
$ttl = (int) ( $ttl[1] ?? 0 );
ok( $ttl > 0, "the over-cap list's cache time is read from its source ({$ttl} s)" );
$secs = 1 === preg_match( '/^(\d\d):(\d\d)$/', $at, $hm ) ? (int) $hm[1] * 3600 + (int) $hm[2] * 60 : -1;
foreach ( array( '2026-01-15' => 'winter (EST)', '2026-07-15' => 'summer (EDT)' ) as $d => $label ) {
	$mid = ( new DateTimeImmutable( $d . ' 00:00:00', new DateTimeZone( SN_ANALYTICS_SPLIT_TZ ) ) )->getTimestamp();
	ok( $secs >= ( $mid - utc( $d . ' 00:00:00' ) ) + $ttl, "$label: after New York midnight (" . gmdate( 'H:i', $mid ) . " UTC) plus the list's cache" );
}
ok( utc( '2026-11-11 06:15:00' ) === call( 'sn_session_rollup_next_run', utc( '2026-11-11 00:10:00' ) ), 'before 06:15, the next run is today' );
ok( utc( '2026-11-12 06:15:00' ) === call( 'sn_session_rollup_next_run', utc( '2026-11-11 06:15:00' ) ), 'at 06:15 exactly, the next run is tomorrow (strictly after now)' );

echo "\nThe day a move would skip (old run 21:43 UTC writes yesterday)\n";
$old = utc( '2026-11-11 21:43:02' );
ok( '' === call( 'sn_session_rollup_skipped_day', $old, utc( '2026-11-11 00:10:00' ) ), '00:10: the first 06:15 run is today and writes Nov 10, nothing skipped' );
ok( '' === call( 'sn_session_rollup_skipped_day', $old, utc( '2026-11-11 06:00:00' ) ), '06:00: the same' );
ok( '2026-11-10' === call( 'sn_session_rollup_skipped_day', $old, utc( '2026-11-11 15:00:00' ) ), '15:00: the old run still owes Nov 10 and the first new run writes Nov 11, so Nov 10 is skipped' );
ok( '2026-11-10' === call( 'sn_session_rollup_skipped_day', $old, utc( '2026-11-11 21:50:00' ) ), '21:50 with the old run overdue: Nov 10 is skipped' );
ok( '' === call( 'sn_session_rollup_skipped_day', utc( '2026-11-12 21:43:02' ), utc( '2026-11-11 22:00:00' ) ), '22:00 after the old run fired: Nov 10 is written, nothing skipped' );

echo "\nScheduling\n";
$now = utc( '2026-11-11 15:00:00' );
reset_cron( false );
sn_session_rollup_schedule( $now );
ok( array( array( 'event', utc( '2026-11-12 06:15:00' ), 'daily', SN_SESSION_ROLLUP_HOOK ) ) === $GLOBALS['ev'] && array() === $GLOBALS['opt'], 'unscheduled: one daily event at 06:15 UTC, no backfill' );
$GLOBALS['ev'] = array();
sn_session_rollup_schedule( $now );
ok( array() === $GLOBALS['ev'], 'already at 06:15: left alone' );
reset_cron( $old );
sn_session_rollup_schedule( $now );
ok( array( 'clear', SN_SESSION_ROLLUP_HOOK, array() ) === ( $GLOBALS['ev'][0] ?? null ) && 1 === count( events( 'clear' ) ), 'a 21:43 run is cleared: this hook, its daily only' );
ok( array( array( 'single', $now + 60, SN_SESSION_ROLLUP_HOOK, array( '2026-11-10' ) ) ) === events( 'single' ), 'the skipped day is queued once, a minute out, with the day as its argument' );
ok( array( array( 'event', utc( '2026-11-12 06:15:00' ), 'daily', SN_SESSION_ROLLUP_HOOK ) ) === events( 'event' ), 'and the daily is set at the next 06:15 UTC' );
ok( '2026-11-10' === ( $GLOBALS['opt'][ MOVED ]['backfill'] ?? null ) && $now === ( $GLOBALS['opt'][ MOVED ]['at'] ?? null ), 'the option records the move and the day it queued' );
$GLOBALS['ev'] = array();
sn_session_rollup_schedule( $now + 60 );
ok( array() === $GLOBALS['ev'], 'the next request changes nothing (idempotent)' );
reset_cron( $old, array( MOVED => array( 'at' => 1, 'backfill' => '2026-11-03' ) ) );
sn_session_rollup_schedule( $now );
ok( array() === events( 'single' ) && 1 === count( events( 'event' ) ) && '2026-11-03' === $GLOBALS['opt'][ MOVED ]['backfill'], 'moved again later: the time is fixed, but no second backfill (the option guards it)' );
reset_cron( utc( '2026-11-12 21:43:02' ) );
sn_session_rollup_schedule( utc( '2026-11-11 22:00:00' ) );
ok( array() === events( 'single' ) && 1 === count( events( 'event' ) ) && '' === ( $GLOBALS['opt'][ MOVED ]['backfill'] ?? null ), 'moved after the old run fired: no backfill, and the option says none was needed' );

echo "\nThe backfill is one ordinary run for its day\n";
$on = static fn( $cb ) => array_values( array_filter( $GLOBALS['hooks'], static fn( $h ) => SN_SESSION_ROLLUP_HOOK === $h[0] && $cb === $h[1] ) );
$v  = $on( 'sn_analytics_v2_verify' );
$r  = $on( 'sn_session_rollup_run' );
ok( 1 === count( $v ) && 5 === $v[0][2] && 0 === $v[0][3], 'the verdict check rides the hook first and takes no argument' );
ok( 1 === count( $r ) && 10 === $r[0][2] && 1 === $r[0][3], "the rollup takes the event's one argument: the backfill's day" );

class Stub_wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $q = array();
	public function prepare( $s, ...$a ) {
		$a = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $a ) { $x = $a[ $i++ ] ?? ''; return '%d' === $m[0] ? (string) (int) $x : "'" . $x . "'"; }, $s );
	}
	public function query( $s ) { $this->q[] = $s; return 1; }
}
$GLOBALS['wpdb'] = new Stub_wpdb();
function sn_analytics_config() { return array( 'account_id' => 'a' ); }
function sn_analytics_overcap_vdays() { return array( 'hashes' => array(), 'ok' => true, 'truncated' => false ); }
function sn_analytics_fetch_session_events( $from, $to, $class ) { $GLOBALS['read'][] = $from; $GLOBALS['read'][] = $to; return array( 'configured' => true, 'summaries' => array( array( 'pageviews' => 2 ) ), 'capped' => false ); }
function sn_session_metrics( array $v ) { return array( 'visits' => count( $v ), 'bounce_rate' => 0.0, 'pages_per_visit' => 2.0, 'median_duration' => 30 ); }
$GLOBALS['read'] = array();
sn_session_rollup_run( '2026-11-10' );
$first = end( $GLOBALS['wpdb']->q );
preg_match_all( "/'(\d{4}-\d{2}-\d{2})'/", (string) $first, $days );
ok( array( '2026-11-10' ) === array_values( array_unique( $GLOBALS['read'] ) ) && array( '2026-11-10' ) === array_values( array_unique( $days[1] ) ), 'a run for Nov 10 reads and writes Nov 10 and no other day' );
ok( false !== strpos( (string) $first, 'ON DUPLICATE KEY UPDATE visits=VALUES(visits)' ), 'its write replaces the stored day, never adds to it' );
sn_session_rollup_run( '2026-11-10' );
ok( end( $GLOBALS['wpdb']->q ) === $first, 'run twice, it writes the same rows: safe to run again' );
$GLOBALS['read'] = array();
sn_session_rollup_run();
ok( array( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ) === array_values( array_unique( $GLOBALS['read'] ) ), 'the daily run, with no day, still writes yesterday' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
