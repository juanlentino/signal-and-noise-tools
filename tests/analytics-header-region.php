<?php
/**
 * Standalone fixture tests for the v8.5.0 Analytics header region
 * (inc/analytics-header-region.php): controls + class strip, then the 2/3
 * Overview + 1/3 rail grid (uptime strip, movers), then the collapsed uptime
 * detail panel, then the snt_analytics_after_overview seam STILL FIRING.
 *
 * Run: php tests/analytics-header-region.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }

// Seam recorder.
$GLOBALS['__fired'] = array();
function do_action( $hook, ...$args ) { $GLOBALS['__fired'][] = array( $hook, $args ); }

// Data accessors (values are inert — composition is under test).
function sn_analytics_range_totals( $f, $t, $c = 'human' ) { return array( 'views' => 1284, 'visits' => 316 ); }
function sn_analytics_class_totals( $f, $t ) { return array( 'human' => 300, 'suspect' => 10, 'bot' => 90 ); }
function sn_analytics_realtime( $c = 'human' ) { return 3; }
function sn_analytics_daily_series( $f, $t, $c = 'human', $g = 'day' ) { return array( array( 'day' => $f, 'views' => 10 ) ); }
function sn_analytics_period_deltas( $f, $t, $c = 'human' ) { return array( 'views' => array( 'pct' => 12, 'dir' => 'up' ) ); }
function sn_analytics_engaged_rate( $f, $t, $c = 'human' ) { return 62; }
function sn_analytics_engaged_rate_delta( $f, $t, $c = 'human' ) { return array( 'current' => 62, 'previous' => 65, 'pct' => -3, 'dir' => 'down' ); }

// Sub-renderer recorders (each has its own suite; this fixture pins ORDER + composition).
function snt_analytics_render_controls( $r, $c, $f = '', $t = '' ) { echo '<!--CONTROLS-->'; }
function snt_analytics_render_separation( $ct, $c ) { echo '<!--SEPARATION-->'; }
function snt_analytics_render_cards( $n, $t, $d = array(), $e = null ) { echo '<!--CARDS-->'; }
function snt_analytics_render_trend( $s, $g = 'day' ) { echo '<!--TREND-->'; }
function snt_analytics_render_movers_tile( $f, $t, $c ) { echo '<!--MOVERS-->'; }

// Uptime surface stubs — flip $GLOBALS['__uptime_on'] to model un/configured.
$GLOBALS['__uptime_on'] = true;
function sn_uptime_status_rail_strip() { return $GLOBALS['__uptime_on'] ? '<!--UPTIME-STRIP-->' : ''; }
function sn_uptime_status_detail_panel() { return $GLOBALS['__uptime_on'] ? '<!--UPTIME-DETAIL-->' : ''; }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-header-region.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-header-region suite - plugin v8.5.0\n";

echo "\nTest: composition + order\n";
$GLOBALS['__fired'] = array();
ob_start();
$totals = snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html   = (string) ob_get_clean();
$order  = array( '<!--CONTROLS-->', '<!--SEPARATION-->', 'sn-an-header-grid', 'sn-an-header-main', '<!--CARDS-->', '<!--TREND-->', 'sn-an-header-rail', '<!--UPTIME-STRIP-->', '<!--MOVERS-->', '<!--UPTIME-DETAIL-->' );
$last   = -1;
$in_order = true;
foreach ( $order as $marker ) {
	$pos = strpos( $html, $marker );
	if ( false === $pos || $pos < $last ) { $in_order = false; break; }
	$last = $pos;
}
ok( $in_order, 'controls -> separation -> grid(main: cards+trend) -> rail(uptime strip, movers) -> detail panel, in order' );
ok( false !== strpos( $html, 'sn-overview' ), 'Overview panel keeps its sn-overview class (fused KPI+trend, v6.5.2 contract)' );
ok( false !== strpos( $html, '>Overview<' ), 'Overview panel titled through the primitive' );
ok( 1 === count( $GLOBALS['__fired'] ) && 'snt_analytics_after_overview' === $GLOBALS['__fired'][0][0], 'the after-Overview seam STILL FIRES exactly once (v8.5.0 removes nothing)' );
ok( array( 'content' ) === $GLOBALS['__fired'][0][1], 'seam receives the view' );
$detail_pos = strpos( $html, '<!--UPTIME-DETAIL-->' );
$grid_end   = strpos( $html, 'sn-an-header-rail' );
ok( false !== $detail_pos && $detail_pos > $grid_end, 'detail panel renders AFTER the header grid (full-width row)' );
ok( is_array( $totals ) && 1284 === ( $totals['views'] ?? 0 ), 'region returns the totals (the dashboard tail empty-hint reads them)' );

echo "\nTest: unconfigured uptime degrades to movers-only rail\n";
$GLOBALS['__uptime_on'] = false;
ob_start();
snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html = (string) ob_get_clean();
ok( false === strpos( $html, '<!--UPTIME-STRIP-->' ) && false === strpos( $html, '<!--UPTIME-DETAIL-->' ), 'no uptime markup without a token' );
ok( false !== strpos( $html, '<!--MOVERS-->' ) && false !== strpos( $html, 'sn-an-header-rail' ), 'rail still renders with the movers tile' );
$GLOBALS['__uptime_on'] = true;

echo "\nTest: all-range window skips deltas\n";
// 'all' has no prior period: deltas empty, engaged is current-only. The
// region must not fatal and must still pass engaged to the cards.
ob_start();
$totals = snt_analytics_render_header_region( 'content', 'all', 'human', '2020-01-01', '2026-07-07', 'week' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, '<!--CARDS-->' ), 'all-range renders cards without deltas' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
