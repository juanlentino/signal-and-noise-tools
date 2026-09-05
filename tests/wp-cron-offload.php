<?php
/**
 * Tests: WP-Cron is kept out of the request path (issue #1032).
 *
 * The measurement behind it: `/wp-cron.php` ran 62 times in 24h averaging
 * 10.6s and peaking at 51.7s, on a 2 GB / 2 vCPU box at ~90% memory. With
 * `DISABLE_WP_CRON` unset, that cost lands on a visitor's pageview, and the
 * requests Varnish cannot place come back as 503 — ten in the same window.
 *
 * Most of these assertions are about the ways this must NOT fire, because the
 * failure mode is silent and total: define the constant with nothing else
 * driving cron and every scheduled job on the site stops, with no error.
 *
 * Run: php tests/wp-cron-offload.php
 * @since 13.97.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

$GLOBALS['snt_filter_value'] = true;
$GLOBALS['snt_filters_seen'] = array();
function apply_filters( $hook, $value ) {
	$GLOBALS['snt_filters_seen'][] = $hook;
	return 'snt_offload_wp_cron' === $hook ? $GLOBALS['snt_filter_value'] : $value;
}

// Require the DECIDER without letting the file's own define() run, so each
// case below can be driven independently. The guard clause at the bottom of
// the file is asserted separately from source.
$src = (string) file_get_contents( __DIR__ . '/../inc/wp-cron-offload.php' );
// The file keeps every definition above a single marker comment and does the
// one executable thing below it. Strip from the marker so this harness gets
// the accessors without the define() - which, once run, would make every
// later case read as 'already defined'.
$cut = strpos( $src, 'The only place anything actually happens' );
if ( false === $cut ) { echo "FAIL: marker comment missing from inc/wp-cron-offload.php\n"; exit( 1 ); }
eval( '?>' . substr( $src, 0, $cut ) );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "wp-cron-offload — plugin v13.97.2\n\nGroup 1: it fires by default\n";
$GLOBALS['snt_filter_value'] = true;
ok( true === snt_should_offload_wp_cron(), 'with nothing overriding it, cron is taken out of the request path' );
ok( in_array( 'snt_offload_wp_cron', $GLOBALS['snt_filters_seen'], true ), 'and the decision runs through a filter, so it can be turned off without editing code' );

echo "\nGroup 2: the ways it must NOT fire\n";
$GLOBALS['snt_filter_value'] = false;
ok( false === snt_should_offload_wp_cron(), 'a filter returning false leaves WordPress default behaviour intact' );

$GLOBALS['snt_filter_value'] = true;
define( 'WP_CLI', true );
ok( false === snt_should_offload_wp_cron(), 'under WP-CLI it does not fire — cron is driven directly there, and the constant would only confuse `wp cron event run`' );

echo "\nGroup 3: an existing decision is never overridden\n";
// Assert from source rather than by defining the constant, which cannot be
// undone inside one process.
ok( 1 === preg_match( "/if \( defined\( 'DISABLE_WP_CRON' \) \) \{\s*return false;/", $src ),
	'a DISABLE_WP_CRON already set in wp-config is returned to unchanged, in EITHER direction' );

echo "\nGroup 4: it is loaded early enough to matter\n";
// spawn_cron() reads the constant during `init`. Anything required after the
// hook has run is too late, and would fail silently — the file would load, the
// constant would be set, and cron would already have spawned.
$boot = (string) file_get_contents( dirname( __DIR__ ) . '/signal-and-noise-tools.php' );
ok( false !== strpos( $boot, "inc/wp-cron-offload.php" ), 'the bootstrap requires it' );
$pos_offload = strpos( $boot, "inc/wp-cron-offload.php" );
$pos_addacts = strpos( $boot, "add_action( 'init'" );
ok( false === $pos_addacts || $pos_offload < $pos_addacts, 'and requires it before any init hook is registered in the bootstrap' );

echo "\nGroup 5: the safety net it leans on still exists\n";
// If the external cron ever disappears, nothing runs and nothing complains —
// unless the overdue detector is there. This change is only defensible while
// that is true, so the dependency is pinned rather than assumed.
$cron_dash = (string) file_get_contents( dirname( __DIR__ ) . '/inc/cron-dashboard.php' );
ok( false !== strpos( $cron_dash, "'overdue'" ), 'cron health still models overdue hooks — the detector for "external cron went away"' );
// This assertion used to say cron_disabled_constant "reports whether the
// constant is set". It does not, and writing that down is how I came to read
// it that way an hour later: it is a PROBLEM FLAG (constant set AND nothing
// fired recently AND no system cron declared), so it reads false both when the
// constant is absent and when it is set and everything works. The state now
// has its own field; both are pinned, and the distinction is pinned with them.
ok( false !== strpos( $cron_dash, 'cron_disabled_constant' ), 'the problem flag is still reported' );
ok( false !== strpos( $cron_dash, 'wp_cron_offload' ), 'and the constant\'s ACTUAL fate is reported separately, so the two cannot be confused' );
ok( false !== strpos( $cron_dash, 'PROBLEM FLAG' ), 'and the misleading field carries a comment saying what it really means' );

echo "\nGroup 6: the five states are distinguishable\n";
ok( function_exists( 'snt_wp_cron_offload_state' ), 'the state accessor exists' );
ok( function_exists( 'snt_wp_cron_still_in_request_path' ), 'and a predicate answers the question that actually matters' );
$src_off = (string) file_get_contents( dirname( __DIR__ ) . '/inc/wp-cron-offload.php' );
foreach ( array( 'offloaded', 'already_true', 'already_false', 'declined_filter', 'declined_cli' ) as $state ) {
	ok( false !== strpos( $src_off, "'" . $state . "'" ), "state '$state' is recorded" );
}
ok( 1 === preg_match( "/already_false.*declined_filter|declined_filter.*already_false/s", $src_off ),
	'THE PIN: already_false and declined_filter are the two that still leave cron in the request path, and both are named together' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
