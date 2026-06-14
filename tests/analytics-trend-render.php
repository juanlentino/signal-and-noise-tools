<?php
/**
 * Tests for snt_analytics_render_trend granularity-aware aria-label.
 * Run: php tests/analytics-trend-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_RANGES', array( 7, 30, 90, 365 ) );

function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }

// Stubs needed so the file loads without fatal errors.
function add_query_arg( $args, $base = '' ) { return $base . '?' . http_build_query( $args ); }
function remove_query_arg( $keys, $url = '' ) { return $url; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function esc_url( $s ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function wp_nonce_field( $action ) {}
function get_option( $k, $d = '' ) { return $d; }
function sn_mask_secret( $s ) { return $s; }
function sn_analytics_config() { return false; }
function current_user_can( $c ) { return false; }
function sn_analytics_last_error() { return null; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else         { $fail++; echo "  FAIL: $msg\n"; }
}

$series = array(
	array( 'day' => '2026-06-08', 'views' => 10 ),
	array( 'day' => '2026-06-01', 'views' => 5 ),
);

echo "\nGroup: trend render granularity\n";

ob_start(); snt_analytics_render_trend( $series, 'week' ); $h = ob_get_clean();
ok( strpos( $h, 'Weekly views trend' ) !== false, 'weekly granularity → weekly aria-label' );

ob_start(); snt_analytics_render_trend( $series ); $h2 = ob_get_clean();
ok( strpos( $h2, 'Daily views trend' ) !== false, 'default → daily aria-label' );

echo "\nGroup: trend characterization (golden — behaviour-preserving after the smooth-path helper extraction)\n";
// Golden captured from the v6.5.4 output for views [100,300,200] (max=300, top=8, base=78).
// The smooth-path helper extraction MUST keep these path strings byte-identical.
$golden_series = array(
	array( 'day' => '2026-06-09', 'views' => 100 ),
	array( 'day' => '2026-06-10', 'views' => 300 ),
	array( 'day' => '2026-06-11', 'views' => 200 ),
);
ob_start(); snt_analytics_render_trend( $golden_series, 'day' ); $g = ob_get_clean();
ok( strpos( $g, 'd="M 0,54.67 C 50,46.89 200,11.89 300,8 C 400,8 550,27.44 600,31.33"' ) !== false,
	'line path d unchanged by the helper extraction (golden)' );
ok( strpos( $g, 'd="M 0,78 L 0,54.67 C 50,46.89 200,11.89 300,8 C 400,8 550,27.44 600,31.33 L 600,78 Z"' ) !== false,
	'area path d unchanged (golden)' );
ok( strpos( $g, 'vector-effect="non-scaling-stroke"' ) !== false, 'non-scaling-stroke preserved' );
ok( strpos( $g, 'peak 300' ) !== false, 'peak meta preserved' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
