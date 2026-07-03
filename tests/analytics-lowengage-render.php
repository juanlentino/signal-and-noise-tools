<?php
/**
 * Tests for snt_analytics_render_lowengage() — "Pages losing readers" panel.
 * Run: php tests/analytics-lowengage-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
// snt_analytics_fmt_time is defined in analytics-admin-render.php itself — no stub needed.

require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "\nGroup: render_lowengage\n";

ob_start();
snt_analytics_render_lowengage( array( array( 'path' => '/bouncy', 'views' => 60, 'scroll_avg' => 8.0, 'time_avg' => 1500.0 ) ) );
$h = ob_get_clean();
ok( strpos( $h, 'Pages losing readers' ) !== false, 'panel heading present' );
ok( strpos( $h, '/bouncy' ) !== false, 'lists the path' );

ob_start();
snt_analytics_render_lowengage( array() );
$e = ob_get_clean();
ok( strpos( $e, 'sn-an-empty' ) !== false, 'empty state when no rows' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
