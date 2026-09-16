<?php
/**
 * The deactivation list names every hook the plugin schedules.
 *
 * WHY. `inc/cron-lifecycle.php` is a hand-kept list; the schedule sites are
 * spread across ~30 modules. A cron added without joining the list survives
 * deactivation and fires into a callback that no longer exists. This suite
 * derives the set of scheduled hooks from source (third argument of
 * wp_schedule_event, second of wp_schedule_single_event) and pins the list to
 * it both ways, then drives sn_cron_deactivate() against a recording stub.
 *
 * Run: php tests/cron-lifecycle.php
 *
 * @since 15.3.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$root = realpath( __DIR__ . '/..' );
$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

// --- 1. The census: every hook a schedule site names, from source. ---------
$name = '((?:SNT?_[A-Z0-9_]+)|\'[a-z_]+\')';
$arg  = '[^,]+';
$scheduled = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/inc" ) );
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	$src = file_get_contents( $file->getPathname() );
	// Strip comments so a docblock example is not a schedule site.
	$src = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $src );
	preg_match_all( "/wp_schedule_event\(\s*$arg,\s*$arg,\s*$name/", $src, $m );
	foreach ( $m[1] as $h ) { $scheduled[ $h ] = true; }
	preg_match_all( "/wp_schedule_single_event\(\s*$arg,\s*$name/", $src, $m );
	foreach ( $m[1] as $h ) { $scheduled[ $h ] = true; }
}
$scheduled = array_keys( $scheduled );
sort( $scheduled );
ok( count( $scheduled ) >= 30, 'census finds the schedule sites (' . count( $scheduled ) . ')' );

// --- 2. The list, as written. ----------------------------------------------
$module = file_get_contents( "$root/inc/cron-lifecycle.php" );
preg_match( '/function sn_cron_hooks\(\)\s*\{(.*?)\n\}/s', $module, $fn );
preg_match_all( "/^\s*$name,/m", $fn[1], $m );
$listed = $m[1];
sort( $listed );
ok( count( $listed ) === count( array_unique( $listed ) ), 'list has no duplicates' );
$missing = array_diff( $scheduled, $listed );
$extra   = array_diff( $listed, $scheduled );
ok( empty( $missing ), 'every scheduled hook is in the deactivation list' . ( $missing ? ': ' . implode( ', ', $missing ) : '' ) );
ok( empty( $extra ), 'the list names no hook nothing schedules' . ( $extra ? ': ' . implode( ', ', $extra ) : '' ) );

// --- 3. The main file registers it THE way: register_deactivation_hook( __FILE__, ... ). ---
$main = file_get_contents( "$root/signal-and-noise-tools.php" );
ok( (bool) preg_match( "/register_deactivation_hook\(\s*__FILE__,\s*'sn_cron_deactivate'\s*\)/", $main ), 'main file registers sn_cron_deactivate on deactivation' );
ok( false !== strpos( $main, "require_once SNT_PATH . 'inc/cron-lifecycle.php'" ), 'main file loads the module' );

// --- 4. Behaviour: every hook is unscheduled, once, by hook name. -----------
foreach ( $listed as $h ) {
	if ( "'" === $h[0] ) { continue; }
	if ( ! defined( $h ) ) { define( $h, 'hook_' . strtolower( $h ) ); }
}
$unscheduled = array();
function wp_unschedule_hook( $hook ) {
	global $unscheduled;
	$unscheduled[] = $hook;
	return 1;
}
require_once "$root/inc/cron-lifecycle.php";
$returned = sn_cron_deactivate();
ok( count( $returned ) === count( $listed ), 'deactivate returns one entry per listed hook' );
ok( $unscheduled === $returned, 'every hook was passed to wp_unschedule_hook, in order, once' );
ok( in_array( 'snt_deploy_workers_warm', $unscheduled, true ), 'literal-named hooks are unscheduled too' );
ok( in_array( constant( 'SN_CF_MONITOR_HOOK' ), $unscheduled, true ), 'constants resolve to their hook names' );

// --- 5. Negative control: a schedule site the list does not know goes red. --
$probe = $scheduled;
$probe[] = 'SN_NOT_IN_THE_LIST_HOOK';
ok( ! empty( array_diff( $probe, $listed ) ), 'negative control: an unlisted hook is a diff' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
