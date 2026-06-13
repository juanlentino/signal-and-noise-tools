<?php
/**
 * Tests for snt_analytics_render_dim_table() with optional $series sparklines.
 * Run: php tests/analytics-dimtable-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok: $m\n"; }
	else       { $fail++; echo "  FAIL: $m\n"; }
}

echo "\nGroup: dim_table sparklines\n";

$rows   = array( array( 'value' => 'Chrome', 'views' => 12, 'visits' => 10 ) );
$series = array( 'Chrome' => array( array( 'day' => '2026-06-10', 'views' => 5 ), array( 'day' => '2026-06-11', 'views' => 7 ) ) );

ob_start(); snt_analytics_render_dim_table( 'Browsers', $rows, 'none', $series ); $h = ob_get_clean();
ok( strpos( $h, 'sn-an-spark' ) !== false, 'sparkline markup injected when series provided' );

ob_start(); snt_analytics_render_dim_table( 'Browsers', $rows, 'none' ); $h2 = ob_get_clean();
ok( strpos( $h2, 'sn-an-spark' ) === false, 'no sparkline when series omitted (back-compat)' );
ok( strpos( $h2, 'Chrome' ) !== false, 'still renders the row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
