<?php
/**
 * CLI fixture for the login-defense Analytics-dashboard view + renderers.
 * Standalone, no WP bootstrap, global-stub style (mirrors tests/login-defense.php).
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
function add_query_arg( $a, $u = '' ) { return '?' . http_build_query( (array) $a ); }
function remove_query_arg( $a, $u = '' ) { return '/base'; }

$GLOBALS['__cfg'] = null;
$GLOBALS['__q']   = array();
function sn_analytics_config() { return $GLOBALS['__cfg']; }
function sn_analytics_query( $sql ) { return $GLOBALS['__q']; }

require __DIR__ . '/../inc/login-defense.php';
require __DIR__ . '/../inc/login-defense-analytics.php';

// --- B1: KPI cards -----------------------------------------------------------
ob_start();
sn_login_defense_render_kpi_cards( array( 'checked' => 100, 'blocked' => 30, 'block_rate' => 30, 'networks' => 4 ) );
$h = ob_get_clean();
ok( strpos( $h, 'sn-kpi' ) !== false && strpos( $h, '30%' ) !== false && strpos( $h, 'Block rate' ) !== false,
	'KPI cards render login labels + values' );

// --- B1: trend ---------------------------------------------------------------
ob_start();
sn_login_defense_render_trend_chart( array( array( 'day' => '2026-06-20', 'views' => 5 ), array( 'day' => '2026-06-21', 'views' => 9 ) ) );
ok( strpos( ob_get_clean(), '<svg' ) !== false, 'trend renders an SVG sparkline' );
ob_start();
sn_login_defense_render_trend_chart( array() );
ok( ob_get_clean() === '', 'trend with no data renders nothing' );

// --- B2: top-N table ---------------------------------------------------------
ob_start();
sn_login_defense_render_top_table( 'Top networks', 'Network', array( array( 'k' => 'BadNet', 'v' => 9 ) ) );
$tb = ob_get_clean();
ok( strpos( $tb, 'BadNet' ) !== false && strpos( $tb, 'Top networks' ) !== false && strpos( $tb, '<table' ) !== false,
	'top table renders rows + caption' );
ob_start();
sn_login_defense_render_top_table( 'Top networks', 'Network', array() );
ok( strpos( ob_get_clean(), 'No' ) !== false, 'top table empty-state' );

// --- B3: view dormant gate ---------------------------------------------------
$GLOBALS['__cfg'] = null;
ob_start();
sn_login_defense_view_render();
ok( strpos( ob_get_clean(), 'Connect Cloudflare Analytics' ) !== false, 'view dormant-gates when CF not configured' );

// --- B3: view configured (smoke) ---------------------------------------------
$GLOBALS['__cfg'] = array( 'account_id' => 'x', 'token' => 'y' );
$GLOBALS['__q']   = array( array( 'decision' => 'block', 'hits' => 3 ), array( 'decision' => 'pass', 'hits' => 7 ) );
ob_start();
sn_login_defense_view_render();
$v = ob_get_clean();
ok( strpos( $v, 'sn-kpi' ) !== false && strpos( $v, 'Top attacker networks' ) !== false,
	'view configured renders KPIs + threat tables (no fatal)' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
