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
// D5 §5: the KPI loop routes through the shared snt_an_kpi_row() primitive —
// byte-identical markup (label/value classes were already this shape), so this
// just pins that the primitive's own class vocabulary is what's on the page.
ok( strpos( $h, 'sn-kpi-label' ) !== false && strpos( $h, 'sn-kpi-value' ) !== false,
	'KPI cards route through the shared snt_an_kpi_row() primitive (label/value classes)' );

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
// D5 §4: routes through the shared snt_an_kv_table primitive, picking up the
// standardized panel-primitive marker class it never had before.
ok( strpos( $tb, 'class="postbox sn-an-postbox"' ) !== false,
	'top table adopts the shared kv-table primitive (sn-an-postbox chrome)' );
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start();
sn_login_defense_render_top_table( 'Top networks', 'Network', array() );
$out_empty = ob_get_clean();
ok( '' === trim( $out_empty ), 'empty top table → no panel emitted (omit + fold, v8.5.2)' );
// v9.40.0 D4: the collector now stores { title, why } shape (fold contract in
// tests/analytics-primitives.php) instead of a plain title string. login-defense
// itself is out of scope for D4 §4 (named exception, deferred to D5) — this call
// still passes only a title, so why defaults to ''.
$ld_noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $ld_noted ) && 'Top networks' === $ld_noted[0]['title'], 'empty top table → title noted for the fold' );

// --- B3: view dormant gate ---------------------------------------------------
$GLOBALS['__cfg'] = null;
ob_start();
sn_login_defense_view_render();
$dormant = ob_get_clean();
ok( strpos( $dormant, 'Connect Cloudflare Analytics' ) !== false, 'view dormant-gates when CF not configured' );
// D5 §5: the config gate routes through the shared snt_an_gate() primitive. The
// old gate was titleless (bare postbox, no header) — it now carries the view's
// natural title, matching every other view's gate (Analytics, Visits, Traffic & edge).
ok( strpos( $dormant, 'sn-an-gate' ) !== false, 'dormant gate routes through the shared snt_an_gate() primitive' );
ok( strpos( $dormant, '<h2 class="hndle"><span>Login defense</span></h2>' ) !== false,
	'dormant gate carries the view\'s natural title "Login defense" (was titleless)' );
ok( strpos( $dormant, 'Connect Cloudflare Analytics (Account ID + token) in the Analytics tab to see login-defense analytics.' ) !== false,
	'dormant gate copy is byte-preserved' );
ok( substr_count( $dormant, '<div' ) === substr_count( $dormant, '</div>' ), 'dormant view output is div-balanced' );

// --- B3: view configured (smoke) ---------------------------------------------
$GLOBALS['__cfg'] = array( 'account_id' => 'x', 'token' => 'y' );
$GLOBALS['__q']   = array( array( 'decision' => 'block', 'hits' => 3 ), array( 'decision' => 'pass', 'hits' => 7 ) );
ob_start();
sn_login_defense_view_render();
$v = ob_get_clean();
ok( strpos( $v, 'sn-kpi' ) !== false && strpos( $v, 'Top attacker networks' ) !== false,
	'view configured renders KPIs + threat tables (no fatal)' );
ok( strpos( $v, 'postbox sn-overview' ) !== false, 'view wraps KPIs + trend in the shared Overview postbox' );
// D5 §5: the Overview postbox routes through snt_an_panel_open() — picks up the
// sn-an-postbox chrome marker (the ONE deliberate visual change this task makes).
ok( strpos( $v, 'postbox sn-an-postbox sn-overview' ) !== false,
	'Overview postbox adopts the shared panel primitive (sn-an-postbox chrome)' );
ok( substr_count( $v, '<div' ) === substr_count( $v, '</div>' ), 'configured view output is div-balanced' );

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
// D5 §5: the door-knock postbox routes through snt_an_panel_open() — exact
// byte-preserved header text, now wrapped in the shared sn-an-postbox chrome.
ok( strpos( $g, '<div class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Door-knock pressure (CF edge)</span></h2></div><div class="inside">' ) !== false,
	'door-knock postbox adopts the shared panel primitive (sn-an-postbox)' );
// Overview + 2 top tables (D5 §4, already shipped) + door-knock = 4 sn-an-postbox panels.
ok( substr_count( $g, 'class="postbox sn-an-postbox' ) >= 4,
	'Overview + top tables + door-knock all carry the shared sn-an-postbox marker' );
ok( substr_count( $g, '<div' ) === substr_count( $g, '</div>' ), 'fully-configured view output is div-balanced' );

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
// D5 §5: the gate is now snt_an_gate(), whose own <p> carries TWO classes that
// both contain the substring 'sn-an-empty' ("sn-an-empty sn-an-empty--panel") —
// so that substring is no longer a reliable single-render marker. 'sn-an-gate'
// (the gate's outer postbox class) IS single-occurrence per render and carries
// the same intent: exactly one gate, not a double-emit from header+body.
$GLOBALS['__cfg'] = null;
ob_start();
sn_login_defense_view_render();
$dz = ob_get_clean();
ok( substr_count( $dz, 'sn-an-gate' ) === 1,
	'wrapper dormant: the Connect-CF gate is emitted exactly once (header only; body silent)' );
ok( strpos( $dz, 'Connect Cloudflare Analytics' ) !== false,
	'wrapper dormant: still shows the Connect-CF notice' );
// D5 §9 (T5 review carry-note): restores the original notice-COUNT intent
// alongside the wrapper-class pin above — the gate class proves "one gate
// postbox", this proves "one copy of the notice text" (no double-emit hiding
// behind e.g. a second gate variant that reuses the same class).
ok( substr_count( $dz, 'Connect Cloudflare Analytics' ) === 1,
	'wrapper dormant: the Connect-CF notice text appears exactly once' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
