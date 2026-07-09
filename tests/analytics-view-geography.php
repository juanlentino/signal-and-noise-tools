<?php
/**
 * Standalone fixture tests for the v8.5.0 Geography view extraction
 * (inc/analytics-view-geography.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-geography.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } } // v9.5.0: the read resolver calls it on tripping data
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } } // snt_an_annotation's "Read" label

function sn_analytics_top_dimension( $d, $f, $t, $c = 'human', $l = 25 ) { return $GLOBALS['__geo'] ?? array( array( 'value' => 'AR', 'views' => 1, 'visits' => 1 ) ); }
function snt_analytics_render_dim_table( $title, $rows, $empty, $series = array(), $drill = '' ) { echo '<!--DIM:' . $title . '-->'; }
function snt_analytics_render_choropleth( $title, $rows, $empty ) { echo '<!--MAP-->'; }

require_once __DIR__ . '/../inc/analytics-annotations.php'; // v9.5.0: the geography read resolver
require_once __DIR__ . '/../inc/analytics-view-geography.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-geography suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
snt_analytics_render_view_geography( '2026-07-01', '2026-07-07', 'human' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, 'sn-geo-split' ) && false !== strpos( $html, 'sn-geo-tiles' ), 'split + tiles wrappers kept' );
ok( false !== strpos( $html, '<!--MAP-->' ), 'choropleth renders' );
foreach ( array( 'Countries', 'Cities', 'Regions', 'Networks', 'Edge locations', 'Time zones' ) as $t ) {
	ok( false !== strpos( $html, '<!--DIM:' . $t . '-->' ), $t . ' table renders' );
}
// v9.5.0: the quiet default fixture (one country) does not trip the read.
ok( false === strpos( $html, 'sn-an-note' ), 'quiet map emits no annotation read' );

echo "\nTest: v9.5.0 geography read fires on market concentration\n";
$GLOBALS['__geo'] = array(
	array( 'value' => 'AR', 'views' => 40, 'visits' => 40 ),
	array( 'value' => 'US', 'views' => 31, 'visits' => 31 ),
	array( 'value' => 'BR', 'views' => 15, 'visits' => 15 ),
	array( 'value' => 'GB', 'views' => 14, 'visits' => 14 ),
);
ob_start();
snt_analytics_render_view_geography( '2026-07-01', '2026-07-07', 'human' );
$hot = (string) ob_get_clean();
ok( false !== strpos( $hot, 'class="sn-an-note"' ), 'the geography read emits on tripping data' );
ok( false !== strpos( $hot, 'Two markets' ), 'geography read text is rendered' );
$note_pos  = strpos( $hot, 'class="sn-an-note"' );
$split_pos = strpos( $hot, 'sn-geo-split' );
ok( false !== $note_pos && $note_pos < $split_pos, 'the read sits above the map/countries split' );
$GLOBALS['__geo'] = null;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
