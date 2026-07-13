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
// The pulse "Today so far" cell reads the series' LAST bucket. Its day is
// controllable: default = today (a rolled current-day bucket) so the positive case
// renders; flip __series_last_today off to model today's bucket not yet rolled
// (last bucket = a stale prior day), which must NOT be mislabelled "Today so far".
$GLOBALS['__series_last_today'] = true;
function sn_analytics_daily_series( $f, $t, $c = 'human', $g = 'day' ) {
	$day = ( $GLOBALS['__series_last_today'] ?? true ) ? gmdate( 'Y-m-d' ) : $f;
	return array( array( 'day' => $day, 'views' => 10 ) );
}
function sn_analytics_period_deltas( $f, $t, $c = 'human' ) { return array( 'views' => array( 'pct' => 40, 'dir' => 'up' ) ); } // 40% up + engaged down trips the overview read
function sn_analytics_engaged_rate( $f, $t, $c = 'human' ) { return 62; }
function sn_analytics_engaged_rate_delta( $f, $t, $c = 'human' ) { return array( 'current' => 62, 'previous' => 65, 'pct' => -3, 'dir' => 'down' ); }
// Pulse-strip accessors (durable bucket/rollup reads) — flip the globals to
// model a dataless install.
$GLOBALS['__dist_on'] = true;
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) {
	if ( ! $GLOBALS['__dist_on'] ) { return array(); }
	return array( array( 'label' => '0-25%', 'views' => 4 ), array( 'label' => '75-100%', 'views' => 40 ) );
}
$GLOBALS['__cs_on'] = true;
function sn_analytics_class_series( $f, $t, $g = 'day' ) {
	if ( ! $GLOBALS['__cs_on'] ) { return array(); }
	return array( array( 'day' => '2026-07-01', 'bot_pct' => 20, 'total' => 80 ), array( 'day' => '2026-07-02', 'bot_pct' => 30, 'total' => 90 ) );
}
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
// Shared bézier helper (real impl lives in analytics-admin-render.php; the
// pulse spark consumes it — a recorder string keeps this fixture focused).
if ( ! function_exists( 'snt_analytics_smooth_path' ) ) { function snt_analytics_smooth_path( $px, $py, $top, $base ) { return 'M 0,0 C 1,1 2,2 3,3'; } }

// Sub-renderer recorders (each has its own suite; this fixture pins ORDER + composition).
function snt_analytics_render_controls( $r, $c, $f = '', $t = '', $cmp = 'off', $ct = array() ) { $GLOBALS['__hr_controls_ct'] = $ct; echo '<!--CONTROLS-->'; }
function snt_analytics_render_cards( $n, $t, $d = array(), $e = null ) { echo '<!--CARDS-->'; }
function snt_analytics_render_trend( $s, $g = 'day' ) { echo '<!--TREND-->'; }
function snt_analytics_render_movers_tile( $f, $t, $c ) { echo '<!--MOVERS-->'; }

// Uptime surface stubs — flip $GLOBALS['__uptime_on'] to model un/configured.
$GLOBALS['__uptime_on'] = true;
function sn_uptime_status_rail_strip() { return $GLOBALS['__uptime_on'] ? '<!--UPTIME-STRIP-->' : ''; }
function sn_uptime_status_detail_panel() { return $GLOBALS['__uptime_on'] ? '<!--UPTIME-DETAIL-->' : ''; }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';
require_once __DIR__ . '/../inc/analytics-header-region.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-header-region suite - plugin v8.5.0\n";

echo "\nTest: composition + order\n";
$GLOBALS['__fired'] = array();
ob_start();
$totals = snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html   = (string) ob_get_clean();
$order  = array( '<!--CONTROLS-->', 'sn-an-header-grid', 'sn-an-header-main', '<!--CARDS-->', '<!--TREND-->', 'sn-an-header-rail', '<!--UPTIME-STRIP-->', '<!--MOVERS-->', '<!--UPTIME-DETAIL-->' );
$last   = -1;
$in_order = true;
foreach ( $order as $marker ) {
	$pos = strpos( $html, $marker );
	if ( false === $pos || $pos < $last ) { $in_order = false; break; }
	$last = $pos;
}
ok( $in_order, 'controls -> grid(main: cards+trend) -> rail(uptime strip, movers) -> detail panel, in order' );
// Armor: the fixture's sn_analytics_class_totals() returns a flat class => views
// map (line 27 above), NOT the nested {views,visits} shape the real accessor
// returns — this suite stubs render_controls entirely, so it only needs to prove
// the region hands class totals THROUGH to the toolbar seam, not shape-match
// production. Its bot figure is 90.
ok( 90 === (int) ( $GLOBALS['__hr_controls_ct']['bot'] ?? -1 ), 'armor: header region hands class totals INTO the toolbar (the meta seam is plumbed, not decorative)' );
ok( false !== strpos( $html, 'sn-overview' ), 'Overview panel keeps its sn-overview class (fused KPI+trend, v6.5.2 contract)' );
ok( false !== strpos( $html, '>Overview<' ), 'Overview panel titled through the primitive' );
ok( 1 === count( $GLOBALS['__fired'] ) && 'snt_analytics_after_overview' === $GLOBALS['__fired'][0][0], 'the after-Overview seam STILL FIRES exactly once (v8.5.0 removes nothing)' );
ok( array( 'content' ) === $GLOBALS['__fired'][0][1], 'seam receives the view' );
$detail_pos = strpos( $html, '<!--UPTIME-DETAIL-->' );
$grid_end   = strpos( $html, 'sn-an-header-rail' );
ok( false !== $detail_pos && $detail_pos > $grid_end, 'detail panel renders AFTER the header grid (full-width row)' );
ok( is_array( $totals ) && 1284 === ( $totals['views'] ?? 0 ), 'region returns the totals (the dashboard tail empty-hint reads them)' );
// Integration: 40%-up views + down engagement trips the overview read, so the
// Overview panel emits the callout. Proves the render passes $deltas + $engaged
// to the resolver in order (an arg swap would not produce this exact sentence).
ok( false !== strpos( $html, 'sn-an-note' ), 'render integration: diverging overview emits the annotation callout' );
ok( false !== strpos( $html, 'Views up 40%, but engaged rate slipped: more traffic, shallower visits.' ), 'render integration: callout carries the overview read (deltas + engaged passed correctly)' );

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

echo "\nTest: v9.37.0 (D1) Pulse strip folds into the Overview footer — Content view only\n";
ob_start();
snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, 'sn-an-pulse' ), 'pulse strip renders' );
$pulse_pos = strpos( $html, '<div class="sn-an-pulse' );
$rail_pos  = strpos( $html, 'sn-an-header-rail' );
$trend_pos = strpos( $html, '<!--TREND-->' );
ok( false !== $pulse_pos && false !== $trend_pos && $pulse_pos > $trend_pos && $pulse_pos < $rail_pos, 'pulse: renders INSIDE the Overview panel, after the trend, before the rail (D1 footer)' );
ok( false !== strpos( $html, 'sn-an-pulse--footer' ), 'pulse: carries the footer modifier' );
ob_start();
snt_analytics_render_header_region( 'visits', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html_v = (string) ob_get_clean();
ok( false === strpos( $html_v, 'sn-an-pulse' ), 'pulse: footer is Content-view-only (other views keep a leaner Overview)' );
ok( false !== strpos( $html, '>Scroll<' ) && false !== strpos( $html, '>Read time<' ), 'scroll + read-time band cells present' );
ok( false !== strpos( $html, '75-100% · 91%' ), 'dominant band named with its share (40 of 44)' );
ok( false !== strpos( $html, 'Bot share' ) && false !== strpos( $html, 'sn-an-pulse-spark' ), 'bot-share cell with microspark' );
ok( false !== strpos( $html, '>25%<' ), 'bot share window average (20+30 / 2)' );
ok( false !== strpos( $html, 'Today so far' ), 'today cell present when the last bucket IS today' );
// Regression: if today's bucket hasn't been rolled yet, the series' last bucket is a
// PRIOR day. It must NOT be mislabelled "Today so far" (that showed a stale full-day
// count as today, then dropped when today's partial bucket landed — the reported bug
// family). The cell is suppressed unless the last bucket's day == today (UTC).
$GLOBALS['__series_last_today'] = false;
ob_start();
snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' );
$html_stale = (string) ob_get_clean();
ok( false === strpos( $html_stale, 'Today so far' ), 'today cell suppressed when the last bucket is a stale prior day (not today)' );
$GLOBALS['__series_last_today'] = true;
// Week granularity: no "today" cell (a week bucket is not a day).
ob_start();
snt_analytics_render_header_region( 'content', '90', 'human', '2026-04-01', '2026-07-07', 'week' );
$html = (string) ob_get_clean();
ok( false === strpos( $html, 'Today so far' ), 'today cell absent on non-day granularity' );
// Dataless install: strip renders nothing (no dead chrome).
$GLOBALS['__dist_on'] = false;
$GLOBALS['__cs_on'] = false;
ob_start();
snt_analytics_render_header_region( 'content', '90', 'human', '2026-04-01', '2026-07-07', 'week' );
$html = (string) ob_get_clean();
ok( false === strpos( $html, 'sn-an-pulse' ), 'dataless install: no pulse chrome' );
$GLOBALS['__dist_on'] = true;
$GLOBALS['__cs_on'] = true;

echo "\nTest: v9.35.0 — the Overview panel names its tier (I6)\n";
ob_start(); snt_analytics_render_header_region( 'content', '7', 'human', '2026-07-01', '2026-07-07', 'day' ); $h_badge = (string) ob_get_clean();
ok( false !== strpos( $h_badge, 'sn-an-tier--descriptive' ) && false !== strpos( $h_badge, '>Descriptive<' ), 'overview: header carries the shared Descriptive badge' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
