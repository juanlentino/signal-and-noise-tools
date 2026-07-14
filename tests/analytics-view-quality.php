<?php
/**
 * Standalone fixture tests for the v8.5.0 Quality view extraction
 * (inc/analytics-view-quality.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-quality.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } } // D5 §6: view now wraps the panel title

function sn_analytics_class_series( $f, $t, $g = 'day' ) { return array(); }
function sn_analytics_bot_breakdown( $f, $t, $l = 10 ) { return array(); }
function sn_analytics_distribution( $m, $f, $t, $c = 'human' ) { return array(); }
function snt_analytics_render_bot_trend( $rows ) { echo '<!--BOTTREND-->'; }
function snt_analytics_render_bot_breakdown( $bb ) { echo '<!--BOTBREAK-->'; }
function snt_analytics_render_distribution( $title, $rows, $empty = '' ) { echo '<!--DIST:' . $title . '-->'; }

require_once __DIR__ . '/../inc/analytics-view-quality.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-quality suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
snt_analytics_render_view_quality( '2026-07-01', '2026-07-07', 'human', 'day' );
$html = (string) ob_get_clean();
$bt = strpos( $html, '<!--BOTTREND-->' ); $bb = strpos( $html, '<!--BOTBREAK-->' ); $bc = strpos( $html, '<!--DIST:Bot confidence-->' );
ok( false !== $bt && false !== $bb && false !== $bc && $bt < $bb && $bb < $bc, 'trend -> breakdown -> confidence, in order' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
