<?php
/**
 * Standalone fixture tests for the v8.5.0 Technology view extraction
 * (inc/analytics-view-technology.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-technology.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }

function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) { return array( array( 'value' => 'X', 'views' => 1, 'visits' => 1 ) ); }
function sn_analytics_dimension_series( $d, $v, $f, $t, $c = 'human', $g = 'day' ) { return array(); }
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill = '' ) { echo '<!--DIM:' . $title . '-->'; }

require_once __DIR__ . '/../inc/analytics-view-technology.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-technology suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
snt_analytics_render_view_technology( '2026-07-01', '2026-07-07', 'human', 'day' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, 'sn-an-grid' ), 'grid wrapper kept' );
foreach ( array( 'Browsers', 'Operating systems', 'Devices', 'Protocols', 'TLS versions' ) as $t ) {
	ok( false !== strpos( $html, '<!--DIM:' . $t . '-->' ), $t . ' table renders' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
