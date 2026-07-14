<?php
/**
 * Tests for inc/edge-admin.php — the "Traffic & edge" analytics view renderer:
 * dormant empty-state, the KPI headline (incl. the beacon-reconciliation), and the
 * edge dim-tables. Stubbed read accessors + escaping seams; no DB.
 * Run: php tests/edge-admin.php
 * @since plugin v6.26.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function size_format( $bytes, $dec = 0 ) { return round( $bytes / 1048576, $dec ) . ' MB'; } // crude MB for the test

// Edge read seams.
$GLOBALS['__ec_config'] = array( 'token' => 't', 'zone' => 'z' );
function sn_edge_config() { return $GLOBALS['__ec_config']; }
function sn_edge_range_totals( $from, $to ) { return array( 'requests' => 2000, 'cached_requests' => 1600, 'bytes' => 10485760, 'threats' => 7, 'page_views' => 500, 'cache_hit_pct' => 80, 'error_pct' => 4, 'status_2xx' => 1800, 'status_3xx' => 100, 'status_4xx' => 70, 'status_5xx' => 30 ); }
function sn_edge_machine_split( $from, $to ) { return array( 'edge' => 500, 'human' => 176, 'machine' => 324, 'machine_pct' => 65 ); }
function sn_edge_daily_series( $from, $to ) { return array( array( 'day' => '2026-06-18', 'requests' => 2000 ) ); }
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) {
	$map = array(
		'colo'    => array( array( 'value' => 'IAD', 'requests' => 900, 'bytes' => 5000000 ) ),
		'country' => array( array( 'value' => 'US', 'requests' => 1200, 'bytes' => 6000000 ) ),
		'threat'  => array( array( 'value' => 'block', 'requests' => 50, 'bytes' => 0 ) ),
		'atk_door'    => array( array( 'value' => '/wp-login.php', 'requests' => 8400, 'bytes' => 0 ) ),
		'atk_country' => array( array( 'value' => 'CN', 'requests' => 5000, 'bytes' => 0 ) ),
		'atk_asn'     => array( array( 'value' => 'DIGITALOCEAN-ASN', 'requests' => 3000, 'bytes' => 0 ) ),
		'atk_status'  => array( array( 'value' => '404', 'requests' => 8000, 'bytes' => 0 ) ),
		'atk_method'  => array( array( 'value' => 'POST', 'requests' => 7000, 'bytes' => 0 ) ),
		'atk_path'    => array( array( 'value' => '/.env', 'requests' => 1200, 'bytes' => 0 ) ),
	);
	return $map[ $dim ] ?? array();
}
$GLOBALS['__trend_calls'] = array();
function snt_analytics_render_trend( $series, $g = 'day' ) { $GLOBALS['__trend_calls'][] = $series; echo '<div class="sn-trend"></div>'; }
$GLOBALS['__ec_retention_days'] = 0; // discovered adaptive retention (days); 0 = unknown.
function sn_edge_adaptive_retention_days() { return (int) $GLOBALS['__ec_retention_days']; }

require_once __DIR__ . '/../inc/edge-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return ob_get_clean(); }

echo "Edge admin — Traffic & edge view\n\n";

echo "Group: dormant empty-state\n";
$GLOBALS['__ec_config'] = null;
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( stripos( $html, 'Zone Analytics:Read' ) !== false, 'dormant: shows the configure note (add Zone Analytics:Read)' );
ok( empty( $GLOBALS['__trend_calls'] ), 'dormant: no data rendering' );
// v9.40.0 D4: the dormant notice adopts the unified snt_an_gate() idiom — a
// deliberate upgrade from the old titleless bare postbox to a titled gate.
ok( strpos( $html, 'sn-an-gate' ) !== false, 'dormant: unified gate marker present' );
ok( strpos( $html, '<span>Traffic &amp; edge</span>' ) !== false, 'dormant: gate carries a title (upgrade from the old titleless bare postbox)' );
$GLOBALS['__ec_config'] = array( 'token' => 't', 'zone' => 'z' );

echo "\nGroup: configured — KPI headline + reconciliation\n";
$GLOBALS['__trend_calls'] = array();
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( strpos( $html, 'sn-kpi-row' ) !== false, 'kpi: reuses the native KPI row markup' );
// v9.40.0 D4: the row now routes through the shared snt_an_kpi_row primitive —
// pin the exact row_class wrapper and that no card carries a delta/sub span
// (empty_slot=omit reproduces the old loop's label+value-only cards).
ok( strpos( $html, 'sn-kpi-row sn-kpi-row--edge' ) !== false, 'kpi: row_class rides the shared primitive wrapper exactly' );
// v9.40.0 D4: the headline postbox now routes through the shared panel primitive
// (plain — no sn-overview) and keeps its "inside inside-flush" body class.
ok( strpos( $html, 'class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Traffic &amp; edge</span></h2></div><div class="inside inside-flush">' ) !== false,
	'headline panel adopts the primitive and keeps "inside inside-flush"' );
ok( strpos( $html, 'sn-kpi-delta' ) === false, 'kpi: empty_slot=omit suppresses the third line entirely (no delta/sub span)' );
ok( strpos( $html, '2,000' ) !== false, 'kpi: total edge requests' );
ok( strpos( $html, '176' ) !== false, 'kpi: human pageviews (from the beacon)' );
ok( strpos( $html, '65%' ) !== false, 'kpi: machine-traffic % (the reconciliation headline)' );
ok( strpos( $html, '80%' ) !== false, 'kpi: cache-hit %' );
ok( strpos( $html, '7' ) !== false, 'kpi: threats' );
foreach ( array( 'requests', 'Human', 'Machine', 'Cache', 'Bandwidth', 'Threats' ) as $label_frag ) {
	ok( stripos( $html, $label_frag ) !== false, "kpi: card label contains '$label_frag'" );
}

echo "\nGroup: trend + breakdown tables\n";
ok( count( $GLOBALS['__trend_calls'] ) === 1 && $GLOBALS['__trend_calls'][0][0]['requests'] === 2000, 'trend: renders the daily request series' );
ok( strpos( $html, 'IAD' ) !== false, 'tables: per-colo (edge POP) breakdown' );
ok( strpos( $html, 'block' ) !== false, 'tables: threats breakdown' );
ok( stripos( $html, 'Requests' ) !== false && stripos( $html, 'Bandwidth' ) !== false, 'tables: edge dim columns are Requests + Bandwidth (not Views/Visits)' );
ok( stripos( $html, '4xx' ) !== false || stripos( $html, 'Errors' ) !== false, 'status: surfaces an error/status breakdown for monitoring' );
// v9.40.0 D4: snt_edge_render_dim's postbox routes through the shared primitive
// (plain — no sn-overview) and keeps its "inside sn-an-table-inside" body class.
ok( strpos( $html, 'class="postbox sn-an-postbox"><div class="postbox-header"><h2 class="hndle"><span>Edge locations</span></h2></div><div class="inside sn-an-table-inside">' ) !== false,
	'dim-table panel adopts the primitive and keeps "inside sn-an-table-inside"' );

echo "\nGroup: discovered-retention caption (surfaced from the settings-node probe)\n";
$GLOBALS['__ec_retention_days'] = 31;
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( strpos( $html, '31' ) !== false && stripos( $html, 'days' ) !== false, 'retention: surfaces the discovered window (31 days) when known' );
ok( stripos( $html, 'retain' ) !== false || stripos( $html, 'Cloudflare' ) !== false, 'retention: frames it as the node retention, not a beacon figure' );
// Unknown (0) → the caption is omitted entirely (graceful, no "0 days" noise).
$GLOBALS['__ec_retention_days'] = 0;
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( stripos( $html, 'retains this node' ) === false, 'retention: unknown (0) → no retention clause rendered' );

echo "\nGroup: attack-surface pressure section\n";
$GLOBALS['__ec_config'] = array( 'token' => 't', 'zone' => 'z' );
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( strpos( $html, 'Attack-surface pressure' ) !== false, 'attack: section title present' );
ok( strpos( $html, '/wp-login.php' ) !== false, 'attack: login door listed' );
ok( strpos( $html, 'DIGITALOCEAN-ASN' ) !== false, 'attack: top attacker network listed' );
ok( strpos( $html, '/.env' ) !== false, 'attack: top probed path listed' );
ok( strpos( $html, 'POST' ) !== false, 'attack: method mix listed' );
// Frame consistency: the section is a full-width sep divider inside the dim grid
// (like every other section), NOT a nested .postbox whose .hndle header rendered
// oversized vs the un-nested dim cards. v6.35.1.
ok( strpos( $html, '<strong>Attack-surface pressure</strong>' ) !== false,
	'attack: section title renders as a full-width sep divider' );
ok( strpos( $html, '<span>Attack-surface pressure</span>' ) === false,
	'attack: section title is no longer a nested postbox header (the oversized .hndle)' );
ok( strpos( $html, 'sn-an-sep--full' ) !== false,
	'attack: section intro uses the shared full-width divider class' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
