<?php
/**
 * Guard: the desktop widgets stay current (2026-10-10). The pulse's stamps
 * move when what a widget shows changed and only then; both routes are
 * owner-only; the client poller (tests/js/pulse.cjs) fires per stamp.
 *
 * Run: php tests/desktop-live.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '23.5.0' );
define( 'ARRAY_A', 'ARRAY_A' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
$GLOBALS['__routes'] = array(); $GLOBALS['__opts'] = array(); $GLOBALS['__can'] = true;
function add_action( $t, $cb ) { $GLOBALS['__acts'][ $t ][] = $cb; }
function register_rest_route( $ns, $r, $a ) { $GLOBALS['__routes'][ $r ] = $a; }
function current_user_can( $c ) { return 'manage_options' === $c && $GLOBALS['__can']; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_template() { return 'sn'; }
class SNT_Theme { public function get( $k ) { return $GLOBALS['__theme_v'] ?? '15.8.1'; } }
function wp_get_theme( $t ) { return new SNT_Theme(); }
class SNT_DB { public $posts = 'wp_posts'; public $rows = array(); public $sql = ''; public function get_results( $sql, $o ) { $this->sql = $sql; return $this->rows; } }
$GLOBALS['wpdb'] = new SNT_DB();
$GLOBALS['wp_version'] = '7.1.3';
require __DIR__ . '/../inc/desktop-mode-live.php';
foreach ( $GLOBALS['__acts']['rest_api_init'] ?? array() as $cb ) { $cb(); }

echo "\nRoutes: owner only, never cached\n";
foreach ( array( '/desktop/pulse', '/desktop/systems' ) as $r ) {
	$route = $GLOBALS['__routes'][ $r ] ?? array();
	ok( 'GET' === ( $route['methods'] ?? '' ) && true === ( $route['permission_callback'] )(), "$r is a GET the owner can read" );
	$GLOBALS['__can'] = false;
	ok( false === ( $route['permission_callback'] )(), "$r refuses anyone without manage_options" );
	$GLOBALS['__can'] = true;
}
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-live.php' ), "'Cache-Control', 'private, no-store'" ), 'both answer private, no-store' );

echo "\nStamps move with what the widgets show, and only then\n";
$wpdb = $GLOBALS['wpdb'];
$wpdb->rows = array( array( 'post_status' => 'publish', 'n' => 51, 'm' => '2026-10-10 14:00:00' ), array( 'post_status' => 'future', 'n' => 25, 'm' => '2026-10-10 13:00:00' ) );
$a = snt_desktop_pulse();
ok( false !== strpos( $wpdb->sql, "post_type IN ('post','page') GROUP BY post_status" ), 'one grouped read over posts and pages' );
ok( $a['content'] === snt_desktop_pulse()['content'] && $a['deploy'] === snt_desktop_pulse()['deploy'], 'nothing changed: the same stamps' );
$wpdb->rows[1]['n'] = 26;
$b = snt_desktop_pulse();
ok( $b['content'] !== $a['content'] && $b['deploy'] === $a['deploy'], 'a post scheduled moves the content stamp alone' );
$wpdb->rows[0]['m'] = '2026-10-10 14:05:00';
ok( snt_desktop_pulse()['content'] !== $b['content'], 'an edit to a published post moves it too' );
$GLOBALS['__opts']['snt_deploy_workers_seen'] = array( 'mcp' => array( 'v' => '2.1.0' ) );
$c = snt_desktop_pulse();
ok( $c['deploy'] !== $b['deploy'], 'a worker deploy recorded moves the deploy stamp' );
$GLOBALS['__theme_v'] = '15.8.2';
ok( snt_desktop_pulse()['deploy'] !== $c['deploy'], 'and so does a theme update' );
ok( 1 === preg_match( '/^[0-9a-f]{32}$/', $c['content'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $c['deploy'] ), 'stamps are opaque hashes: no counts or versions leave the route' );

echo "\nSN Systems re-reads its page-load lines\n";
function snt_health_summary_for_localize() { return array( 'passed' => 17 ); }
function snt_cron_summary_for_localize() { return array( 'total' => 85 ); }
function snt_desktop_status_extra() { return array( 'systems' => array(), 'provenance' => array() ); }
ok( array( 'healthSummary', 'cronSummary', 'statusExtra' ) === array_keys( snt_desktop_systems_lines() ) && 17 === snt_desktop_systems_lines()['healthSummary']['passed'], 'the three lines, from the same functions the page-load localize calls' );

echo "\nThe client poller\n";
$out = shell_exec( 'node ' . escapeshellarg( __DIR__ . '/js/pulse.cjs' ) . ' 2>&1' );
echo (string) $out;
ok( false !== strpos( (string) $out, 'PASS: pulse' ), 'tests/js/pulse.cjs passes (node is required; never silently skipped)' );
$assets = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-assets.php' );
foreach ( array( 'queue' => 'content', 'anchors' => 'content', '' => 'deploy' ) as $f => $key ) {
	$name = 'desktop-mode-widget' . ( $f ? "-$f" : '' ) . '.js';
	ok( false !== strpos( (string) file_get_contents( __DIR__ . "/../assets/$name" ), "window.sntPulse.on( '$key'" ), "$name subscribes to the $key stamp" );
}
ok( 4 === substr_count( $assets, "'snt-pulse'" ), 'the pulse is registered once and declared by the three widgets that subscribe' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
