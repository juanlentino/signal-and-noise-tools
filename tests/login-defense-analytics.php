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
function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
function add_query_arg( $a, $u = '' ) { return '?' . http_build_query( (array) $a ); }
function remove_query_arg( $a, $u = '' ) { return '/base'; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// Edge glance seams (the glance below the worker decisions reads these).
$GLOBALS['__edge_cfg'] = null;
function sn_edge_config() { return $GLOBALS['__edge_cfg']; }
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) {
	$map = array(
		'atk_door'    => array( array( 'value' => '/wp-login.php', 'requests' => 8400, 'bytes' => 0 ) ),
		'atk_country' => array( array( 'value' => 'CN', 'requests' => 5000, 'bytes' => 0 ) ),
		'atk_asn'     => array( array( 'value' => 'DIGITALOCEAN-ASN', 'requests' => 3000, 'bytes' => 0 ) ),
	);
	return $map[ $dim ] ?? array();
}

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
ok( strpos( $h, 'sn-kpi-delta' ) !== false, 'KPI cards include the delta slot (parity with the shared cards)' );

// --- B1: trend ---------------------------------------------------------------
ob_start();
sn_login_defense_render_trend_chart( array( array( 'day' => '2026-06-20', 'views' => 5 ), array( 'day' => '2026-06-21', 'views' => 9 ) ) );
$tr = ob_get_clean();
ok( strpos( $tr, '<svg' ) !== false, 'trend renders an SVG sparkline' );
ok( strpos( $tr, 'snSparkFill' ) !== false && strpos( $tr, 'fill="url(#snSparkFill)"' ) !== false, 'trend has the gradient area fill (parity with the shared trend)' );
ob_start();
sn_login_defense_render_trend_chart( array() );
ok( ob_get_clean() === '', 'trend with no data renders nothing' );

// --- B2: top-N table ---------------------------------------------------------
ob_start();
sn_login_defense_render_top_table( 'Top networks', 'Network', array( array( 'k' => 'BadNet', 'v' => 9 ) ) );
$tb = ob_get_clean();
ok( strpos( $tb, 'BadNet' ) !== false && strpos( $tb, 'Top networks' ) !== false && strpos( $tb, '<table' ) !== false,
	'top table renders rows + caption' );
ok( strpos( $tb, 'postbox' ) !== false && strpos( $tb, 'wp-list-table' ) !== false && strpos( $tb, 'hndle' ) !== false,
	'top table uses the shared postbox + wp-list-table chrome' );
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start();
sn_login_defense_render_top_table( 'Top networks', 'Network', array() );
$out_empty = ob_get_clean();
ok( '' === trim( $out_empty ), 'empty top table → no panel emitted (omit + fold, v8.5.2)' );
ok( in_array( 'Top networks', (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() ), true ), 'empty top table → title noted for the fold' );

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
ok( strpos( $v, 'postbox sn-overview' ) !== false, 'view wraps KPIs + trend in the shared Overview postbox' );

// --- B4: CF edge door-knock glance ------------------------------------------
$GLOBALS['__cfg']      = array( 'account_id' => 'x', 'token' => 'y' ); // AE configured (view precondition)
$GLOBALS['__edge_cfg'] = null; // edge NOT configured → glance dormant
ob_start();
sn_login_defense_view_render();
$ng = ob_get_clean();
ok( strpos( $ng, 'Door-knock pressure' ) === false, 'glance: dormant when sn_edge_config() returns null (no glance, no fatal)' );

$GLOBALS['__edge_cfg'] = array( 'token' => 't', 'zone' => 'z' ); // edge configured → glance renders
ob_start();
sn_login_defense_view_render();
$g = ob_get_clean();
ok( strpos( $g, 'Door-knock pressure' ) !== false, 'glance: renders when edge configured' );
ok( strpos( $g, '8,400' ) !== false || strpos( $g, '8400' ) !== false, 'glance: total door-knock pressure (8400)' );
ok( strpos( $g, 'CN' ) !== false && strpos( $g, 'DIGITALOCEAN-ASN' ) !== false, 'glance: top country + network' );
ok( strpos( $g, 'page=sn-analytics&sn_view=edge' ) !== false, 'glance: links to the Traffic & edge breakdown' );

// --- Frame parity: header / body split --------------------------------------
$GLOBALS['__cfg']      = array( 'account_id' => 'x', 'token' => 'y' );
$GLOBALS['__edge_cfg'] = null; // glance dormant — body emits tables only
$GLOBALS['__q']        = array( array( 'decision' => 'block', 'hits' => 3 ), array( 'decision' => 'pass', 'hits' => 7 ) );

ob_start();
sn_login_defense_render_header();
$hd = ob_get_clean();
ok( strpos( $hd, 'button-group' ) !== false && strpos( $hd, 'button button-small' ) !== false && strpos( $hd, 'button-small active' ) !== false,
	'header: range control uses the shared pill markup (button-group + button-small + active)' );
ok( strpos( $hd, 'postbox sn-overview' ) !== false && strpos( $hd, 'sn-an-breakdown' ) !== false,
	'header: renders the Overview postbox + breakdown pills' );
ok( strpos( $hd, 'Top attacker networks' ) === false,
	'header: does NOT render the attacker tables (body-only)' );

ob_start();
sn_login_defense_render_body();
$bd = ob_get_clean();
ok( strpos( $bd, 'Top attacker networks' ) !== false && strpos( $bd, 'Top attacker countries' ) !== false,
	'body: renders the attacker tables' );
ok( strpos( $bd, 'postbox sn-overview' ) === false,
	'body: does NOT render the Overview postbox (header-only)' );

// Wrapper dormant: exactly ONE "Connect" notice (no double-emit from header+body).
$GLOBALS['__cfg'] = null;
ob_start();
sn_login_defense_view_render();
$dz = ob_get_clean();
ok( substr_count( $dz, 'sn-an-empty' ) === 1,
	'wrapper dormant: the Connect-CF notice is emitted exactly once (header only; body silent)' );
ok( strpos( $dz, 'Connect Cloudflare Analytics' ) !== false,
	'wrapper dormant: still shows the Connect-CF notice' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
