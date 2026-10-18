<?php
/**
 * Standalone test: the audit log's lockout facts come from the login guard.
 *
 * Limit Login Attempts Reloaded is gone (2026-10-18); the edge worker's own
 * decisions ('lockout', 'throttle', 'account_throttle') replace what the audit
 * log used to poll out of its options. This suite goes red if any read of that
 * plugin's options or screen comes back, if the retired counter starts being
 * written again, or if a failed guard read turns into zeros.
 *
 * Run: php tests/audit-log-guard.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$GLOBALS['__options'] = array();
$GLOBALS['__reads']   = array();
function get_option( $k, $d = false ) { $GLOBALS['__reads'][] = $k; return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
function sn_setting( $k, $d = null ) { return $d; }
function add_action() {}
function wp_next_scheduled() { return true; }

$root = dirname( __DIR__ );
require $root . '/inc/audit-log.php';

echo "\nGroup: no read of the retired plugin survives\n";
ok( ! file_exists( $root . '/inc/audit-log-lla.php' ), 'inc/audit-log-lla.php is deleted' );
$hits = array();
foreach ( array( 'inc', 'apps', 'assets' ) as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! preg_match( '/\.(php|js)$/', $file->getFilename() ) ) {
			continue;
		}
		$src = (string) file_get_contents( $file->getPathname() );
		if ( preg_match( '/limit_login_|limit-login-attempts|Limit Login Attempts|\blla\b|lla_delta|_lla_/i', $src ) ) {
			$hits[] = substr( $file->getPathname(), strlen( $root ) + 1 );
		}
	}
}
ok( array() === $hits, 'no file under inc/, apps/ or assets/ reads or names Limit Login Attempts: ' . implode( ', ', $hits ) );

echo "\nGroup: the retired lockout counter is history, not a live column\n";
ok( ! in_array( 'lockout_triggered', SN_AUDIT_COUNTER_TYPES, true ), 'lockout_triggered is no longer a counter type' );
snt_audit_increment_counter_impl( 'lockout_triggered' );
$blob = snt_audit_get_blob();
ok( array() === $blob['counters'], 'incrementing lockout_triggered writes nothing' );
$old = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
$GLOBALS['__options'][ SN_AUDIT_OPTION ] = array( 'schema_version' => 1, 'created_at' => time(), 'counters' => array( $old => array( 'login_failed' => 1, 'lockout_triggered' => 4 ) ), 'login_success' => array() );
$rows = snt_audit_get_counters_impl( 7 );
ok( 4 === $rows[2]['lockout_triggered'], 'an old day still reads its historical lockout_triggered count' );
$GLOBALS['__reads'] = array();
$stats = snt_audit_prune_impl();
ok( array( 'counter_buckets_dropped', 'login_rows_dropped', 'change_rows_dropped' ) === array_keys( $stats ), 'prune reports only what it deleted: ' . implode( ',', array_keys( $stats ) ) );
ok( array() === preg_grep( '/^limit_login|lla/', $GLOBALS['__reads'] ), 'prune reads no option of the retired plugin: ' . implode( ',', $GLOBALS['__reads'] ) );

echo "\nGroup: the summary carries the guard's 7-day decisions, unavailable never as zeros\n";
$g = snt_audit_get_summary_impl()['guard'];
ok( 'unconfigured' === $g['state'], 'without the login-defense module the guard reads as not configured' );
if ( true ) { // conditional, so it is not hoisted above the first assertion
	function sn_login_defense_headline() { return $GLOBALS['__lg']; }
}
$GLOBALS['__lg'] = array( 'configured' => false );
ok( 'unconfigured' === snt_audit_get_summary_impl()['guard']['state'], 'an unconfigured headline reads as not configured' );
$GLOBALS['__lg'] = array( 'configured' => true, 'unavailable' => true, 'checked' => 0, 'locked_out' => 0 );
ok( 'unavailable' === snt_audit_get_summary_impl()['guard']['state'], 'a failed guard read is unavailable, not zeros' );
$GLOBALS['__lg'] = array( 'configured' => true, 'checked' => 90, 'locked_out' => 3, 'throttled' => 11, 'account_throttled' => 2 );
$g = snt_audit_get_summary_impl()['guard'];
ok( 'ok' === $g['state'] && 3 === $g['locked_out'] && 11 === $g['throttled'] && 2 === $g['account_throttled'], 'a good read carries lockouts 3, throttled 11, account throttled 2' );
ok( ! array_key_exists( 'lla', snt_audit_get_summary_impl() ), 'the summary has no lla key' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
