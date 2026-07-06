<?php
/**
 * Standalone fixture tests for the v8.5.0 Engagement view extraction
 * (inc/analytics-view-engagement.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-engagement.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }

function sn_analytics_hour_dow_grid( $f, $t, $c = 'human' ) { return array( 'grid' => array(), 'max' => 0 ); }
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) { return array(); }
function sn_analytics_percentiles( $m, $f, $t, $c = 'human' ) { return array(); }
function sn_analytics_engagement_anomalies( $f, $t, $c = 'human' ) { return array( 'divergence' => array(), 'outliers' => array() ); }
function snt_analytics_render_heatmap( $h ) { echo '<!--HEATMAP-->'; }
function snt_analytics_render_distribution( $title, $rows, $empty = '' ) { echo '<!--DIST:' . $title . '-->'; }
function snt_analytics_render_percentiles( $title, $rows, $fmt = 'pct', $empty = '', $note = '' ) { echo '<!--PCTL:' . $title . '-->'; }
function snt_analytics_render_anomalies( $anom ) { echo '<!--ANOM-->'; }

require_once __DIR__ . '/../inc/analytics-view-engagement.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-engagement suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
snt_analytics_render_view_engagement( '2026-07-01', '2026-07-07', 'human' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, '<!--HEATMAP-->' ), 'heatmap renders first' );
foreach ( array( 'Scroll depth', 'Time on page', 'Connection RTT', 'LCP (field)', 'INP (field)', 'CLS (field)' ) as $t ) {
	ok( false !== strpos( $html, '<!--DIST:' . $t . '-->' ), $t . ' distribution renders' );
}
ok( false !== strpos( $html, '<!--PCTL:Scroll depth' ) && false !== strpos( $html, '<!--PCTL:Time on page' ), 'both percentile panels render' );
ok( false !== strpos( $html, 'Field Core Web Vitals' ), 'CWV separator kept' );
ok( false !== strpos( $html, '<!--ANOM-->' ), 'anomalies panel renders' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
