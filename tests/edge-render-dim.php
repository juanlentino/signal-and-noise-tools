<?php
/**
 * Standalone test: snt_edge_render_dim() omits empty panels (v8.5.2).
 * Run: php tests/edge-render-dim.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return (string) (int) $n; }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/edge-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_edge_render_dim( 'Edge locations', array(), 'No edge-location data yet.', true ); $e = ob_get_clean();
ok( '' === trim( $e ), 'empty edge dim renders no panel' );
ok( in_array( 'Edge locations', (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() ), true ), 'empty edge dim registers its title' );

ob_start(); snt_edge_render_dim( 'Status codes', array( array( 'value' => '2xx', 'requests' => 5, 'bytes' => 0 ) ), 'x', false ); $h = ob_get_clean();
ok( strpos( $h, '2xx' ) !== false && strpos( $h, 'postbox' ) !== false, 'renders a panel when rows present' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
