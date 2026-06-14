<?php
/**
 * Tests for the drill-down panel + the $drill_dim links on dim tables.
 * Run: php tests/analytics-drilldown-render.php
 * @since plugin v6.9.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
function add_query_arg( $a ) { $q = array(); foreach ( $a as $k => $v ) { $q[] = $k . '=' . $v; } return '?' . implode( '&', $q ); }
function remove_query_arg( $k, $u = '' ) { return '?cleared'; }
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "\nGroup: drill panel\n";
$rows = array( array( 'path' => '/a', 'views' => 20, 'visits' => 12 ), array( 'path' => '/b', 'views' => 5, 'visits' => 3 ) );
ob_start(); snt_analytics_render_drilldown_panel( 'country', 'US', $rows ); $h = ob_get_clean();
ok( strpos( $h, 'Top pages · Country = ' ) !== false && strpos( $h, 'US' ) !== false, 'panel: title with dim label + value' );
ok( strpos( $h, '/a' ) !== false && strpos( $h, '20' ) !== false, 'panel: page rows' );
ok( strpos( $h, 'Clear' ) !== false, 'panel: clear link' );
ob_start(); snt_analytics_render_drilldown_panel( 'referrer', 'x', null ); $e = ob_get_clean();
ok( strpos( $e, 'No pages for this segment' ) !== false, 'panel: null → empty-state' );
ob_start(); snt_analytics_render_drilldown_panel( 'country', 'US', $rows, '(reflects the last ~90 days — Analytics Engine raw retention)' ); $n = ob_get_clean();
ok( strpos( $n, 'last ~90 days' ) !== false, 'panel: retention note shown when provided' );

echo "\nGroup: dim-table drill links (\$drill_dim)\n";
$drows = array( array( 'value' => 'United States', 'views' => 10, 'visits' => 6 ) );
ob_start(); snt_analytics_render_dim_table( 'Countries', $drows, 'none', array(), 'country' ); $dl = ob_get_clean();
ok( strpos( $dl, 'sn_drill=country:United States' ) !== false, 'dim-table: value links to ?sn_drill=<dim>:<value> when drill_dim set' );
ob_start(); snt_analytics_render_dim_table( 'Countries', $drows, 'none' ); $nd = ob_get_clean();
ok( strpos( $nd, 'sn_drill=' ) === false, 'dim-table: no drill link when drill_dim omitted (back-compat)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
