<?php
/**
 * a11y render tests — heatmap accessible companion table + bot-networks thead.
 * Run: php tests/analytics-a11y-render.php
 * @since plugin v6.11.2 (refinement audit, Cluster C)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function __( $s, $d = null ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: heatmap accessible companion table\n";
$heatmap = array( 'max' => 10, 'grid' => array( 1 => array( 8 => 7, 9 => 3 ), 3 => array( 14 => 10 ) ) );
ob_start(); snt_analytics_render_heatmap( $heatmap ); $hm = ob_get_clean();
ok( strpos( $hm, 'class="screen-reader-text"' ) !== false, 'companion: visually-hidden data table present' );
ok( strpos( $hm, '<th scope="row">Mon</th>' ) !== false, 'companion: day row headers (scope=row)' );
ok( strpos( $hm, '<th scope="col">08:00</th>' ) !== false, 'companion: hour column headers (scope=col)' );
ok( strpos( $hm, 'sn-an-heatmap" aria-hidden="true"' ) !== false, 'visual grid is aria-hidden (companion conveys the data)' );
ok( strpos( $hm, 'role="img"' ) === false, 'visual grid no longer double-announced as role=img' );

echo "\nGroup: bot-networks table headers\n";
$bb = array( 'totals' => array( 'human' => 100, 'suspect' => 5, 'bot' => 20, 'total' => 125 ), 'top_bot_networks' => array( array( 'value' => 'AS13335', 'views' => 12 ) ) );
ob_start(); snt_analytics_render_bot_breakdown( $bb ); $q = ob_get_clean();
ok( strpos( $q, '<thead><tr><th scope="col">Network</th><th scope="col" class="num">Views</th></tr></thead>' ) !== false, 'bot networks: thead with scope=col before tbody' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
