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
	);
	return $map[ $dim ] ?? array();
}
$GLOBALS['__trend_calls'] = array();
function snt_analytics_render_trend( $series, $g = 'day' ) { $GLOBALS['__trend_calls'][] = $series; echo '<div class="sn-trend"></div>'; }

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
$GLOBALS['__ec_config'] = array( 'token' => 't', 'zone' => 'z' );

echo "\nGroup: configured — KPI headline + reconciliation\n";
$GLOBALS['__trend_calls'] = array();
$html = cap( function () { snt_edge_render_view( '2026-06-01', '2026-06-19' ); } );
ok( strpos( $html, 'sn-kpi-row' ) !== false, 'kpi: reuses the native KPI row markup' );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
