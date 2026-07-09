<?php
/**
 * Smoke test: the anomalies panel renderer runs and folds empty.
 * Run: php tests/analytics-render-anomalies.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return (string) $n; } }
// NOTE: inc/analytics-admin-render.php itself `require_once`s inc/analytics-panels.php
// (unconditionally, no function_exists guard around its declarations), so this test
// cannot pre-declare snt_an_panel_open()/snt_an_panel_close()/snt_an_note_empty() stubs —
// that would collide with the real declarations and fatal on redeclaration. Instead,
// following the existing convention in tests/analytics-distribution-render.php, this
// test loads the REAL inc/analytics-panels.php and asserts against its real behavior:
// snt_an_note_empty() collects into $GLOBALS['sn_an_empty_panels'], and
// snt_an_panel_open() echoes real markup containing the title.
require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-annotations.php';

require_once __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Analytics render — anomalies panel\n\n";
unset( $GLOBALS['sn_an_empty_panels'] );
ob_start();
snt_analytics_render_anomalies( array( 'divergence' => array(), 'outliers' => array() ) );
$empty_html = ob_get_clean();
ok( '' === trim( $empty_html ), 'render: no anomalies → emits no panel markup' );
ok( in_array( 'Engagement anomalies', (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() ), true ), 'render: no anomalies → folds to empty note (no full panel)' );

unset( $GLOBALS['sn_an_empty_panels'] );
ob_start();
snt_analytics_render_anomalies( array(
	'divergence' => array( array( 'path' => '/skim', 'type' => 'skim', 'scroll_avg' => 82.0, 'time_avg_ms' => 2500, 'views' => 400 ) ),
	'outliers'   => array(),
) );
$html = ob_get_clean();
ok( false !== strpos( $html, 'Engagement anomalies' ), 'render: with data → opens the panel' );
ok( false !== strpos( $html, '/skim' ), 'render: emits the anomalous path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
