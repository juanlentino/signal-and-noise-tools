<?php
/**
 * Tests: the consolidated "Signal & Noise" dashboard widget on index.php.
 *
 * v11.30.0 folds FOUR boxes into one — Login defense, Analytics Overview,
 * Analytics Top content and S&N Health — the same move v8.3.0 made when it
 * folded S&N Uptime into S&N Health ("one 'is everything okay' surface instead
 * of a fifth dashboard box", owner call 2026-07-02). Removal guards below keep
 * them gone, exactly as tests/uptime-status-widget.php keeps that one gone.
 *
 * THE HARD CONSTRAINT IS ZERO COST. index.php renders on every admin login, so
 * this render performs no HTTP and no scan. The test counts both.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__actions'] = array(); $GLOBALS['__widgets'] = array();
$GLOBALS['__http_calls'] = 0; $GLOBALS['__scans'] = 0; $GLOBALS['__cap'] = 'manage_options';

function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function wp_add_dashboard_widget( $id, $title, $cb ) { $GLOBALS['__widgets'][ $id ] = array( $title, $cb ); }
function current_user_can( $c ) { return 'manage_options' === $GLOBALS['__cap'] ? true : ( 'view_stats' === $c ); }
function wp_remote_get( $u, $a = array() ) { $GLOBALS['__http_calls']++; return array(); }
function wp_remote_post( $u, $a = array() ) { $GLOBALS['__http_calls']++; return array(); }
function sn_health_run_scan() { $GLOBALS['__scans']++; return array(); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-verdict.php';
require __DIR__ . '/../inc/dash-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function fire() { $GLOBALS['__widgets'] = array(); foreach ( $GLOBALS['__actions']['wp_dashboard_setup'] ?? array() as $cb ) { $cb(); } }
echo "the consolidated dashboard widget\n\n";

fire();

// ── ONE BOX ─────────────────────────────────────────────────────────────────
ok( isset( $GLOBALS['__widgets']['sn_dashboard'] ), 'the consolidated widget registers' );
ok( 1 === count( $GLOBALS['__widgets'] ), 'AND IT IS THE ONLY ONE THIS MODULE ADDS' );

// ── REMOVAL GUARDS — the four folded boxes stay gone ────────────────────────
echo "\nRemoval guards (v11.30.0 consolidation)\n";
foreach ( array(
	'sn_login_defense'       => 'login-defense-widget',
	'sn_plausible_snapshot'  => 'analytics-widget',
	'sn_plausible_pages'     => 'analytics-widget',
	'sn_site_health'         => 'site-health-widget',
) as $id => $module ) {
	ok( ! isset( $GLOBALS['__widgets'][ $id ] ), "no $id widget registered any more" );
	$src = (string) file_get_contents( __DIR__ . '/../inc/' . $module . '.php' );
	ok( false === strpos( $src, "wp_add_dashboard_widget( '" . $id ),
		"inc/$module.php contains no registration call for $id" );
}

// ── ZERO COST. index.php renders on every admin login. ──────────────────────
echo "\nZero-cost render\n";
$GLOBALS['__http_calls'] = 0; $GLOBALS['__scans'] = 0;
ob_start(); call_user_func( $GLOBALS['__widgets']['sn_dashboard'][1] ); $html = ob_get_clean();
ok( 0 === $GLOBALS['__http_calls'], 'THE RENDER MAKES ZERO HTTP CALLS' );
ok( 0 === $GLOBALS['__scans'], 'AND NEVER TRIGGERS A SCAN — it reads the cached scan only' );
ok( '' !== trim( $html ), 'it renders something' );

// ── the verdict leads, and links through ────────────────────────────────────
echo "\nContent\n";
ok( false !== strpos( $html, 'sn-dw' ), 'it uses its own markup vocabulary' );
ok( false !== strpos( $html, 'holding' ) || false !== strpos( $html, 'attention' ) || false !== strpos( $html, 'reported' ),
	'THE VERDICT LEADS — the one question a widget on the home screen should answer' );
ok( false !== strpos( $html, 'sn-theme-options' ), 'and it links through to the full screen' );

// ── capability: a stats-only user must not read admin findings ──────────────
echo "\nCapability tiers\n";
$GLOBALS['__cap'] = 'view_stats';
fire();
ok( isset( $GLOBALS['__widgets']['sn_dashboard'] ), 'a view_stats user still gets the widget (the analytics boxes it replaced were view_stats-gated)' );
ob_start(); call_user_func( $GLOBALS['__widgets']['sn_dashboard'][1] ); $stats_html = ob_get_clean();
ok( false === strpos( $stats_html, 'sn-dw__exceptions' ),
	'BUT NOT THE EXCEPTION BAND — health findings and cron faults are manage_options business, and the folded Health widget was gated that way' );
$GLOBALS['__cap'] = 'manage_options';

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
