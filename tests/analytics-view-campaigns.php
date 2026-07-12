<?php
/**
 * Standalone fixture tests for the Campaigns dashboard view
 * (inc/analytics-view-campaigns.php): a dedicated Analytics tab surfacing UTM
 * attribution (Campaigns + Source/Medium, with sparklines). Unlike the earlier
 * Content-view placement it is ALWAYS visible — the panels render an empty state
 * rather than being hidden when there is no campaign data yet, so the feature is
 * discoverable before any UTM traffic arrives.
 *
 * Run: php tests/analytics-view-campaigns.php
 * @since plugin v9.29.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

// UTM accessors — recorders; the view's composition is under test.
$GLOBALS['__camp'] = null;
$GLOBALS['__src']  = null;
function sn_analytics_top_utm_campaigns( $f, $t, $c = 'human', $l = 25 ) { return $GLOBALS['__camp'] ?? array( array( 'value' => 'summer_sale', 'views' => 12, 'visits' => 9 ) ); }
function sn_analytics_top_utm_sources( $f, $t, $c = 'human', $l = 25 ) { return $GLOBALS['__src'] ?? array( array( 'value' => 'google / cpc', 'source' => 'google', 'medium' => 'cpc', 'views' => 9, 'visits' => 7 ) ); }
$GLOBALS['__series_modes'] = array();
function sn_analytics_utm_series( $mode, $vals, $f, $t, $c = 'human', $g = 'day' ) { $GLOBALS['__series_modes'][] = (string) $mode; return array(); }

// Renderer recorder — the dim-table panel has its own suite.
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill = '', $visible = 5 ) { echo '<!--DIM:' . esc_html( $title ) . '-->'; }

require_once __DIR__ . '/../inc/analytics-panels.php'; // provides snt_an_flush_empty_fold()
require_once __DIR__ . '/../inc/analytics-view-campaigns.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-campaigns suite - plugin v9.29.0\n";

echo "\nTest: composition (with data)\n";
$GLOBALS['__series_modes'] = array();
ob_start();
snt_analytics_render_view_campaigns( '2026-07-01', '2026-07-11', 'human', 'day' );
$html = (string) ob_get_clean();
$camp = strpos( $html, '<!--DIM:Campaigns-->' );
$src  = strpos( $html, '<!--DIM:Source / Medium-->' );
ok( false !== $camp, 'renders the Campaigns panel' );
ok( false !== $src && $src > $camp, 'renders the Source / Medium panel after Campaigns' );
ok( in_array( 'campaign', $GLOBALS['__series_modes'], true ) && in_array( 'source_medium', $GLOBALS['__series_modes'], true ), 'both panels request a trend series (sparklines)' );
ok( false !== stripos( $html, 'campaign attribution' ), 'renders an explanatory intro line' );
ok( false !== stripos( $html, 'utm_' ), 'intro names the utm_* params so the empty view is self-explaining' );

echo "\nTest: ALWAYS visible — panels render even with no campaign data\n";
$GLOBALS['__camp'] = array();
$GLOBALS['__src']  = array();
ob_start();
snt_analytics_render_view_campaigns( '2026-07-01', '2026-07-11', 'human', 'day' );
$empty = (string) ob_get_clean();
ok( false !== strpos( $empty, '<!--DIM:Campaigns-->' ), 'Campaigns panel still renders with no data (empty-state, not hidden)' );
ok( false !== strpos( $empty, '<!--DIM:Source / Medium-->' ), 'Source/Medium panel still renders with no data' );
$GLOBALS['__camp'] = null;
$GLOBALS['__src']  = null;

echo "\nTest: partial-install guard\n";
$src_txt = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-campaigns.php' );
ok( false !== strpos( $src_txt, "function_exists( 'sn_analytics_top_utm_campaigns' )" ), 'UTM accessors stay behind a function_exists guard' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
