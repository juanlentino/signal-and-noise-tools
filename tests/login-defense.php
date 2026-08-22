<?php
/**
 * CLI fixture for the Login defense panel query builders + helpers.
 * Mirrors tests/ssrf-guard.php: standalone, no WP bootstrap, global-stub style.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $cond, $msg ) {
	global $fails, $passes;
	if ( $cond ) { echo "PASS: $msg\n"; $passes++; } else { echo "FAIL: $msg\n"; $fails++; }
}

// Minimal WP stubs the helpers touch (defined unconditionally; only used in CLI).
$GLOBALS['__home'] = 'https://juanlentino.com';
function home_url( $p = '' ) { return rtrim( $GLOBALS['__home'], '/' ) . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require __DIR__ . '/../inc/login-defense.php';

// --- AE query builders -------------------------------------------------------
$sql = sn_login_defense_decisions_sql( 7 );
ok( strpos( $sql, 'sn_login_guard' ) !== false, 'decisions SQL targets sn_login_guard dataset' );
ok( strpos( $sql, 'sum(_sample_interval)' ) !== false, 'decisions SQL uses sum(_sample_interval) for de-sampled totals' );
ok( strpos( $sql, "INTERVAL '7'" ) !== false, 'decisions SQL honors the day window' );
ok( strpos( $sql, 'blob2 AS decision' ) !== false, 'decisions SQL aliases blob2 as decision' );

$top = sn_login_defense_top_asn_sql();
ok( strpos( $top, "blob2 = 'block'" ) !== false, 'top-ASN SQL filters to blocked decisions' );
ok( strpos( $top, 'blob4 AS asorg' ) !== false, 'top-ASN SQL aliases blob4 as asorg' );
ok( strpos( $top, 'LIMIT' ) !== false, 'top-ASN SQL is bounded by LIMIT' );

// --- status URL derivation ---------------------------------------------------
ok(
	sn_login_defense_status_url() === 'https://juanlentino.com/_sn/login-guard/status',
	'status URL derives the origin and points at /_sn/login-guard/status'
);

// --- attribution -------------------------------------------------------------
$attr = sn_login_defense_attribution();
ok(
	stripos( $attr, 'FireHOL' ) !== false && stripos( $attr, 'Spamhaus' ) !== false,
	'attribution credits both FireHOL and Spamhaus (license requirement)'
);

// --- A1: new query builders --------------------------------------------------
$c = sn_login_defense_top_country_sql( 30, 10 );
ok( strpos( $c, 'blob3 AS country' ) !== false && strpos( $c, "blob2 = 'block'" ) !== false
	&& strpos( $c, 'sum(_sample_interval)' ) !== false && strpos( $c, 'LIMIT 10' ) !== false,
	'top-country SQL: blob3, blocked-only, de-sampled, limited' );

$t = sn_login_defense_trend_sql( 7 );
ok( strpos( $t, "sum(if(blob2 = 'block', _sample_interval, 0)) AS blocked" ) !== false
	&& strpos( $t, "sum(if(blob2 = 'pass', _sample_interval, 0)) AS passed" ) !== false
	&& strpos( $t, 'GROUP BY day' ) !== false && strpos( $t, 'ORDER BY day' ) !== false,
	'trend SQL: conditional block/pass sums, grouped + ordered by day' );

$nq = sn_login_defense_networks_sql( 30 );
ok( strpos( $nq, 'count(DISTINCT blob4)' ) !== false && strpos( $nq, "blob2 = 'block'" ) !== false
	&& strpos( $nq, 'count(*)' ) === false,
	'networks SQL: count(DISTINCT blob4), blocked-only, no count(*)' );

// --- A2: derivation ----------------------------------------------------------
$k = sn_login_defense_kpis_from_rows( array(
	array( 'decision' => 'block', 'hits' => 30 ),
	array( 'decision' => 'pass', 'hits' => 70 ),
	array( 'decision' => 'bypass', 'hits' => 0 ),
) );
ok( $k['checked'] === 100 && $k['blocked'] === 30 && $k['block_rate'] === 30, 'KPIs: checked=100, blocked=30, rate=30%' );
ok( sn_login_defense_kpis_from_rows( array() )['block_rate'] === 0, 'KPIs: empty -> 0% (no divide-by-zero)' );

// Worker v1.7.0: 'throttle' (per-IP POST rate limit) is a denial of a CHECKED
// request — it joins the denominator, gets its own KPI key, and dilutes the
// block rate (30 blocked of 125 checked = 24%).
$k = sn_login_defense_kpis_from_rows( array(
	array( 'decision' => 'block', 'hits' => 30 ),
	array( 'decision' => 'pass', 'hits' => 70 ),
	array( 'decision' => 'throttle', 'hits' => 25 ),
) );
ok( $k['checked'] === 125 && $k['throttled'] === 25 && $k['block_rate'] === 24,
	'KPIs: throttle joins checked (125), throttled=25, rate dilutes to 24%' );
ok( sn_login_defense_kpis_from_rows( array() )['throttled'] === 0, 'KPIs: throttled defaults to 0' );

// Worker v1.10.0: 'lockout' is the escalated throttle — a run of capped windows
// earned a cooldown. It is a denial of a CHECKED request, exactly like throttle,
// so it joins the denominator. The inflation pin below is the one that matters:
// a lockout missing from `checked` does not just hide a count, it raises the
// reported block rate, so the surface would read HEALTHIER the more the guard
// was being hammered.
$k = sn_login_defense_kpis_from_rows( array(
	array( 'decision' => 'block', 'hits' => 30 ),
	array( 'decision' => 'pass', 'hits' => 70 ),
	array( 'decision' => 'lockout', 'hits' => 50 ),
) );
ok( 150 === $k['checked'], 'KPIs: lockout joins checked (150), like throttle before it' );
ok( 50 === $k['locked_out'], 'KPIs: locked_out is its own key — distinct from throttled, because the operator questions differ' );
ok( 20 === $k['block_rate'], 'KPIs: and it DILUTES the block rate (30/150 = 20%) — omitting it would have inflated the rate to 30%' );
ok( 0 === sn_login_defense_kpis_from_rows( array() )['locked_out'], 'KPIs: locked_out defaults to 0' );

// Throttle and lockout are counted SEPARATELY, never merged.
$k = sn_login_defense_kpis_from_rows( array(
	array( 'decision' => 'throttle', 'hits' => 7 ),
	array( 'decision' => 'lockout', 'hits' => 3 ),
	array( 'decision' => 'pass', 'hits' => 90 ),
) );
ok( 7 === $k['throttled'] && 3 === $k['locked_out'] && 100 === $k['checked'],
	'KPIs: throttled and locked_out stay distinct and both land in checked' );

$series = sn_login_defense_trend_series( array(
	array( 'day' => '2026-06-20', 'blocked' => 5, 'passed' => 1 ),
	array( 'day' => '2026-06-21', 'blocked' => 9, 'passed' => 2 ),
) );
ok( $series[0]['day'] === '2026-06-20' && $series[1]['views'] === 9, 'trend series: ascending, views = blocked count' );

// --- A3: cached headline -----------------------------------------------------
$GLOBALS['__lg_q'] = array(
	array( array( 'decision' => 'block', 'hits' => 4 ), array( 'decision' => 'pass', 'hits' => 6 ) ),
	array( array( 'asorg' => 'BadNet', 'hits' => 4 ) ),
	// v8.5.0: the headline also fetches + caches the 7d trend (third query).
	array( array( 'day' => '2026-07-01', 'blocked' => 1, 'passed' => 3 ), array( 'day' => '2026-07-02', 'blocked' => 3, 'passed' => 3 ) ),
);
function sn_analytics_config() { return array( 'account_id' => 'x', 'token' => 'y' ); }
function sn_analytics_query( $sql ) { return array_shift( $GLOBALS['__lg_q'] ); }
$GLOBALS['__t'] = array();
function get_transient( $k ) { return $GLOBALS['__t'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['__t'][ $k ] = $v; return true; }
$h = sn_login_defense_headline();
ok( $h['blocked'] === 4 && $h['block_rate'] === 40 && $h['top_network'] === 'BadNet', 'headline: blocked/rate/top-network' );
ok( isset( $GLOBALS['__t']['sn_lg_headline'] ), 'headline: cached in transient' );
// v8.5.0: the trend rides the SAME transient (one shared cache, no widget-side query).
ok( isset( $h['trend'] ) && 2 === count( $h['trend'] ) && 3 === (int) $h['trend'][1]['views'], 'headline: carries the 7d blocked trend as a sparkline series (blocked -> views)' );

// --- D1: Security panel is status-only (no decisions KPI; links to analytics) -
function esc_html( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
function __( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) $n; }
function wp_http_validate_url( $u ) { return false; } // -> status null, no network call
ob_start();
sn_login_defense_render();
$panel = ob_get_clean();
ok( strpos( $panel, 'Login decisions' ) === false, 'panel no longer renders the decisions KPI list' );
ok( strpos( $panel, 'page=sn-analytics&sn_view=login-defense' ) !== false, 'panel links to the dashboard login-defense view' );
ok( strpos( $panel, 'FireHOL' ) !== false, 'panel keeps the FireHOL/Spamhaus attribution' );
// v8.0.2 cohesion: the status readout gets system card chrome. The wrapper sits
// OUTSIDE the .sn-status-box flex row (children echoed inside it render as a
// squeezed column — the v7.2.1 lesson).
ok( strpos( $panel, '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Login guard status</h2><div class="sn-status-box">' ) !== false, 'status readout wrapped in a system card, fieldset AROUND the flex-row status box' );
ok( strpos( $panel, 'postbox' ) === false && strpos( $panel, 'style=' ) === false, 'panel stays free of meta-box chrome and inline styles' );

// --- worker version surfaced in the status panel (parity with analytics) -----
ob_start();
sn_login_defense_render_status( array( 'version' => '1.0.1', 'denylistCount' => 4553, 'compiledAt' => '2026-06-22T16:07Z', 'deployed_at' => '2026-06-22T17:15Z' ) );
$st = ob_get_clean();
ok( strpos( $st, 'v1.0.1' ) !== false && strpos( $st, '17:15' ) !== false, 'status renders the worker version + deployed-at' );
ok( strpos( $st, 'Denylist: 4553' ) !== false, 'status keeps the denylist line' );
ob_start();
sn_login_defense_render_status( null );
ok( strpos( ob_get_clean(), 'unavailable' ) !== false, 'status null -> unavailable line' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
